<?php

declare(strict_types=1);

namespace App\Http\Controllers\Client\V2;

use App\Constants\InvoiceStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Client\V2\Invoice\ListInvoicesRequest;
use App\Http\Requests\Client\V2\Invoice\ShowInvoiceRequest;
use App\Http\Resources\Client\V2\ClientInvoiceDetailResource;
use App\Http\Resources\Client\V2\ClientInvoiceSummaryResource;
use App\Models\Invoice;
use App\Services\Finance\CheckoutSecurityService;
use App\Services\Finance\CheckoutService;
use App\Services\Finance\ClientInvoicePaymentWorkflowService;
use App\Services\Finance\InvoiceV2QueryService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class InvoiceController extends Controller
{
    public function __construct(
        private readonly InvoiceV2QueryService $invoices,
        private readonly CheckoutService $checkoutService,
        private readonly CheckoutSecurityService $checkoutSecurityService,
        private readonly ClientInvoicePaymentWorkflowService $invoicePayments,
    ) {}

    public function index(ListInvoicesRequest $request): JsonResponse
    {
        $userId = (int) $request->user()->id;
        $this->checkoutService->cancelExpiredUnpaidInvoicesForUser(
            $userId,
            $this->paymentWindowExpiredContext($request)
        );

        return $this->paginate(
            $this->invoices->paginateClientInvoices($userId, $request->validated()),
            ClientInvoiceSummaryResource::class
        );
    }

    public function show(ShowInvoiceRequest $request, int $invoice): JsonResponse
    {
        $userId = (int) $request->user()->id;
        $model = $this->invoices->findClientInvoice($userId, $invoice);
        $updated = $this->checkoutService->cancelExpiredUnpaidInvoice(
            $model,
            $this->paymentWindowExpiredContext($request)
        );
        $model = $this->invoices->findClientInvoice($userId, (int) $updated->id);
        $payload = $this->withPaymentOptions($model, $this->invoices->clientInvoiceDetail($model));

        return $this->success([
            'invoice' => (new ClientInvoiceDetailResource($payload))->resolve(),
        ]);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function withPaymentOptions(Invoice $invoice, array $payload): array
    {
        $payableAmount = (float) ($payload['payable_amount'] ?? max((float) $invoice->amount - (float) ($invoice->paid_amount ?? 0), 0));
        $security = $this->checkoutSecurityService->issueInvoicePaymentSession($invoice, (int) $invoice->user_id);
        // 未付与逾期都仍可取消：逾期账单用户侧需要自助放弃，否则只能等客服处理
        $canCancel = in_array((int) $invoice->status, [InvoiceStatus::UNPAID, InvoiceStatus::OVERDUE], true);

        return array_merge($payload, [
            // 与支付流同源：支付方式只有一个组装点，新增/下架网关自动跟随，
            // 不会出现「详情页列得出、发起支付却报错」的两套口径。
            'pay_methods' => $this->invoicePayments->availablePaymentMethods($payableAmount),
            'payment_security' => [
                'can_pay' => $canCancel && (bool) ($security['session_token'] ?? false),
                'session_token' => (string) ($security['session_token'] ?? ''),
                'expires_at' => $security['expires_at'] ?? null,
            ],
            'can_cancel' => $canCancel,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function paymentWindowExpiredContext(Request $request): array
    {
        return [
            'actor_type' => 'system',
            'actor_user_id' => 0,
            'actor_name' => 'payment-window-expired',
            'ip_address' => (string) $request->ip(),
            'trace_id' => (string) $request->header('X-Request-Id', ''),
            'reason' => 'payment_window_expired',
        ];
    }
}
