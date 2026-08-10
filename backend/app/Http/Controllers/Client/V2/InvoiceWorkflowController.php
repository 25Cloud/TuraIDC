<?php

declare(strict_types=1);

namespace App\Http\Controllers\Client\V2;

use App\Constants\PaymentGatewayCode;
use App\Http\Controllers\Controller;
use App\Http\Requests\Client\V2\Invoice\PayByAlipayRequest;
use App\Http\Requests\Client\V2\Invoice\PayByBalanceAndAlipayRequest;
use App\Http\Requests\Client\V2\Invoice\PayByBalanceRequest;
use App\Http\Requests\Client\V2\Invoice\QueryAlipayStatusRequest;
use App\Http\Requests\Client\V2\Invoice\StoreRequest;
use App\Services\Finance\ClientInvoicePaymentWorkflowService;
use Illuminate\Http\Request;

class InvoiceWorkflowController extends Controller
{
    public function __construct(
        private readonly ClientInvoicePaymentWorkflowService $workflow,
    ) {}

    public function summary(Request $request)
    {
        return $this->success($this->workflow->summary(
            $request->user(),
            $this->expiredContext($request),
        ));
    }

    public function store(StoreRequest $request)
    {
        return $this->success(
            $this->workflow->create(
                $request->user(),
                $request->validated(),
                trim((string) $request->header('X-Idempotency-Key', '')),
                $this->operationContext($request),
            ),
            '账单创建成功',
        );
    }

    public function payByBalance(PayByBalanceRequest $request, int $id)
    {
        return $this->success(
            $this->workflow->payByBalance(
                $request->user(),
                $id,
                (string) $request->validated('payment_session_token'),
                $this->operationContext($request),
                $this->expiredContext($request),
            ),
            '支付成功',
        );
    }

    public function payByBalanceAndAlipay(PayByBalanceAndAlipayRequest $request, int $id)
    {
        return $this->success(
            $this->workflow->payByBalanceAndAlipay(
                $request->user(),
                $id,
                (string) $request->validated('payment_session_token'),
                (float) $request->validated('balance_amount'),
                $this->operationContext($request),
                $this->expiredContext($request),
                (string) $request->ip(),
            ),
            '组合支付二维码生成成功',
        );
    }

    public function payByAlipay(PayByAlipayRequest $request, int $id)
    {
        return $this->success(
            $this->workflow->payByGateway(
                $request->user(),
                $id,
                (string) $request->validated('payment_session_token'),
                (string) ($request->validated('gateway') ?? PaymentGatewayCode::ALIPAY),
                (string) ($request->validated('payment_type') ?? ''),
                $this->operationContext($request),
                $this->expiredContext($request),
                (string) $request->ip(),
            ),
            '二维码生成成功',
        );
    }

    public function queryAlipayStatus(QueryAlipayStatusRequest $request, int $id)
    {
        return $this->success($this->workflow->queryGatewayStatus(
            $request->user(),
            $id,
            (string) $request->validated('payment_no'),
            (string) ($request->validated('gateway') ?? ''),
            (string) $request->validated('poll_token'),
            (string) $request->ip(),
        ));
    }

    /**
     * @return array<string, mixed>
     */
    private function operationContext(Request $request): array
    {
        $user = $request->user();

        return [
            'actor_type' => 'client',
            'actor_user_id' => (int) ($user?->id ?? 0),
            'actor_name' => (string) ($user?->display_name ?? $user?->nickname ?? $user?->email ?? ''),
            'ip_address' => (string) $request->ip(),
            'trace_id' => (string) $request->header('X-Request-Id', ''),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function expiredContext(Request $request): array
    {
        return array_merge($this->operationContext($request), [
            'actor_type' => 'system',
            'actor_user_id' => 0,
            'actor_name' => 'payment-window-expired',
            'reason' => 'payment_window_expired',
        ]);
    }
}
