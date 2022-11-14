<?php

namespace Laravel\ResetTransaction\Facades;

use Carbon\Carbon;
use GuzzleHttp\Client;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class ResetTransaction
{
    protected $transactIdArr = [];
    protected $transactRollback = [];

    public function beginTransaction()
    {
        $transactId = session_create_id();
        array_push($this->transactIdArr, $transactId);
        if (count($this->transactIdArr) == 1) {
            $data = [
                'transact_id' => $transactId,
                'transact_rollback' => '[]',
                'xids_info' => '[]',
            ];
            DB::connection('rt_center')->table('reset_transact')->insert($data);
        }

        $this->stmtBegin();

        return $this->getTransactId();
    }

    public function commit()
    {
        if (count($this->transactIdArr) == 1) {
            $this->stmtRollback();
        } else {
            $this->stmtCommit();
        }

        if (count($this->transactIdArr) > 1) {
            array_pop($this->transactIdArr);

            return true;
        }

        $this->logRT(RT::STATUS_COMMIT);

        $commitUrl = config('rt_database.center.commit_url');

        $client = new Client();
        $response = $client->post($commitUrl, [
            'json' => [
                'transact_id' => $this->getTransactId(),
                'transact_rollback' => $this->transactRollback,
            ]
        ]);

        $this->removeRT();

        return $response;
    }

    public function rollBack()
    {
        $this->stmtRollback();

        if (count($this->transactIdArr) > 1) {
            $transactId = $this->getTransactId();
            foreach ($this->transactRollback as $i => $txId) {
                if (strpos($txId, $transactId) === 0) {
                    unset($this->transactRollback[$i]);
                }
            }
            array_push($this->transactRollback, $transactId);
            array_pop($this->transactIdArr);
            return true;
        }

        $this->logRT(RT::STATUS_ROLLBACK);

        $rollbackUrl = config('rt_database.center.rollback_url');

        $client = new Client();
        $response = $client->post($rollbackUrl, [
            'json' => [
                'transact_id' => $this->getTransactId(),
                'transact_rollback' => $this->transactRollback,
            ]
        ]);
        $this->removeRT();

        return $response;
    }

    public function middlewareBeginTransaction($transactId)
    {
        $transactIdArr = explode('-', $transactId);
        $connection = DB::connection()->getConfig('connection_name');
        $sqlArr = DB::connection('rt_center')
            ->table('reset_transact_sql')
            ->where('transact_id', $transactIdArr[0])
            ->where('connection', $connection)
            ->whereIn('transact_status', [RT::STATUS_START, RT::STATUS_COMMIT])
            ->get();

        $this->stmtBegin();
        if ($sqlArr) {
//            DB::unprepared($sql);
            foreach ($sqlArr as $item) {
                $subString = strtolower(substr(trim($item->sql), 0, 12));
                $actionArr = explode(' ', $subString);
                $action = $actionArr[0];
                if (($action == 'insert' || $action == 'update' || $action = 'delete') && $item->values) {
//                    Log::info('here commit insert', [
//                        'item' => $item,
//                    ]);
                    $result = DB::connection()->{$action}($item->sql, json_decode($item->values, true));
                } else {
//                    Log::info('exec:' . $item->sql, [
//                        'value' => $item->values,
//                    ]);
                    $result = DB::connection()->getPdo()->exec(str_replace('\\', '\\\\', $item->sql));
                }

                if ($item->check_result && $result != $item->result) {
                    throw new RtException("db had been changed by anothor transact_id");
                }
            }
        }

        $this->setTransactId($transactId);
    }

    public function middlewareRollback()
    {
        $this->stmtRollback();

        $this->logRT(RT::STATUS_COMMIT);

        if ($this->transactRollback) {
            $transactId = RT::getTransactId();
            $transactIdArr = explode('-', $transactId);
            $tid = $transactIdArr[0];

            $item = DB::connection('rt_center')->table('reset_transact')->where('transact_id', $tid)->first();
            $arr = $item->transact_rollback ? json_decode($item->transact_rollback, true) : [];
            $arr = array_merge($arr, $this->transactRollback);
            $arr = array_unique($arr);

            $data = ['transact_rollback' => json_encode($arr)];
            DB::connection('rt_center')->table('reset_transact')->where('transact_id', $tid)->update($data);
        }

        $this->removeRT();
    }

    public function commitTest()
    {
        $this->stmtRollback();

        if (count($this->transactIdArr) > 1) {
            array_pop($this->transactIdArr);

            return true;
        }

        $this->logRT(RT::STATUS_COMMIT);
    }

    public function rollBackTest()
    {
        $this->stmtRollback();

        if (count($this->transactIdArr) > 1) {
            $transactId = $this->getTransactId();
            foreach ($this->transactRollback as $i => $txId) {
                if (strpos($txId, $transactId) === 0) {
                    unset($this->transactRollback[$i]);
                }
            }
            array_push($this->transactRollback, $transactId);
            array_pop($this->transactIdArr);
            return true;
        }

        $this->logRT(RT::STATUS_ROLLBACK);
    }

    public function setTransactId($transactId)
    {
        $this->transactIdArr = explode('-', $transactId);
    }


    public function getTransactId()
    {
        return implode('-', $this->transactIdArr);
    }

    public function getTransactRollback()
    {
        return $this->transactRollback;
    }

    public function logRT($status)
    {
        $sqlArr = session()->get('rt_transact_sql');
        $requestId = session()->get('rt-request-id');
        if (is_null($requestId)) {
            $requestId = $this->transactIdArr[0];
        }

        if ($sqlArr) {
            foreach ($sqlArr as $item) {
                DB::connection('rt_center')->table('reset_transact_sql')->insert([
                    'request_id' => $requestId,
                    'transact_id' => $this->transactIdArr[0],
                    'chain_id' => $item['transact_id'],
                    'transact_status' => $status,
                    'sql' => value($item['sql']),
                    'values' => $item['values'],
                    'result' => $item['result'],
                    'check_result' => $item['check_result'],
                    'connection' => $item['connection'],
                ]);
            }
        }
    }

    private function removeRT()
    {
        $this->transactIdArr = [];

        session()->remove('rt_transact_sql');
        session()->remove('rt-request-id');
    }

    public function saveQuery($query, $bindings, $result, $checkResult, $keyName = null, $id = null)
    {
        $rtTransactId = $this->getTransactId();
        $rtSkip = session()->get('rt_skip');
        if (!$rtSkip && $rtTransactId && $query && !strpos($query, 'reset_transact')) {
            $subString = strtolower(substr(trim($query), 0, 12));
            $actionArr = explode(' ', $subString);
            $action = $actionArr[0];

            if (!in_array($action, ['insert', 'update', 'delete'])) {
                if ($bindings) {
                    $values = $bindings;
                    for ($i = 0; $i < count($bindings); $i++) {
                        if (!is_null($bindings[$i])) {
                            $query = Str::replaceFirst('?', "'%s'", $query);
                        } else {
                            $query = Str::replaceFirst('?', 'null', $query);
                            unset($values[$i]);
                        }
                    }
                    $sql = str_replace(', ?', '', $query);
                    $bindings = $values;
                } else {
                    $sql = str_replace("?", "'%s'", $query);
                }
                $completeSql = vsprintf($sql, $bindings);
            } else {
                $completeSql = $query;
                if ($bindings) {
                    for ($i = 0; $i < count($bindings); $i++) {
                        $bindings[$i] = is_null($bindings[$i]) ? null : (string)$bindings[$i];
                    }
                }
            }

            if (in_array($action, ['insert', 'update', 'delete', 'set', 'savepoint', 'rollback']) &&
                !in_array('`telescope_entries`', $actionArr) &&
                !in_array('`telescope_entries_tags`', $actionArr)) {
                $backupSql = $completeSql;
                if ($action == 'insert') {
                    // if only queryBuilder insert or batch insert then return false
                    preg_match("/insert into (.+) \((.+)\) values \((.+)\)/s", $backupSql, $match);
                    $database = DB::connection()->getConfig('database');
                    $table = $match[1];
                    $columns = $match[2];
                    $parameters = $match[3];
                    if (is_null($id)) {
                        $id = DB::connection()->getPdo()->lastInsertId();
                        // extract variables from sql
                        $columnItem = DB::selectOne('select column_name as `column_name` from information_schema.columns where table_schema = ? and table_name = ? and column_key="PRI"', [$database, trim($table, '`')]);
                        $keyName = $columnItem?->column_name;
                    }

                    if ($keyName && !empty($keyName) && $id != 0 && strpos($columns, "`{$keyName}`") === false) {
                        $columns = "`{$keyName}`, " . $columns;
                        array_unshift($bindings, $id);
                        $parameters = "?, " . $parameters;
                    }

                    $backupSql = "insert into $table ($columns) values ($parameters)";
                }

                $connectionName = DB::connection()->getConfig('connection_name');
                $sqlItem = [
                    'transact_id' => $rtTransactId,
                    'sql' => $backupSql,
                    'values' => json_encode($bindings, JSON_UNESCAPED_UNICODE),
                    'result' => $result,
                    'check_result' => (int)$checkResult,
                    'connection' => $connectionName
                ];
                session()->push('rt_transact_sql', $sqlItem);
            }
        }
    }

    private function stmtBegin()
    {
        session()->put('rt_stmt', 'begin');
        DB::beginTransaction();
        session()->remove('rt_stmt', 'begin');
    }

    private function stmtCommit()
    {
        session()->put('rt_stmt', 'begin');
        DB::commit();
        session()->remove('rt_stmt', 'begin');
    }

    private function stmtRollback()
    {
        session()->put('rt_stmt', 'rollback');
        DB::rollBack();
        session()->remove('rt_stmt', 'rollback');
    }
}
