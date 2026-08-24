<?php

declare(strict_types=1);

namespace App\Http\Controllers\Open\V2;

use App\Http\Controllers\Controller;
use App\Http\Requests\Open\V2\OpenOrderStoreRequest;
use App\Models\Invoice;
use App\Models\User;
use App\Services\Finance\CheckoutService;
use App\Services\Finance\PaymentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class OpenOrderController extends Controller
{
    public function __construct(
        private readonly CheckoutService $checkout,
        private readonly PaymentService $payments,
    ) {}

    public function store(OpenOrderStoreRequest $request): JsonResponse
    {
        $user = $request->attributes->get('api_key_user');
        $data = $request->validated();

        $invoice = $this->checkout->create((int) $user->id, [
            'product_id' => (int) $data['product_id'],
            'billing_cycle' => (string) $data['billing_cycle'],
            'quantity' => max((int) ($data['quantity'] ?? 1), 1),
            'config' => (array) ($data['config'] ?? []),
            'quote_token' => (string) $data['quote_token'],
        ], [
            'idempotency_key' => (string) $data['idempotency_key'],
            'trace_id' => 'open:'.$request->attributes->get('api_key')->key_prefix,
            'ip_address' => (string) $request->ip(),
        ]);

        return $this->success($this->presentInvoice($invoice), '下单成功');
    }

    public function payByBalance(Request $request, int $invoice): JsonResponse
    {
        $user = $request->attributes->get('api_key_user');
        $target = $this->findOwnedInvoice($user, $invoice);

        $this->payments->payByBalance($target, $user, ['trace_id' => 'open:pay:'.$target->invoice_no]);

        return $this->success($this->presentInvoice($target->fresh()), '支付成功');
    }

    public function index(Request $request): JsonResponse
    {
        $user = $request->attributes->get('api_key_user');
        $items = Invoice::query()
            ->where('user_id', (int) $user->id)
            ->orderByDesc('id')
            ->limit(50)
            ->get()
            ->map(fn (Invoice $invoice) => $this->presentInvoice($invoice));

        return $this->success(['list' => $items]);
    }

    public function show(Request $request, int $invoice): JsonResponse
    {
        $user = $request->attributes->get('api_key_user');

        return $this->success($this->presentInvoice($this->findOwnedInvoice($user, $invoice)));
    }

    private function findOwnedInvoice(User $user, int $id): Invoice
    {
        $invoice = Invoice::query()
            ->where('user_id', (int) $user->id)
            ->find($id);

        if (! $invoice) {
            abort(404);
        }

        return $invoice;
    }

    private function presentInvoice(Invoice $invoice): array
    {
        return [
            'id' => (int) $invoice->id,
            'invoice_no' => (string) $invoice->invoice_no,
            'product_name' => (string) ($invoice->product_spec_snapshot ?? ''),
            'amount' => (string) $invoice->amount,
            'paid_amount' => (string) $invoice->paid_amount,
            'status' => (int) $invoice->status,
            'paid_at' => $invoice->paid_at?->format('Y-m-d H:i:s'),
            'created_at' => $invoice->created_at?->format('Y-m-d H:i:s'),
        ];
    }
}
