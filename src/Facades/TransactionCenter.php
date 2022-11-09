<?php

namespace Laravel\ResetTransaction\Facades;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Laravel\ResetTransaction\Exception\RtException;

class TransactionCenter
{
    protected $transactId;

    public function __construct()
    {
        DB::setDefaultConnection('rt_center');
    }

    public function commit(string $transactId, array $transactRollback)
    {
        $item = DB::table('reset_transact')->where('transact_id', $transactId)->first();
        if (!$item) {
            throw new RtException("transact_id not found");
        }

        if ($item->action != RTCenter::ACTION_START) {
            throw new RtException("transact_id has been processed");
        }

        $this->transactId = $transactId;
        if ($item->transact_rollback) {
            $rollArr = json_decode($item->transact_rollback, true);
            $transactRollback = array_merge($transactRollback, $rollArr);
        }
        foreach ($transactRollback as $tid) {
            DB::table('reset_transact_sql')->where('transact_id', $transactId)->where('chain_id', 'like', $tid . '%')->update(['transact_status' => RT::STATUS_ROLLBACK]);
        }
        $xidMap = $this->getXidMap($transactId);
        if ($xidMap) {
        }
        $xidArr = [];
        foreach ($xidMap as $name => $item) {
            $xidArr[$name] = $item['xid'];
        }

        $this->xaBeginTransaction($xidArr);
        try {
            foreach ($xidMap as $name => $item) {
                $sqlCollects = $item['sql_list'];
                foreach ($sqlCollects as $item) {
                    $subString = strtolower(substr(trim($item->sql), 0, 12));
                    $actionArr = explode(' ', $subString);
                    $action = $actionArr[0];
                    if (($action == 'insert' || $action == 'update' || $action == 'delete') && $item->values) {
//                        Log::info('here commit ' . $action, [
//                            'item' => $item,
//                        ]);
                        $result = DB::connection($name)->{$action}($item->sql, json_decode($item->values, true));
                    } else {
                        Log::info('exec:' . $item->sql, [
                            'value' => $item->values,
                        ]);
                        $result = DB::connection($name)->getPdo()->exec(str_replace('\\', '\\\\', $item->sql));
                    }

                    if ($item->check_result && $result != $item->result) {
                        throw new RtException("db had been changed by anothor transact_id");
                    }
                }
            }
        } catch (RtException $e) {
            $this->xaRollBack($xidArr);
            Log::info('xa commit failed: ' . $e->getMessage());
            abort(500, $e->getMessage());
        } catch (\Exception $exception) {
            $this->xaRollBack($xidArr);
            Log::info('xa commit failed: ' . $exception->getMessage());
            abort(500, $exception->getMessage());
        }
        $this->xaCommit($xidArr);

        return $this->result();
    }

    public function rollback(string $transactId, array $transactRollback)
    {
        $item = DB::table('reset_transact')->where('transact_id', $transactId)->first();
        if (!$item) {
            throw new RtException("transact_id not found");
        }

        $this->transactId = $transactId;
        if (strpos('-', $transactId)) {
            $chainId = $transactId;
            $transId = explode('-', $transactId)[0];
            array_push($transactRollback, $chainId);

            foreach ($transactRollback as $txId) {
                DB::table('reset_transact_sql')->where('transact_id', $transId)->where('chain_id', 'like', $txId . '%')->update(['transact_status' => RT::STATUS_ROLLBACK]);
            }
        } else {
            DB::table('reset_transact_sql')->where('transact_id', $transactId)->update(['transact_status' => RT::STATUS_ROLLBACK]);
            DB::table('reset_transact')->where('transact_id', $transactId)->update(['action' => RTCenter::ACTION_ROLLBACK]);
        }

        return $this->result();
    }

    private function getXidMap($transactId)
    {
        $xidMap = [];
        $query = DB::table('reset_transact_sql')->where('transact_id', $transactId);
        $query->whereIn('transact_status', [RT::STATUS_START, RT::STATUS_COMMIT]);
        $list = $query->get();
        foreach ($list as $item) {
            $name = $item->connection;
            $xidMap[$name]['sql_list'][] = $item;
        }

        foreach ($xidMap as $name => &$item) {
            $xid = session_create_id();
            $item['xid'] = $xid;
        }

        return $xidMap;
    }

    /**
     * beginTransaction
     *
     */
    public function xaBeginTransaction($xidArr)
    {
        $this->_XAStart($xidArr);
    }

    /**
     * commit
     * @param $xidArr
     */
    public function xaCommit($xidArr)
    {
        $this->_XAEnd($xidArr);
        $this->_XAPrepare($xidArr);
        $this->_XACommit($xidArr);
    }

    /**
     * rollback
     * @param $xidArr
     */
    public function xaRollBack($xidArr)
    {
        $this->_XAEnd($xidArr);
        $this->_XAPrepare($xidArr);
        $this->_XARollback($xidArr);
    }

    private function _XAStart($xidArr)
    {
        DB::table('reset_transact')->where('transact_id', $this->transactId)->update(['xids_info' => json_encode($xidArr)]);
        foreach ($xidArr as $name => $xid) {
            DB::connection($name)->getPdo()->exec("XA START '{$xid}'");
        }
    }


    private function _XAEnd($xidArr)
    {
        foreach ($xidArr as $name => $xid) {
            DB::connection($name)->getPdo()->exec("XA END '{$xid}'");
        }
    }


    private function _XAPrepare($xidArr)
    {
        DB::table('reset_transact')->where('transact_id', $this->transactId)->update(['action' => RTCenter::ACTION_PREPARE]);
        foreach ($xidArr as $name => $xid) {
            DB::connection($name)->getPdo()->exec("XA PREPARE '{$xid}'");
        }
    }

    private function _XACommit($xidArr)
    {
        DB::table('reset_transact')->where('transact_id', $this->transactId)->update(['action' => RTCenter::ACTION_PREPARE_COMMIT]);
        foreach ($xidArr as $name => $xid) {
            DB::connection($name)->getPdo()->exec("XA COMMIT '{$xid}'");
        }
        DB::table('reset_transact')->where('transact_id', $this->transactId)->update(['action' => RTCenter::ACTION_COMMIT]);
    }

    private function _XARollback($xidArr)
    {
        DB::table('reset_transact')->where('transact_id', $this->transactId)->update(['action' => RTCenter::ACTION_PREPARE_ROLLBACK]);
        foreach ($xidArr as $name => $xid) {
            DB::connection($name)->getPdo()->exec("XA ROLLBACK '{$xid}'");
        }
        DB::table('reset_transact')->where('transact_id', $this->transactId)->update(['action' => RTCenter::ACTION_ROLLBACK]);
    }

    private function result()
    {
        return ['error_code' => 0, 'message' => 'done success', 'errors' => []];
    }
}
