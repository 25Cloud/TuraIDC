<?php

declare(strict_types=1);

namespace App\Http\Controllers\Client\V2;

use App\Exceptions\BusinessException;
use App\Http\Controllers\Controller;
use App\Services\Ticket\TicketDeliveryService;
use App\Services\Ticket\TicketUpstreamCallbackService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

final class TicketUpstreamCallbackController extends Controller
{
    public function __construct(
        private readonly TicketUpstreamCallbackService $callbacks,
        private readonly TicketDeliveryService $delivery,
    ) {}

    public function reply(Request $request): JsonResponse
    {
        $legacy = $request->is('api/ticket_reply/sync');

        try {
            $result = $this->callbacks->receiveReply($request->all(), legacy: $legacy);
        } catch (BusinessException $exception) {
            $this->delivery->recordInboundCallbackFailure(
                (string) $request->input('tid', ''),
                $this->reasonCode($exception->getMessage()),
                $exception->getMessage()
            );
            if ($legacy) {
                return response()->json(['status' => 400, 'msg' => $exception->getMessage()], 200);
            }
            throw $exception;
        } catch (\Throwable $exception) {
            $this->delivery->recordInboundCallbackFailure(
                (string) $request->input('tid', ''),
                'callback_exception',
                $exception->getMessage()
            );
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

    private function reasonCode(string $message): string
    {
        return match (true) {
            str_contains($message, '签名') || str_contains($message, 'token') => 'signature_invalid',
            str_contains($message, '绑定') || str_contains($message, '工单') => 'binding_invalid',
            str_contains($message, '附件') => 'attachment_invalid',
            str_contains($message, '供应商') => 'supplier_disabled',
            default => 'callback_rejected',
        };
    }
}
