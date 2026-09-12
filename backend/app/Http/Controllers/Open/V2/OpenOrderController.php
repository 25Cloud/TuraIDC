<?php

declare(strict_types=1);

namespace App\Http\Controllers\Open\V2;

use App\Exceptions\BusinessException;
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
    /** 列表默认页大小（与旧 limit 行为一致），page_size 可放大但不超过上限 */
    private const LIST_DEFAULT_PAGE_SIZE = 50;

    private const LIST_MAX_PAGE_SIZE = 200;

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
        $page = max((int) $request->query('page', '1'), 1);
        $pageSize = min(max((int) $request->query('page_size', (string) self::LIST_DEFAULT_PAGE_SIZE), 1), self::LIST_MAX_PAGE_SIZE);

        $query = Invoice::query()
            ->where('user_id', (int) $user->id)
            ->orderByDesc('id');
        $total = (int) $query->count();
        $items = $query->forPage($page, $pageSize)
            ->get()
            ->map(fn (Invoice $invoice) => $this->presentInvoice($invoice));

        return $this->success([
            'list' => $items,
            'total' => $total,
            'page' => $page,
            'page_size' => $pageSize,
        ]);
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
            // 越权/不存在统一 404，但用业务码给出明确语义，而不是裸 abort 的「接口不存在」
            throw new BusinessException('账单不存在', 40400, 404);
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
            // 履约完成后回填：下游据此把上游账单映射到上游服务实例（开通轮询/续费恢复）
            'service_id' => $invoice->service_id !== null ? (int) $invoice->service_id : null,
            'paid_at' => $invoice->paid_at?->format('Y-m-d H:i:s'),
            'created_at' => $invoice->created_at?->format('Y-m-d H:i:s'),
        ];
    }
}
