<?php

declare(strict_types=1);

namespace App\Http\Controllers\Client\V2;

use App\Http\Controllers\Controller;
use App\Http\Requests\Client\V2\Invoice\ListInvoicesRequest;
use App\Http\Requests\Client\V2\Invoice\ShowInvoiceRequest;
use App\Http\Resources\Client\V2\ClientInvoiceDetailResource;
use App\Http\Resources\Client\V2\ClientInvoiceSummaryResource;
use App\Models\Invoice;
use App\Services\Finance\CheckoutSecurityService;
use App\Services\Finance\CheckoutService;
use App\Services\Finance\InvoiceV2QueryService;
use App\Services\Integrations\Payments\PaymentGatewayManager;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class InvoiceController extends Controller
{
    public function __construct(
        private readonly InvoiceV2QueryService $invoices,
        private readonly CheckoutService $checkoutService,
        private readonly CheckoutSecurityService $checkoutSecurityService,
        private readonly PaymentGatewayManager $paymentGatewayManager,
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
        $canCancel = in_array((int) $invoice->status, [0, 3], true);

        return array_merge($payload, [
            'pay_methods' => $this->availableInvoicePayMethods($payableAmount),
            'payment_security' => [
                'can_pay' => $canCancel && (bool) ($security['session_token'] ?? false),
                'session_token' => (string) ($security['session_token'] ?? ''),
                'expires_at' => $security['expires_at'] ?? null,
            ],
            'can_cancel' => $canCancel,
        ]);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function availableInvoicePayMethods(float $payableAmount): array
    {
        if ($payableAmount <= 0) {
            return [['key' => 'free', 'name' => '确认支付', 'label' => '零元账单', 'option_key' => 'free']];
        }

        return [
            ['key' => 'balance', 'name' => '余额支付', 'label' => '账户余额', 'option_key' => 'balance'],
            ...$this->paymentGatewayManager->availableThirdPartyGatewayOptions(),
        ];
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
