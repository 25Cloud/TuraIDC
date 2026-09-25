<?php

declare(strict_types=1);

namespace App\Http\Controllers\Client\V2;

use App\Http\Controllers\Controller;
use App\Services\ZjmfUpstream\HostSyncPushReceiver;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * 魔方财务上游 → 本系统（Tura 作为下游）主机状态推送接收端：POST /api/host/sync。
 * 签名校验由 verify.ticket.upstream.callback 中间件完成。
 */
final class HostUpstreamSyncController extends Controller
{
    public function __construct(
        private readonly HostSyncPushReceiver $receiver,
    ) {}

    public function sync(Request $request): JsonResponse
    {
        try {
            $result = $this->receiver->receive($request->all());
        } catch (\Throwable $exception) {
            Log::warning('上游主机推送处理失败', [
                'service_id' => (int) $request->input('id', 0),
                'message' => $exception->getMessage(),
                'exception' => $exception::class,
            ]);

            return response()->json(['status' => 400, 'msg' => '主机推送处理失败'], 200);
        }

        return response()->json($result, 200);
    }
}
