<?php

namespace App\Services\Finance;

use App\Constants\InvoiceStatus;
use App\Constants\InvoiceType;
use App\Constants\OrderStatus;
use App\Constants\OrderType;
use App\Constants\PaymentGatewayCode;
use App\Constants\PaymentStatus;
use App\Constants\ProductType;
use App\Exceptions\BusinessException;
use App\Models\Invoice;
use App\Models\OperationLog;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Product;
use App\Models\User;
use App\Services\ProductCatalog\ProductDisplayNameResolver;
use App\Services\System\OperationLogService;
use App\Support\AdminPrivacy;
use Carbon\CarbonImmutable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class InvoiceService
{
    public function __construct(
        private readonly ?ProductDisplayNameResolver $productDisplayNameResolver = null,
    ) {}

    /**
     * 根据订单创建账单
     */
    public function createFromOrder(Order $order): Invoice
    {
        $invoice = Invoice::create([
            'invoice_no' => Invoice::generateInvoiceNoFromOrderNo((string) $order->order_no),
            'user_id' => $order->user_id,
            'order_id' => $order->id,
            'product_id' => $order->product_id,
            'product_spec_snapshot' => $order->product_spec_snapshot,
            'product_type_snapshot' => $order->product_type_snapshot,
            'service_id' => $order->service_id,
            'coupon_id' => $order->coupon_id,
            'user_coupon_id' => $order->user_coupon_id,
            'coupon_code' => $order->coupon_code,
            'type' => $order->type === OrderType::RENEW ? InvoiceType::RENEW : 'normal',
            'amount' => $order->amount - $order->discount,
            'discount' => $order->discount ?? 0,
            'billing_cycle' => $order->billing_cycle,
            'quantity' => $order->quantity ?? 1,
            'config_snapshot' => $order->config_snapshot,
            'config_pricing_snapshot' => $order->config_pricing_snapshot,
            'coupon_snapshot' => $order->coupon_snapshot,
            'status' => InvoiceStatus::UNPAID,
            'due_date' => now()->addDays(7),
            'trace_id' => $order->trace_id,
        ]);

        return $this->syncProjection($invoice);
    }

    /**
     * 直接创建账单（不依赖订单）
     */
    public function createDirect(array $data): Invoice
    {
        $invoice = Invoice::create([
            'invoice_no' => Invoice::generateInvoiceNo(),
            'user_id' => $data['user_id'],
            'product_id' => $data['product_id'] ?? null,
            'product_spec_snapshot' => $data['product_spec_snapshot'] ?? null,
            'product_type_snapshot' => $data['product_type_snapshot'] ?? null,
            'service_id' => $data['service_id'] ?? null,
            'coupon_id' => $data['coupon_id'] ?? null,
            'user_coupon_id' => $data['user_coupon_id'] ?? null,
            'coupon_code' => $data['coupon_code'] ?? null,
            'type' => $data['type'] ?? 'normal',
            'amount' => $data['amount'],
            'discount' => $data['discount'] ?? 0,
            'billing_cycle' => $data['billing_cycle'] ?? null,
            'quantity' => $data['quantity'] ?? 1,
            'config_snapshot' => $data['config_snapshot'] ?? null,
            'config_pricing_snapshot' => $data['config_pricing_snapshot'] ?? null,
            'coupon_snapshot' => $data['coupon_snapshot'] ?? null,
            'status' => InvoiceStatus::UNPAID,
            'due_date' => $data['due_date'] ?? now()->addDays(7),
            'trace_id' => $data['trace_id'] ?? null,
        ]);

        return $this->syncProjection($invoice);
    }

    /**
     * 充值到账 -> 创建充值类型账单
     */
    public function createForRecharge(User $user, float $amount, ?Payment $payment = null, ?string $remark = null, ?string $traceId = null): Invoice
    {
        if ($payment instanceof Payment && (int) ($payment->invoice_id ?? 0) > 0) {
            $existing = Invoice::query()
                ->where('user_id', (int) $user->id)
                ->find((int) $payment->invoice_id);

            if ($existing instanceof Invoice) {
                return $existing;
            }
        }

        return DB::transaction(function () use ($user, $amount, $payment, $remark, $traceId): Invoice {
            $invoice = Invoice::create([
                'invoice_no' => Invoice::generateInvoiceNo(),
                'user_id' => $user->id,
                'type' => InvoiceType::RECHARGE,
                'amount' => $amount,
                'paid_amount' => $amount,
                'status' => InvoiceStatus::PAID,
                'paid_at' => now(),
                'due_date' => null,  // 充值账单已立即付清，截止日期无意义
                'config_snapshot' => array_filter([
                    'remark' => $remark,
                    'payment_no' => $payment?->payment_no,
                ], static fn ($value) => $value !== null && $value !== ''),
                'trace_id' => $traceId ?: $payment?->trace_id,
            ]);

            if ($payment) {
                $payment->forceFill(['invoice_id' => $invoice->id])->save();
            }

            return $this->syncProjection($invoice);
        });
    }

    /**
     * 推广返利入账 -> 创建入账类型账单
     */
    public function createForReferralCredit(User $user, float $amount, ?string $remark = null, ?string $traceId = null): Invoice
    {
        return Invoice::create([
            'invoice_no' => Invoice::generateInvoiceNo(),
            'user_id' => $user->id,
            'type' => InvoiceType::REFERRAL_CREDIT,
            'amount' => $amount,
            'paid_amount' => $amount,
            'status' => InvoiceStatus::PAID,
            'paid_at' => now(),
            'due_date' => null,  // 推广返利账单已立即入账，截止日期无意义
            'config_snapshot' => $remark ? ['remark' => $remark] : null,
            'trace_id' => $traceId,
        ]);
    }

    /**
     * 扣款 -> 创建扣款类型账单
     */
    public function createForDeduction(User $user, float $amount, ?string $remark = null, ?string $traceId = null): Invoice
    {
        return Invoice::create([
            'invoice_no' => Invoice::generateInvoiceNo(),
            'user_id' => $user->id,
            'type' => InvoiceType::DEDUCTION,
            'amount' => $amount,
            'paid_amount' => $amount,
            'status' => InvoiceStatus::PAID,
            'paid_at' => now(),
            'due_date' => null,  // 扣款账单已立即扣清，截止日期无意义
            'config_snapshot' => $remark ? ['remark' => $remark] : null,
            'trace_id' => $traceId,
        ]);
    }

    /**
     * 查询账单列表
     */
    public function adminList(array $filters, int $perPage = 20)
    {
        $query = Invoice::with([
            'user:id,email,nickname,phone',
            'order:id,order_no,status,type,service_id,paid_at,product_id,billing_cycle',
            'order.product:id,product_type,service_type_code,product_group_id,remark,config_options,purchase_requires',
            'product:id,product_type,service_type_code,product_group_id,remark,config_options,purchase_requires',
            'service:id,name,status,expires_at',
            'payments',
            'items',
        ]);

        if (! empty($filters['invoice_no'])) {
            $query->where('invoice_no', $filters['invoice_no']);
        }
        if (! empty($filters['keyword'])) {
            $keyword = trim((string) $filters['keyword']);
            $query->where(function ($query) use ($keyword) {
                $query->where('invoice_no', 'like', "%{$keyword}%")
                    ->orWhere('id', $keyword)
                    ->orWhereHas('user', function ($userQuery) use ($keyword) {
                        $userQuery->where('email', 'like', "%{$keyword}%")
                            ->orWhere('nickname', 'like', "%{$keyword}%")
                            ->orWhere('phone', 'like', "%{$keyword}%");
                    })
                    ->orWhereHas('order', function ($orderQuery) use ($keyword) {
                        $orderQuery->where('order_no', 'like', "%{$keyword}%");
                    });
            });
        }
        if (! empty($filters['user_id'])) {
            $query->where('user_id', $filters['user_id']);
        }
        if (isset($filters['status']) && $filters['status'] !== '') {
            $query->where('status', $filters['status']);
        }
        if (! empty($filters['type'])) {
            $type = $filters['type'];
            if (in_array($type, ['new', 'normal'], true)) {
                $query->whereIn('type', ['new', 'normal']);
            } else {
                $query->where('type', $type);
            }
        }
        if (! empty($filters['product_id'])) {
            $query->where('product_id', $filters['product_id']);
        }
        $this->applyDateFilter($query, $filters);

        $paginator = $query->orderByDesc('id')->paginate($perPage);
        $paginator->setCollection(
            $paginator->getCollection()->map(fn (Invoice $invoice) => $this->transformAdminInvoiceListItem($invoice))
        );

        return $paginator;
    }

    public function adminDetail(int $id): array
    {
        $invoice = Invoice::with([
            'user:id,email,nickname,phone',
            'order:id,order_no,status,type,service_id,paid_at,product_id,billing_cycle',
            'order.product:id,product_type,service_type_code,product_group_id,remark,config_options,purchase_requires',
            'product:id,product_type,service_type_code,product_group_id,remark,config_options,purchase_requires',
            'service:id,name,status,expires_at',
            'payments',
            'items',
        ])->findOrFail($id);

        $scene = $this->resolveInvoiceScene($invoice);
        $paymentSummary = $this->resolveInvoicePaymentSummary($invoice);
        $displayStatus = $this->resolveInvoiceDisplayStatus($invoice, $paymentSummary);
        $productDisplayName = $this->resolveInvoiceProductDisplayName($invoice);
        $productSpecDisplay = $this->resolveInvoiceProductSpecDisplay($invoice, $scene, $productDisplayName);
        $combinedDisplayName = $this->resolveInvoiceCombinedDisplayName($invoice, $productDisplayName);

        return [
            'id' => (int) $invoice->id,
            'invoice_no' => (string) $invoice->invoice_no,
            'user_id' => (int) $invoice->user_id,
            'product_spec_snapshot' => (string) ($invoice->product_spec_snapshot ?? ''),
            'product_spec_display' => $productSpecDisplay,
            'product_display_name' => $productDisplayName,
            'combined_display_name' => $combinedDisplayName,
            'user' => $this->adminUserPayload($invoice->user),
            'order_id' => (int) ($invoice->order_id ?? 0),
            'order' => $invoice->order ? [
                'id' => (int) $invoice->order->id,
                'order_no' => (string) $invoice->order->order_no,
                'status' => (int) $invoice->order->status,
                'type' => (string) $invoice->order->type,
                'service_id' => (int) ($invoice->order->service_id ?? 0),
                'paid_at' => $invoice->order->paid_at?->format('Y-m-d H:i:s'),
                'billing_cycle' => (string) ($invoice->order->billing_cycle ?? ''),
                'product' => $invoice->order->product ? [
                    'id' => (int) $invoice->order->product->id,
                    'name' => (string) $invoice->order->product->name,
                ] : null,
            ] : null,
            'product_id' => (int) ($invoice->product_id ?? 0),
            'product' => $invoice->product ? [
                'id' => (int) $invoice->product->id,
                'name' => (string) $invoice->product->name,
                'product_type' => (string) ($invoice->product->product_type ?? ''),
                'config_options' => (array) ($invoice->product->config_options ?? []),
            ] : null,
            'service' => $invoice->service ? [
                'id' => (int) $invoice->service->id,
                'name' => (string) $invoice->service->name,
                'status' => (int) $invoice->service->status,
                'expires_at' => $invoice->service->expires_at?->format('Y-m-d H:i:s'),
            ] : null,
            'type' => (string) $invoice->type,
            'type_label' => $this->resolveInvoiceTypeLabel((string) $invoice->type, $invoice),
            'scene' => $scene,
            'amount' => number_format((float) $invoice->amount, 2, '.', ''),
            'discount' => number_format((float) ($invoice->discount ?? 0), 2, '.', ''),
            'paid_amount' => number_format((float) ($invoice->paid_amount ?? 0), 2, '.', ''),
            'payable_amount' => number_format(max((float) $invoice->amount - (float) ($invoice->paid_amount ?? 0), 0), 2, '.', ''),
            'status' => (int) $displayStatus['status'],
            'status_label' => (string) $displayStatus['status_label'],
            'raw_status' => (int) $invoice->status,
            'raw_status_label' => InvoiceStatus::$labels[$invoice->status] ?? (string) $invoice->status,
            'billing_cycle' => (string) ($invoice->billing_cycle ?? ''),
            'quantity' => (int) ($invoice->quantity ?? 1),
            'summary' => $this->buildInvoiceSummary($invoice, $scene),
            'due_date' => $invoice->due_date?->format('Y-m-d'),
            'paid_at' => $invoice->paid_at?->format('Y-m-d H:i:s'),
            'created_at' => $invoice->created_at?->format('Y-m-d H:i:s'),
            'updated_at' => $invoice->updated_at?->format('Y-m-d H:i:s'),
            'trace_id' => (string) ($invoice->trace_id ?? ''),
            'refund_trace_id' => (string) ($invoice->refund_trace_id ?? ''),
            'config_snapshot' => (array) ($invoice->config_snapshot ?? []),
            'config_pricing_snapshot' => (array) ($invoice->config_pricing_snapshot ?? []),
            'coupon_snapshot' => (array) ($invoice->coupon_snapshot ?? []),
            'payment_summary' => $paymentSummary,
            'payments' => $this->thirdPartyPayments($invoice->payments)->map(fn ($p) => [
                'id' => (int) $p->id,
                'payment_no' => (string) $p->payment_no,
                'gateway' => $this->paymentGatewayKey($p),
                'gateway_key' => $this->paymentGatewayKey($p),
                'gateway_label' => $this->resolvePaymentGatewayLabel($this->paymentGatewayKey($p)),
                'amount' => number_format((float) $p->amount, 2, '.', ''),
                'status' => (int) $p->status,
                'status_label' => $this->resolvePaymentStatusLabel((int) $p->status),
                'paid_at' => $p->paid_at?->format('Y-m-d H:i:s'),
                'trace_id' => (string) ($p->trace_id ?? ''),
                'trade_no' => (string) ($p->trade_no ?? ''),
                'refund_method' => (string) data_get((array) ($p->callback_raw ?? []), 'refund.refund_method', ''),
                'refund_method_label' => (string) data_get((array) ($p->callback_raw ?? []), 'refund.refund_method_label', ''),
                'refund_reason' => (string) data_get((array) ($p->callback_raw ?? []), 'refund.refund_reason', ''),
                'refunded_at' => (string) data_get((array) ($p->callback_raw ?? []), 'refund.refunded_at', ''),
            ])->values()->all(),
            'items' => $this->buildInvoiceItems($invoice, $scene),
            'logs' => $this->buildInvoiceLogs($invoice, $scene),
            'can_cancel' => in_array((int) $invoice->status, [InvoiceStatus::UNPAID, InvoiceStatus::OVERDUE], true),
        ];
    }

    private function applyDateFilter($query, array $filters): void
    {
        $start = trim((string) ($filters['start_date'] ?? ''));
        $end = trim((string) ($filters['end_date'] ?? ''));

        if ($start === '' && $end === '') {
            return;
        }

        if ($start !== '' && $end !== '') {
            $query->whereBetween('created_at', [
                CarbonImmutable::parse($start)->startOfDay(),
                CarbonImmutable::parse($end)->endOfDay(),
            ]);

            return;
        }

        if ($start !== '') {
            $query->where('created_at', '>=', CarbonImmutable::parse($start)->startOfDay());

            return;
        }

        $query->where('created_at', '<=', CarbonImmutable::parse($end)->endOfDay());
    }

    private function adminUserPayload(?User $user): ?array
    {
        if (! $user instanceof User) {
            return null;
        }

        $privacy = AdminPrivacy::current();

        return [
            'id' => (int) $user->id,
            'email' => $privacy->email($user->email),
            'nickname' => (string) ($user->nickname ?? ''),
            'phone' => $privacy->phone($user->phone ?? ''),
        ];
    }

    public function clientDetail(Invoice $invoice): array
    {
        $invoice->loadMissing([
            'order:id,order_no,status,type,service_id,paid_at,product_id,billing_cycle,amount,discount,paid_amount,quantity,product_spec_snapshot,product_type_snapshot,config_snapshot,config_pricing_snapshot',
            'order.product:id,product_type,service_type_code,product_group_id,remark,config_options,purchase_requires',
            'order.product.productGroup:id,second_product_group_id,name',
            'order.product.productGroup.secondProductGroup:id,first_product_group_id,name',
            'order.product.productGroup.secondProductGroup.firstProductGroup:id,code,name',
            'product:id,product_type,service_type_code,product_group_id,remark,config_options,purchase_requires',
            'product.productGroup:id,second_product_group_id,name',
            'product.productGroup.secondProductGroup:id,first_product_group_id,name',
            'product.productGroup.secondProductGroup.firstProductGroup:id,code,name',
            'service:id,name,status,expires_at',
            'payments',
            'items',
        ]);

        $scene = $this->resolveInvoiceScene($invoice);
        $paymentSummary = $this->resolveInvoicePaymentSummary($invoice);
        $displayStatus = $this->resolveInvoiceDisplayStatus($invoice, $paymentSummary);
        $productDisplayName = $this->resolveInvoiceProductDisplayName($invoice);
        $productSpecDisplay = $this->resolveInvoiceProductSpecDisplay($invoice, $scene, $productDisplayName);
        $combinedDisplayName = $this->resolveInvoiceCombinedDisplayName($invoice, $productDisplayName);

        return [
            'id' => (int) $invoice->id,
            'invoice_no' => (string) $invoice->invoice_no,
            'product_spec_snapshot' => (string) ($invoice->product_spec_snapshot ?? ''),
            'product_spec_display' => $productSpecDisplay,
            'product_display_name' => $productDisplayName,
            'combined_display_name' => $combinedDisplayName,
            'product_id' => (int) ($invoice->product_id ?? 0),
            'product' => $invoice->product ? [
                'id' => (int) $invoice->product->id,
                'name' => (string) $invoice->product->name,
                'product_type' => (string) ($invoice->product->product_type ?? ''),
                'config_options' => (array) ($invoice->product->config_options ?? []),
            ] : null,
            'service' => $invoice->service ? [
                'id' => (int) $invoice->service->id,
                'name' => (string) $invoice->service->name,
                'status' => (int) $invoice->service->status,
                'expires_at' => $invoice->service->expires_at?->format('Y-m-d H:i:s'),
            ] : null,
            'type' => (string) $invoice->type,
            'type_label' => $this->resolveInvoiceTypeLabel((string) $invoice->type, $invoice),
            'scene' => $scene,
            'amount' => number_format((float) $invoice->amount, 2, '.', ''),
            'discount' => number_format((float) ($invoice->discount ?? 0), 2, '.', ''),
            'paid_amount' => number_format((float) ($invoice->paid_amount ?? 0), 2, '.', ''),
            'payable_amount' => number_format(max((float) $invoice->amount - (float) ($invoice->paid_amount ?? 0), 0), 2, '.', ''),
            'status' => (int) $displayStatus['status'],
            'status_label' => (string) $displayStatus['status_label'],
            'billing_cycle' => (string) ($invoice->billing_cycle ?? ''),
            'quantity' => (int) ($invoice->quantity ?? 1),
            'summary' => $this->buildInvoiceSummary($invoice, $scene),
            'due_date' => $invoice->due_date?->format('Y-m-d'),
            'paid_at' => $invoice->paid_at?->format('Y-m-d H:i:s'),
            'created_at' => $invoice->created_at?->format('Y-m-d H:i:s'),
            'updated_at' => $invoice->updated_at?->format('Y-m-d H:i:s'),
            'payment_summary' => $paymentSummary,
            'payments' => $this->thirdPartyPayments($invoice->payments)->map(fn ($p) => [
                'id' => (int) $p->id,
                'payment_no' => (string) $p->payment_no,
                'gateway' => $this->paymentGatewayKey($p),
                'gateway_key' => $this->paymentGatewayKey($p),
                'gateway_label' => $this->resolvePaymentGatewayLabel($this->paymentGatewayKey($p)),
                'amount' => number_format((float) $p->amount, 2, '.', ''),
                'status' => (int) $p->status,
                'status_label' => $this->resolvePaymentStatusLabel((int) $p->status),
                'paid_at' => $p->paid_at?->format('Y-m-d H:i:s'),
                'trade_no' => (string) ($p->trade_no ?? ''),
                'refund_method' => (string) data_get((array) ($p->callback_raw ?? []), 'refund.refund_method', ''),
                'refund_method_label' => (string) data_get((array) ($p->callback_raw ?? []), 'refund.refund_method_label', ''),
                'refund_reason' => (string) data_get((array) ($p->callback_raw ?? []), 'refund.refund_reason', ''),
                'refunded_at' => (string) data_get((array) ($p->callback_raw ?? []), 'refund.refunded_at', ''),
            ])->values()->all(),
            'items' => $this->buildInvoiceItems($invoice, $scene),
            'logs' => $this->buildInvoiceLogs($invoice, $scene),
        ];
    }

    public function adminListItem(Invoice $invoice): array
    {
        $invoice->loadMissing([
            'user:id,email,nickname,phone',
            'order:id,order_no,status,type,service_id,paid_at,product_id,billing_cycle',
            'order.product:id,product_type,service_type_code,product_group_id,remark,config_options,purchase_requires',
            'product:id,product_type,service_type_code,product_group_id,remark,config_options,purchase_requires',
            'service:id,name,status,expires_at',
            'payments',
            'items',
        ]);

        return $this->transformAdminInvoiceListItem($invoice);
    }

    private function transformAdminInvoiceListItem(Invoice $invoice): array
    {
        $paymentSummary = $this->resolveInvoicePaymentSummary($invoice);
        $displayStatus = $this->resolveInvoiceDisplayStatus($invoice, $paymentSummary);
        $scene = $this->resolveInvoiceScene($invoice);
        $productDisplayName = $this->resolveInvoiceProductDisplayName($invoice);
        $productSpecDisplay = $this->resolveInvoiceProductSpecDisplay($invoice, $scene, $productDisplayName);
        $combinedDisplayName = $this->resolveInvoiceCombinedDisplayName($invoice, $productDisplayName);

        return [
            'id' => (int) $invoice->id,
            'invoice_no' => (string) $invoice->invoice_no,
            'product_spec_snapshot' => (string) ($invoice->product_spec_snapshot ?? ''),
            'product_spec_display' => $productSpecDisplay,
            'product_display_name' => $productDisplayName,
            'combined_display_name' => $combinedDisplayName,
            'user' => $this->adminUserPayload($invoice->user),
            'order_id' => (int) ($invoice->order_id ?? 0),
            'order' => $invoice->order ? [
                'id' => (int) $invoice->order->id,
                'order_no' => (string) $invoice->order->order_no,
                'status' => (int) $invoice->order->status,
                'service_id' => (int) ($invoice->order->service_id ?? 0),
                'billing_cycle' => (string) ($invoice->order->billing_cycle ?? ''),
                'paid_at' => $invoice->order->paid_at?->format('Y-m-d H:i:s'),
                'product' => $invoice->order->product ? [
                    'id' => (int) $invoice->order->product->id,
                    'name' => (string) $invoice->order->product->name,
                ] : null,
            ] : null,
            'product_id' => (int) ($invoice->product_id ?? 0),
            'product' => $invoice->product ? [
                'id' => (int) $invoice->product->id,
                'name' => (string) $invoice->product->name,
                'product_type' => (string) ($invoice->product->product_type ?? ''),
            ] : null,
            'type' => (string) $invoice->type,
            'type_label' => $this->resolveInvoiceTypeLabel((string) $invoice->type, $invoice),
            'scene' => $scene,
            'amount' => number_format((float) $invoice->amount, 2, '.', ''),
            'discount' => number_format((float) ($invoice->discount ?? 0), 2, '.', ''),
            'paid_amount' => number_format((float) ($invoice->paid_amount ?? 0), 2, '.', ''),
            'payable_amount' => number_format(max((float) $invoice->amount - (float) ($invoice->paid_amount ?? 0), 0), 2, '.', ''),
            'status' => (int) $displayStatus['status'],
            'status_label' => (string) $displayStatus['status_label'],
            'raw_status' => (int) $invoice->status,
            'raw_status_label' => InvoiceStatus::$labels[$invoice->status] ?? (string) $invoice->status,
            'billing_cycle' => (string) ($invoice->billing_cycle ?? ''),
            'quantity' => (int) ($invoice->quantity ?? 1),
            'summary' => $this->buildInvoiceSummary($invoice, $scene),
            'due_date' => $invoice->due_date?->format('Y-m-d'),
            'paid_at' => $invoice->paid_at?->format('Y-m-d H:i:s'),
            'created_at' => $invoice->created_at?->format('Y-m-d H:i:s'),
            'payment_summary' => $paymentSummary,
        ];
    }

    private function buildInvoiceItems(Invoice $invoice, array $scene): array
    {
        if (($scene['kind'] ?? '') === 'refund' && ! empty($scene['items']) && is_array($scene['items'])) {
            return collect($scene['items'])->map(function ($item, $index) use ($invoice) {
                return [
                    'id' => (int) ($invoice->id * 100 + $index + 1),
                    'description' => (string) ($item['description'] ?? ''),
                    'amount' => number_format((float) ($item['amount'] ?? 0), 2, '.', ''),
                ];
            })->values()->all();
        }

        if ($invoice->items->isNotEmpty()) {
            return $invoice->items->map(fn ($item) => [
                'id' => (int) $item->id,
                'description' => (string) ($item->description ?? $item->item_name ?? ''),
                'amount' => number_format((float) ($item->amount ?? $item->line_amount ?? 0), 2, '.', ''),
            ])->values()->all();
        }

        if (! empty($scene['items']) && is_array($scene['items'])) {
            return collect($scene['items'])->map(function ($item, $index) use ($invoice) {
                return [
                    'id' => (int) ($invoice->id * 100 + $index + 1),
                    'description' => (string) ($item['description'] ?? ''),
                    'amount' => number_format((float) ($item['amount'] ?? 0), 2, '.', ''),
                ];
            })->values()->all();
        }

        return [[
            'id' => (int) $invoice->id,
            'description' => $scene['description'] ?? $this->resolveInvoiceItemDescription($invoice),
            'amount' => number_format((float) $invoice->amount, 2, '.', ''),
        ]];
    }

    private function buildInvoiceSummary(Invoice $invoice, array $scene): array
    {
        $remark = trim((string) ($scene['remark'] ?? $invoice->config_snapshot['remark'] ?? $invoice->coupon_snapshot['remark'] ?? ''));
        $subheadline = (string) ($scene['subheadline'] ?? '');
        $highlight = (string) ($scene['highlight'] ?? '');

        if ($subheadline === '') {
            $subheadline = $this->resolveInvoiceSceneFallbackSubheadline($invoice);
        }
        if ($highlight === '') {
            $highlight = $remark !== '' ? $remark : (string) ($invoice->order?->order_no ?? '');
        }

        return [
            'headline' => (string) ($scene['headline'] ?? $this->resolveInvoiceTypeLabel((string) $invoice->type, $invoice)),
            'subheadline' => $subheadline,
            'badge' => (string) ($scene['badge'] ?? $this->resolveInvoiceTypeLabel((string) $invoice->type, $invoice)),
            'highlight' => $highlight,
            'remark' => $remark,
        ];
    }

    private function resolveInvoiceItemDescription(Invoice $invoice): string
    {
        $productName = trim((string) ($invoice->display_product_name ?? ''));
        if ($productName !== '') {
            return $productName;
        }

        $orderProductName = trim((string) ($invoice->order?->display_product_name ?? ''));
        if ($orderProductName !== '') {
            return $orderProductName;
        }

        return $this->resolveInvoiceTypeLabel((string) $invoice->type, $invoice);
    }

    private function resolveInvoiceScene(Invoice $invoice): array
    {
        $type = InvoiceType::normalize((string) $invoice->type);
        $remark = trim((string) ($invoice->config_snapshot['remark'] ?? $invoice->coupon_snapshot['remark'] ?? ''));
        $productName = trim((string) ($invoice->order?->display_product_name ?? ''));
        $billingCycle = trim((string) ($invoice->billing_cycle ?? $invoice->order?->billing_cycle ?? ''));
        $paymentSummary = $this->resolveInvoicePaymentSummary($invoice);
        $refundScene = $this->resolveRefundScene($invoice);

        if ($refundScene !== null) {
            return $refundScene;
        }

        return match ($type) {
            InvoiceType::NEW_PURCHASE => [
                'kind' => 'new_purchase',
                'headline' => '新购账单',
                'subheadline' => '首次购买产生的账单，通常包含产品价格、配置附加费与优惠信息。',
                'badge' => '新购',
                'highlight' => $productName !== '' ? $productName : (string) ($invoice->order?->order_no ?? ''),
                'remark' => $remark !== '' ? $remark : '新购订单已生成',
                'fields' => $this->buildPurchaseLikeSceneFields($invoice, $paymentSummary),
                'items' => $this->buildOrderBasedSceneItems($invoice),
            ],
            InvoiceType::RENEW => [
                'kind' => 'renew',
                'headline' => '续费账单',
                'subheadline' => '用于延长现有服务周期，通常与已有实例关联。',
                'badge' => '续费',
                'highlight' => $billingCycle !== '' ? $billingCycle : (string) ($invoice->order?->order_no ?? ''),
                'remark' => $remark !== '' ? $remark : '续费订单已生成',
                'fields' => $this->buildPurchaseLikeSceneFields($invoice, $paymentSummary),
                'items' => $this->buildOrderBasedSceneItems($invoice),
            ],
            InvoiceType::RECHARGE => [
                'kind' => 'recharge',
                'headline' => '充值账单',
                'subheadline' => '余额充值到账后生成，通常直接完成支付。',
                'badge' => '充值',
                'highlight' => $remark !== '' ? $remark : '资金到账',
                'remark' => $remark !== '' ? $remark : '充值金额已入账',
                'fields' => [
                    ['label' => '入账方式', 'value' => '余额充值'],
                    ['label' => '到账金额', 'value' => number_format((float) $invoice->amount, 2, '.', '')],
                    ['label' => '到账状态', 'value' => InvoiceStatus::$labels[$invoice->status] ?? (string) $invoice->status],
                    ['label' => '支付时间', 'value' => $invoice->paid_at?->format('Y-m-d H:i:s') ?: '--'],
                ],
                'items' => [[
                    'description' => '账户充值入账',
                    'amount' => $invoice->amount,
                ]],
            ],
            InvoiceType::DEDUCTION => [
                'kind' => 'deduction',
                'headline' => '扣款账单',
                'subheadline' => '管理员或系统发起的余额扣减记录。',
                'badge' => '扣款',
                'highlight' => $remark !== '' ? $remark : '余额扣减',
                'remark' => $remark !== '' ? $remark : '余额已扣减',
                'fields' => [
                    ['label' => '扣款方式', 'value' => '余额扣减'],
                    ['label' => '扣款金额', 'value' => number_format((float) $invoice->amount, 2, '.', '')],
                    ['label' => '到账状态', 'value' => InvoiceStatus::$labels[$invoice->status] ?? (string) $invoice->status],
                    ['label' => '处理时间', 'value' => $invoice->paid_at?->format('Y-m-d H:i:s') ?: '--'],
                ],
                'items' => [[
                    'description' => '账户扣款',
                    'amount' => $invoice->amount,
                ]],
            ],
            InvoiceType::REFUND => [
                'kind' => 'refund',
                'headline' => '退款红字账单',
                'subheadline' => '用于冲抵原账单的退款单据，可通过原账单编号追溯退款来源。',
                'badge' => '退款',
                'highlight' => (string) ($invoice->originInvoice?->invoice_no ?? $invoice->config_snapshot['origin_invoice_no'] ?? ''),
                'remark' => $remark !== '' ? $remark : '退款冲抵',
                'fields' => [
                    ['label' => '原账单', 'value' => (string) ($invoice->originInvoice?->invoice_no ?? $invoice->config_snapshot['origin_invoice_no'] ?? '--')],
                    ['label' => '退款金额', 'value' => number_format((float) $invoice->amount, 2, '.', '')],
                    ['label' => '退款时间', 'value' => $invoice->paid_at?->format('Y-m-d H:i:s') ?: '--'],
                ],
                'items' => [[
                    'description' => '退款冲抵',
                    'amount' => $invoice->amount,
                ]],
            ],
            InvoiceType::REFERRAL_CREDIT => [
                'kind' => 'referral_credit',
                'headline' => '推荐奖励账单',
                'subheadline' => '推荐返利结算到账后生成，金额通常直接入账到余额。',
                'badge' => '推荐奖励',
                'highlight' => $remark !== '' ? $remark : '推广返利入账',
                'remark' => $remark !== '' ? $remark : '推荐奖励已发放',
                'fields' => [
                    ['label' => '奖励类型', 'value' => '推荐返利'],
                    ['label' => '奖励金额', 'value' => number_format((float) $invoice->amount, 2, '.', '')],
                    ['label' => '到账状态', 'value' => InvoiceStatus::$labels[$invoice->status] ?? (string) $invoice->status],
                    ['label' => '入账时间', 'value' => $invoice->paid_at?->format('Y-m-d H:i:s') ?: '--'],
                ],
                'items' => [[
                    'description' => '推荐奖励入账',
                    'amount' => $invoice->amount,
                ]],
            ],
            InvoiceType::MANUAL => [
                'kind' => 'manual',
                'headline' => '手工账单',
                'subheadline' => '后台人工创建或修正的账单。',
                'badge' => '手工',
                'highlight' => $remark !== '' ? $remark : '人工账单',
                'remark' => $remark !== '' ? $remark : '后台手工处理',
                'fields' => [
                    ['label' => '处理方式', 'value' => '后台人工'],
                    ['label' => '账单金额', 'value' => number_format((float) $invoice->amount, 2, '.', '')],
                    ['label' => '账单状态', 'value' => InvoiceStatus::$labels[$invoice->status] ?? (string) $invoice->status],
                ],
                'items' => [[
                    'description' => $remark !== '' ? $remark : '后台手工账单',
                    'amount' => $invoice->amount,
                ]],
            ],
            default => [
                'kind' => 'default',
                'headline' => '账单详情',
                'subheadline' => '',
                'badge' => $this->resolveInvoiceTypeLabel((string) $invoice->type, $invoice),
                'highlight' => $remark !== '' ? $remark : '',
                'remark' => $remark,
                'fields' => $this->buildPurchaseLikeSceneFields($invoice, $paymentSummary),
            ],
        };
    }

    private function buildPurchaseLikeSceneFields(Invoice $invoice, ?array $paymentSummary): array
    {
        return array_values(array_filter([
            ['label' => '账单编号', 'value' => (string) $invoice->invoice_no],
            ['label' => '订单编号', 'value' => (string) ($invoice->order?->order_no ?? '')],
            ['label' => '配置名称', 'value' => $this->resolveInvoiceProductDisplayName($invoice)],
            ['label' => '计费周期', 'value' => trim((string) ($invoice->billing_cycle ?? $invoice->order?->billing_cycle ?? ''))],
            ['label' => '账单金额', 'value' => number_format((float) $invoice->amount, 2, '.', '')],
            ['label' => '已付金额', 'value' => number_format((float) ($invoice->paid_amount ?? 0), 2, '.', '')],
            ['label' => '应付金额', 'value' => number_format(max((float) $invoice->amount - (float) ($invoice->paid_amount ?? 0), 0), 2, '.', '')],
            ['label' => '数量', 'value' => (string) (int) ($invoice->quantity ?? 1)],
            ['label' => '支付方式', 'value' => (string) ($paymentSummary['gateway_label'] ?? '')],
            ['label' => '支付状态', 'value' => (string) ($paymentSummary['status_label'] ?? '')],
            ['label' => '优惠抵扣', 'value' => number_format((float) ($invoice->discount ?? 0), 2, '.', '')],
        ], static fn (array $item): bool => trim((string) ($item['value'] ?? '')) !== ''));
    }

    private function resolveInvoicePaymentSummary(Invoice $invoice): ?array
    {
        if (! $invoice->relationLoaded('payments')) {
            $invoice->loadMissing(['payments' => fn ($query) => $query->orderByDesc('id')]);
        }

        $payment = $this->resolvePrimaryInvoicePayment($invoice->payments);

        if (! $payment instanceof Payment) {
            return null;
        }

        $refund = (array) data_get((array) ($payment->callback_raw ?? []), 'refund', []);

        return [
            'id' => (int) $payment->id,
            'payment_no' => (string) $payment->payment_no,
            'trade_no' => (string) ($payment->trade_no ?? ''),
            'gateway' => $this->paymentGatewayKey($payment),
            'gateway_key' => $this->paymentGatewayKey($payment),
            'gateway_label' => $this->resolvePaymentGatewayLabel($this->paymentGatewayKey($payment)),
            'amount' => number_format((float) $payment->amount, 2, '.', ''),
            'status' => (int) $payment->status,
            'status_label' => $this->resolvePaymentStatusLabel((int) $payment->status),
            'paid_at' => $payment->paid_at?->format('Y-m-d H:i:s'),
            'refund_method' => (string) ($refund['refund_method'] ?? ''),
            'refund_method_label' => (string) ($refund['refund_method_label'] ?? ''),
            'refund_reason' => (string) ($refund['refund_reason'] ?? ''),
            'refunded_at' => (string) ($refund['refunded_at'] ?? ($refund['gmt_refund_pay'] ?? '')),
        ];
    }

    private function resolvePrimaryInvoicePayment(iterable $payments): ?Payment
    {
        $collection = $this->thirdPartyPayments($payments);

        return $collection
            ->first(fn (Payment $payment) => ! (bool) data_get((array) ($payment->callback_raw ?? []), 'duplicate_paid', false)
                && in_array((int) $payment->status, [PaymentStatus::SUCCESS, PaymentStatus::REFUNDED], true))
            ?? $collection->first(fn (Payment $payment) => in_array((int) $payment->status, [PaymentStatus::SUCCESS, PaymentStatus::REFUNDED], true));
    }

    private function thirdPartyPayments(iterable $payments): Collection
    {
        return collect($payments)
            ->filter(fn (Payment $payment) => $payment->isThirdPartyGateway())
            ->values();
    }

    private function paymentGatewayKey(Payment $payment): string
    {
        return $payment->gatewayKey();
    }

    private function resolveInvoiceDisplayStatus(Invoice $invoice, ?array $paymentSummary): array
    {
        if (($paymentSummary['status'] ?? null) === PaymentStatus::REFUNDED || (int) $invoice->status === InvoiceStatus::REFUNDED) {
            return [
                'status' => InvoiceStatus::REFUNDED,
                'status_label' => '已退款',
            ];
        }

        return [
            'status' => (int) $invoice->status,
            'status_label' => InvoiceStatus::$labels[$invoice->status] ?? (string) $invoice->status,
        ];
    }

    private function resolveRefundScene(Invoice $invoice): ?array
    {
        $refundInfo = $this->extractRefundInfo($invoice);

        if ($refundInfo === null) {
            return null;
        }

        return [
            'kind' => 'refund',
            'headline' => '退款账单',
            'subheadline' => $refundInfo['subheadline'],
            'badge' => '退款',
            'highlight' => $refundInfo['highlight'],
            'remark' => $refundInfo['remark'],
            'fields' => array_values(array_filter([
                ['label' => '退款方式', 'value' => $refundInfo['method_label']],
                ['label' => '原支付金额', 'value' => number_format((float) $refundInfo['original_amount'], 2, '.', '')],
                ['label' => '退款金额', 'value' => number_format((float) $refundInfo['refund_amount'], 2, '.', '')],
                ['label' => '退款时间', 'value' => $refundInfo['refunded_at'] !== '' ? $refundInfo['refunded_at'] : '--'],
                ['label' => '退款原因', 'value' => $refundInfo['remark'] !== '' ? $refundInfo['remark'] : ''],
            ], static fn (array $item): bool => trim((string) ($item['value'] ?? '')) !== '')),
            'items' => [
                [
                    'description' => '原支付金额',
                    'amount' => $refundInfo['original_amount'],
                ],
                [
                    'description' => '退款金额',
                    'amount' => -1 * $refundInfo['refund_amount'],
                ],
            ],
        ];
    }

    private function extractRefundInfo(Invoice $invoice): ?array
    {
        $payment = null;
        if ($invoice->relationLoaded('payments')) {
            $payment = $this->thirdPartyPayments($invoice->payments)
                ->first(fn (Payment $item) => (int) $item->status === PaymentStatus::REFUNDED
                    || is_array(data_get((array) ($item->callback_raw ?? []), 'refund')));
        }

        if (! $payment instanceof Payment && (int) $invoice->status !== InvoiceStatus::REFUNDED) {
            return null;
        }

        $refund = (array) data_get((array) ($payment?->callback_raw ?? []), 'refund', []);
        $refundAmount = (float) ($refund['refund_amount'] ?? $payment?->amount ?? $invoice->amount ?? 0);
        $originalAmount = (float) ($payment?->amount ?? $invoice->amount ?? 0);
        $refundMethodLabel = trim((string) ($refund['refund_method_label'] ?? ''));

        if ($refundMethodLabel === '') {
            $refundMethod = trim((string) ($refund['refund_method'] ?? ''));
            $refundMethodLabel = match ($refundMethod) {
                'balance' => '退回余额',
                'original' => '原路退款',
                default => '已退款',
            };
        }

        $refundReason = trim((string) ($refund['refund_reason'] ?? ''));
        $refundedAt = trim((string) ($refund['refunded_at'] ?? ($refund['gmt_refund_pay'] ?? '')));

        return [
            'highlight' => $refundMethodLabel !== '' ? $refundMethodLabel : '已退款',
            'remark' => $refundReason,
            'subheadline' => $refundedAt !== '' ? "退款时间：{$refundedAt}" : '该账单已完成退款。',
            'refund_amount' => $refundAmount > 0 ? $refundAmount : $originalAmount,
            'original_amount' => $originalAmount,
            'refunded_at' => $refundedAt,
            'method_label' => $refundMethodLabel !== '' ? $refundMethodLabel : '已退款',
        ];
    }

    private function buildOrderBasedSceneItems(Invoice $invoice): array
    {
        $items = [];
        $productName = $this->resolveInvoiceProductDisplayName($invoice);
        $billingCycle = trim((string) ($invoice->billing_cycle ?? $invoice->order?->billing_cycle ?? ''));
        $grossAmount = (float) ($invoice->amount ?? 0) + (float) ($invoice->discount ?? 0);

        if ($productName !== '') {
            $items[] = [
                'description' => $billingCycle !== '' ? "{$productName} / {$billingCycle}" : $productName,
                'amount' => $grossAmount,
            ];
        }

        if ((float) ($invoice->discount ?? 0) > 0) {
            $items[] = [
                'description' => '优惠抵扣',
                'amount' => -1 * (float) $invoice->discount,
            ];
        }

        return $items;
    }

    private function resolveInvoiceSceneFallbackSubheadline(Invoice $invoice): string
    {
        return match (InvoiceType::normalize((string) $invoice->type)) {
            InvoiceType::RECHARGE => '该账单主要反映余额入账，不依赖订单或服务实例。',
            InvoiceType::DEDUCTION => '该账单主要反映余额扣减，不依赖订单或服务实例。',
            InvoiceType::REFUND => '该账单为退款红字凭证，用于冲抵原账单资金。',
            InvoiceType::REFERRAL_CREDIT => '该账单主要反映推荐奖励入账，不依赖订单或服务实例。',
            InvoiceType::MANUAL => '该账单由后台人工创建或修正，重点查看备注和支付方式。',
            default => '',
        };
    }

    private function resolveInvoiceTypeLabel(string $type, Invoice $invoice): string
    {
        return match (InvoiceType::normalize($type)) {
            InvoiceType::NEW_PURCHASE => '新购账单',
            InvoiceType::RENEW => '续费账单',
            InvoiceType::RECHARGE => '充值账单',
            InvoiceType::UPGRADE => '附加配置账单',
            InvoiceType::DEDUCTION => '扣款账单',
            InvoiceType::REFUND => '退款红字账单',
            InvoiceType::REFERRAL_CREDIT => '推荐奖励账单',
            InvoiceType::MANUAL => '手工账单',
            default => ($invoice->order?->display_product_name ?? '') !== '' ? '产品账单' : '普通账单',
        };
    }

    private function resolvePaymentGatewayLabel(string $gateway): string
    {
        return match ($gateway) {
            PaymentGatewayCode::ALIPAY => PaymentGatewayCode::label(PaymentGatewayCode::ALIPAY),
            PaymentGatewayCode::YIPAY => PaymentGatewayCode::label(PaymentGatewayCode::YIPAY),
            'wechat' => '微信支付',
            'balance' => '余额支付',
            'free' => '免支付',
            'manual' => '手动入账',
            'bank_transfer' => '银行转账',
            'offline' => '线下支付',
            default => $gateway !== '' ? $gateway : '-',
        };
    }

    private function resolvePaymentStatusLabel(int $status): string
    {
        return match ($status) {
            PaymentStatus::SUCCESS => '已支付',
            PaymentStatus::FAILED => '失败',
            PaymentStatus::REFUNDED => '已退款',
            PaymentStatus::CANCELLED => '已取消',
            default => '未支付',
        };
    }

    private function buildInvoiceLogs(Invoice $invoice, array $scene): array
    {
        if (in_array((string) $invoice->type, [InvoiceType::RECHARGE, InvoiceType::DEDUCTION, InvoiceType::REFERRAL_CREDIT], true)) {
            return [];
        }

        return is_array($scene['logs'] ?? null) ? $scene['logs'] : $this->resolveInvoiceLogs($invoice);
    }

    public function syncProjection(Invoice $invoice): Invoice
    {
        $invoice->syncInvoiceItemProjection();

        return $invoice->fresh([
            'items',
            'order',
            'product:id,product_type,service_type_code,product_group_id,remark,config_options,purchase_requires',
        ]) ?? $invoice;
    }

    private function resolveInvoiceProductDisplayName(Invoice $invoice): string
    {
        $orderDisplayName = trim((string) ($invoice->order?->display_product_name ?? ''));
        if ($orderDisplayName !== '') {
            return $orderDisplayName;
        }

        $snapshotDisplayName = trim((string) ($invoice->display_product_name ?? ''));
        if ($snapshotDisplayName !== '') {
            return $snapshotDisplayName;
        }

        if ($invoice->product instanceof Product) {
            $resolved = $this->resolveProductDisplayNameResolver()->resolveForProduct(
                $invoice->product,
                (array) ($invoice->config_snapshot ?? [])
            );
            $productDisplayName = trim((string) ($resolved['product_spec_display'] ?? $resolved['product_display_name'] ?? ''));
            if ($productDisplayName !== '') {
                return $productDisplayName;
            }
        }

        return $this->resolveInvoiceTypeLabel((string) $invoice->type, $invoice);
    }

    private function resolveOrderProductName(Order $order): string
    {
        $snapshot = trim((string) ($order->display_product_name ?? $order->product_spec_snapshot ?? ''));
        if ($snapshot !== '' && $snapshot !== '未配置规格') {
            return $snapshot;
        }

        if ($order->product instanceof Product) {
            $resolved = $this->resolveProductDisplayNameResolver()->resolveForProduct(
                $order->product,
                (array) ($order->config_snapshot ?? [])
            );
            $name = trim((string) ($resolved['combined_display_name'] ?? $resolved['product_spec_display'] ?? $resolved['product_display_name'] ?? ''));
            if ($name !== '') {
                return $name;
            }
        }

        $productId = (int) ($order->product_id ?? 0);

        return $productId > 0 ? "产品 #{$productId}" : '未配置产品';
    }

    private function resolveOrderProductPath(Order $order): string
    {
        $product = $order->product;
        $thirdGroup = $product instanceof Product && $product->relationLoaded('productGroup')
            ? $product->productGroup
            : null;
        $secondGroup = $thirdGroup?->relationLoaded('secondProductGroup')
            ? $thirdGroup->secondProductGroup
            : null;
        $firstGroup = $secondGroup?->relationLoaded('firstProductGroup')
            ? $secondGroup->firstProductGroup
            : null;
        $productType = trim((string) ($order->product_type_snapshot ?? $product?->product_type ?? $product?->service_type_code ?? ''));
        $productType = $productType !== ''
            ? ProductType::normalizeBusinessValue($productType)
            : ProductType::businessValueForFirstGroup($firstGroup, $firstGroup?->code);

        $segments = [
            ProductType::businessLabelOf($productType),
            trim((string) ($secondGroup?->name ?? '')),
            trim((string) ($thirdGroup?->name ?? '')),
            $this->resolveOrderProductName($order),
        ];

        $clean = [];
        foreach ($segments as $segment) {
            $segment = trim((string) $segment);
            if ($segment === '' || $segment === '-' || in_array($segment, $clean, true)) {
                continue;
            }
            $clean[] = $segment;
        }

        return $clean !== [] ? implode('/', $clean) : $this->resolveOrderProductName($order);
    }

    private function resolveInvoiceProductSpecDisplay(Invoice $invoice, array $scene, string $productDisplayName): string
    {
        $standaloneSpecText = $this->resolveStandaloneInvoiceSpecText((string) $invoice->type);
        if ($standaloneSpecText !== '') {
            return $standaloneSpecText;
        }

        $snapshot = trim((string) ($invoice->product_spec_snapshot ?? ''));
        if ($snapshot !== '') {
            return $snapshot;
        }

        if ($productDisplayName !== '') {
            return $productDisplayName;
        }

        $subheadline = trim((string) ($scene['subheadline'] ?? ''));
        if ($subheadline !== '') {
            return $subheadline;
        }

        return $this->resolveInvoiceTypeLabel((string) $invoice->type, $invoice);
    }

    private function resolveStandaloneInvoiceSpecText(string $type): string
    {
        return match (InvoiceType::normalize($type)) {
            InvoiceType::RECHARGE => '余额充值',
            InvoiceType::UPGRADE => '附加配置',
            InvoiceType::DEDUCTION => '余额扣减',
            InvoiceType::REFERRAL_CREDIT => '推荐返利',
            InvoiceType::MANUAL => '后台人工',
            default => '',
        };
    }

    private function resolveInvoiceCombinedDisplayName(Invoice $invoice, string $fallbackDisplayName = ''): string
    {
        $product = $invoice->product instanceof Product
            ? $invoice->product
            : ($invoice->order?->product instanceof Product ? $invoice->order->product : null);
        if (! $product instanceof Product) {
            return $fallbackDisplayName !== ''
                ? $fallbackDisplayName
                : $this->resolveInvoiceTypeLabel((string) $invoice->type, $invoice);
        }

        $resolved = $this->resolveProductDisplayNameResolver()->resolveForProduct(
            $product,
            (array) ($invoice->config_snapshot ?? [])
        );

        $combinedDisplayName = trim((string) ($resolved['combined_display_name'] ?? ''));

        return $combinedDisplayName !== '' ? $combinedDisplayName : $fallbackDisplayName;
    }

    private function resolveProductDisplayNameResolver(): ProductDisplayNameResolver
    {
        return $this->productDisplayNameResolver ?? new ProductDisplayNameResolver;
    }

    public function markPaidManually(Invoice $invoice, array $payload, array $context = []): Invoice
    {
        $paidAt = ! empty($payload['paid_at'])
            ? Carbon::parse((string) $payload['paid_at'])
            : now();
        $requestedAmount = round((float) ($payload['amount'] ?? $invoice->amount), 2);
        $sendEmail = (bool) ($payload['send_email'] ?? false);
        $remark = trim((string) ($payload['remark'] ?? ''));
        $syncBusinessFlow = (bool) ($payload['sync_business_flow'] ?? false);
        $traceId = trim((string) ($context['trace_id'] ?? ''));

        $updatedInvoice = DB::transaction(function () use ($invoice, $paidAt, $requestedAmount, $traceId): Invoice {
            $lockedInvoice = Invoice::query()
                ->lockForUpdate()
                ->with('order')
                ->findOrFail((int) $invoice->id);

            throw_if(
                ! in_array((int) $lockedInvoice->status, [InvoiceStatus::UNPAID, InvoiceStatus::OVERDUE], true),
                new BusinessException('当前账单状态不支持手动入账')
            );

            $payableAmount = round((float) $lockedInvoice->amount - (float) ($lockedInvoice->paid_amount ?? 0), 2);
            throw_if($payableAmount <= 0, new BusinessException('当前账单无需再入账'));
            throw_if(abs($requestedAmount - $payableAmount) > 0.00001, new BusinessException('当前仅支持按账单应付金额全额入账'));

            Payment::query()
                ->where('invoice_id', $lockedInvoice->id)
                ->where('status', PaymentStatus::PENDING)
                ->lockForUpdate()
                ->get()
                ->each(function (Payment $payment) use ($traceId): void {
                    $callbackRaw = (array) ($payment->callback_raw ?? []);
                    $callbackRaw['closed_reason'] = 'invoice_paid_manually';
                    $callbackRaw['trace_id'] = $traceId !== '' ? $traceId : (string) ($payment->trace_id ?? '');

                    $payment->forceFill([
                        'status' => PaymentStatus::FAILED,
                        'callback_raw' => $callbackRaw,
                    ])->save();
                    app(PaymentService::class)->syncProjection($payment);
                });

            $lockedInvoice->forceFill([
                'status' => InvoiceStatus::PAID,
                'paid_amount' => $lockedInvoice->amount,
                'paid_at' => $paidAt,
                'trace_id' => $traceId !== '' ? $traceId : $lockedInvoice->trace_id,
            ])->save();

            $lockedInvoice->order?->forceFill([
                'status' => OrderStatus::PAID,
                'paid_amount' => $lockedInvoice->amount,
                'paid_at' => $paidAt,
            ])->save();

            return $lockedInvoice->fresh(['order', 'items', 'payments']) ?? $lockedInvoice;
        });

        if ($syncBusinessFlow) {
            app(PaymentService::class)->handlePaidInvoice($updatedInvoice, $traceId !== '' ? 'manual:'.$traceId : 'manual:invoice:'.$updatedInvoice->id);
        }

        app(OperationLogService::class)->write(
            userId: ((int) ($context['operator_id'] ?? 0)) ?: null,
            userType: 'admin',
            action: 'invoice.payment.mark_paid',
            module: 'invoice',
            targetId: (int) $updatedInvoice->id,
            detail: [
                'invoice_no' => (string) $updatedInvoice->invoice_no,
                'paid_amount' => number_format((float) $updatedInvoice->paid_amount, 2, '.', ''),
                'paid_at' => $paidAt->format('Y-m-d H:i:s'),
                'payment_gateway' => trim((string) ($payload['payment_gateway'] ?? 'manual')) ?: 'manual',
                'trade_no' => trim((string) ($payload['trade_no'] ?? '')),
                'send_email' => $sendEmail,
                'sync_business_flow' => $syncBusinessFlow,
                'remark' => $remark,
                'actor_name' => (string) ($context['operator_name'] ?? ''),
                'trace_id' => $traceId,
            ],
            ipAddress: (string) ($context['ip_address'] ?? '') ?: null,
        );

        return $updatedInvoice;
    }

    /**
     * 管理员原路退款（Invoice-first 入口）。
     */
    public function refundByPaymentMethod(Invoice $invoice, array $payload, array $context = []): array
    {
        $invoice->loadMissing('order');

        throw_if(
            ! $invoice->order instanceof Order,
            new BusinessException('账单未关联订单，暂不支持原路退款')
        );

        $result = app(PaymentService::class)->refundOrder($invoice->order, $payload, $context);

        if (($result['already_refunded'] ?? false) !== true) {
            $refund = (array) ($result['refund'] ?? []);

            app(OperationLogService::class)->write(
                userId: ((int) ($context['operator_id'] ?? 0)) ?: null,
                userType: 'admin',
                action: 'invoice.payment.refund',
                module: 'invoice',
                targetId: (int) $invoice->id,
                detail: [
                    'invoice_no' => (string) $invoice->invoice_no,
                    'order_id' => (int) ($invoice->order_id ?? 0),
                    'payment_id' => (int) ($result['payment_id'] ?? 0),
                    'refund_method' => (string) ($refund['refund_method'] ?? $payload['refund_method'] ?? 'original'),
                    'refund_method_label' => (string) ($refund['refund_method_label'] ?? ''),
                    'refund_amount' => (string) ($refund['refund_amount'] ?? $payload['amount'] ?? ''),
                    'refund_reason' => (string) ($refund['refund_reason'] ?? $payload['remark'] ?? ''),
                    'out_request_no' => (string) ($refund['out_request_no'] ?? ''),
                    'trade_no' => (string) ($refund['trade_no'] ?? ''),
                    'actor_name' => (string) ($context['operator_name'] ?? ''),
                    'trace_id' => (string) ($context['trace_id'] ?? ''),
                ],
                ipAddress: (string) ($context['ip_address'] ?? '') ?: null,
            );
        }

        return array_merge($result, [
            'invoice_id' => (int) $invoice->id,
        ]);

    }

    private function resolveInvoiceLogs(Invoice $invoice): array
    {
        if (! $invoice->order_id) {
            return [];
        }

        return OperationLog::query()
            ->where('module', 'order')
            ->where('subject_id', $invoice->order_id)
            ->orderByDesc('id')
            ->limit(50)
            ->get()
            ->map(fn (OperationLog $log) => [
                'id' => (int) $log->id,
                'created_at' => $log->created_at?->format('Y-m-d H:i:s'),
                'action' => $log->action,
                'summary' => $this->stringifyOperationDetail((array) ($log->detail ?? [])),
                'tone' => $this->resolveLogTone((string) $log->action),
            ])
            ->values()
            ->all();
    }

    private function resolveLogTone(string $action): string
    {
        return match (true) {
            str_contains($action, 'refund') => 'danger',
            str_contains($action, 'cancel') => 'warning',
            str_contains($action, 'paid') => 'success',
            default => 'info',
        };
    }

    private function stringifyOperationDetail(array $detail): string
    {
        if ($detail === []) {
            return '-';
        }

        $pairs = [];

        foreach ($detail as $key => $value) {
            if (is_array($value)) {
                $value = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            } elseif (is_bool($value)) {
                $value = $value ? 'true' : 'false';
            } elseif ($value === null) {
                $value = '';
            }

            $pairs[] = "{$key}: {$value}";
        }

        return implode(' | ', $pairs);
    }
}
