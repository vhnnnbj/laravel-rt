<?php

namespace Laravel\ResetTransaction\Middleware;

use Closure;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\Response;
use Laravel\ResetTransaction\Exception\RtException;
use Laravel\ResetTransaction\Facades\RT;

class DistributeTransact
{
    /**
     * Handle an incoming request.
     *
     * @param \Illuminate\Http\Request $request
     * @param \Closure $next
     * @return mixed
     */
    public function handle($request, Closure $next)
    {
        $requestId = $request->header('rt-request-id');
        $transactId = $request->header('rt-transact-id');

        if ($transactId) {
            if (!$requestId) {
                throw new RtException('rt_request_id cannot be null');
            }
            session()->put('rt-request-id', $requestId);
//            $item = DB::connection('rt_center')->table('reset_transact_req')->where('request_id', $requestId)->first();
            $item = Redis::get('RTCENTER:RESPONSE:' . $requestId);
            if ($item) {
                $data = json_decode($item->response, true);
                return Response::json($data);
            }

            RT::middlewareBeginTransaction($transactId);
        }

        $response = $next($request);

        $requestId = $request->header('rt-request-id');
        $transactId = $request->header('rt-transact-id');
        $transactIdArr = explode('-', $transactId);
        if ($transactId && $response->isSuccessful()) {
            RT::middlewareRollback();
            Redis::setex('RTCENTER:RESPONSE:' . $requestId, 5 * 60, $response->getContent());
            DB::connection('rt_center')->table('reset_transact_req')->insert([
                'transact_id' => $transactIdArr[0],
                'request_id' => $requestId,
                'response' => $response->getContent(),
            ]);
        }

        return $response;
    }
}
