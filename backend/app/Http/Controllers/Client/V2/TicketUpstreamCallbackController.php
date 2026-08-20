<?php

declare(strict_types=1);

namespace App\Http\Controllers\Client\V2;

use App\Exceptions\BusinessException;
use App\Http\Controllers\Controller;
use App\Services\Ticket\TicketUpstreamCallbackService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

final class TicketUpstreamCallbackController extends Controller
{
    public function __construct(
        private readonly TicketUpstreamCallbackService $callbacks,
    ) {}

    public function reply(Request $request): JsonResponse
    {
        $legacy = $request->is('api/ticket_reply/sync');

        try {
            $result = $this->callbacks->receiveReply($request->all(), legacy: $legacy);
        } catch (BusinessException $exception) {
            if ($legacy) {
                return response()->json(['status' => 400, 'msg' => $exception->getMessage()], 200);
            }
            throw $exception;
        } catch (\Throwable $exception) {
            Log::warning('上游工单回调处理失败', ['message' => $exception->getMessage()]);
            if ($legacy) {
                return response()->json(['status' => 400, 'msg' => '工单回复处理失败'], 200);
            }
            throw $exception;
        }

        if ($legacy) {
            return response()->json(['status' => 200, 'msg' => '工单回复成功', 'data' => $result]);
        }

        return $this->success($result, '上游回复已接收');
    }
}
