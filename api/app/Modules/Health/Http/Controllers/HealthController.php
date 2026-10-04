<?php

namespace App\Modules\Health\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;
use Throwable;

final class HealthController
{
    /**
     * Liveness: the process can serve HTTP. Never checks dependencies, so a database
     * outage doesn't make the orchestrator restart healthy containers.
     */
    public function live(): JsonResponse
    {
        return response()->json(['status' => 'ok']);
    }

    /**
     * Readiness: the instance can do useful work. Fails (503) when a hard dependency
     * is unreachable so the load balancer stops routing traffic to it.
     */
    public function ready(): JsonResponse
    {
        $checks = [
            'database' => fn () => DB::connection()->select('select 1'),
            'redis' => fn () => Redis::connection()->ping(),
        ];

        $results = [];
        foreach ($checks as $name => $check) {
            try {
                $check();
                $results[$name] = 'ok';
            } catch (Throwable $e) {
                Log::warning('Readiness check failed', ['check' => $name, 'error' => $e->getMessage()]);
                $results[$name] = 'fail';
            }
        }

        $ready = ! in_array('fail', $results, true);

        return response()->json(
            ['status' => $ready ? 'ok' : 'fail', 'checks' => $results],
            $ready ? 200 : 503,
        );
    }
}
