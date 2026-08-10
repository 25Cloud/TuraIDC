<?php

declare(strict_types=1);

namespace App\Http\Controllers\System;

use App\Http\Controllers\Controller;
use App\Services\System\ProductionReadinessService;
use Illuminate\Http\JsonResponse;

class HealthController extends Controller
{
    public function live(): JsonResponse
    {
        return response()->json([
            'code' => 0,
            'message' => 'ok',
            'data' => ['status' => 'alive'],
            'timestamp' => time(),
        ]);
    }

    public function ready(ProductionReadinessService $readiness): JsonResponse
    {
        $report = $readiness->check();
        $ready = $report['ready'];

        return response()->json([
            'code' => $ready ? 0 : 50300,
            'message' => $ready ? 'ok' : '服务未就绪',
            'data' => [
                'status' => $ready ? 'ready' : 'not_ready',
                'checks' => $report['checks'],
            ],
            'timestamp' => time(),
        ], $ready ? 200 : 503);
    }
}
