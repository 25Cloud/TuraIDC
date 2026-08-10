<?php

declare(strict_types=1);

namespace App\Services\Finance;

use App\Constants\InvoiceStatus;
use App\Constants\OrderStatus;
use App\Constants\OrderType;
use App\Constants\PaymentGatewayCode;
use App\Constants\PaymentStatus;
use App\Models\Invoice;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Product;
use App\Services\ProductCatalog\ProductDisplayNameResolver;
use App\Services\ProductCatalog\ProductFullPathResolver;
use App\Support\AdminPrivacy;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

class AdminFinanceQueryService
{
    private const UPGRADE_KIND_LABELS = [
        'traffic_package' => '流量包',
    ];

    public function __construct(
        private readonly ProductDisplayNameResolver $productDisplayNameResolver,
        private readonly ?ProductFullPathResolver $productFullPathResolver = null,
    ) {}

    public function paginateOrders(array $filters, int $perPage = 20, ?string $forcedType = null): LengthAwarePaginator
    {
        $query = Order::query()
            ->with([
                'user:id,email,nickname,phone',
                'invoice:id,invoice_no,order_id,type,status,amount,paid_amount,paid_at,due_date,created_at',
                'product:id,product_type,service_type_code,product_group_id,remark,config_options,purchase_requires',
                'product.productGroup:id,second_product_group_id,name',
                'product.productGroup.secondProductGroup:id,first_product_group_id,name',
                'product.productGroup.secondProductGroup.firstProductGroup:id,code,name',
                'service:id,name,domain,status,expires_at',
            ]);

        $type = $forcedType ?: trim((string) ($filters['type'] ?? ''));
        if ($type !== '') {
            $query->where('type', $type);
        }

        if (isset($filters['status']) && $filters['status'] !== '') {
            $query->where('status', (int) $filters['status']);
        }

        $this->applyKeywordFilter($query, trim((string) ($filters['keyword'] ?? '')));
        $this->applyDateFilter($query, 'created_at', $filters);

        $paginator = $query->orderByDesc('id')->paginate($perPage);
        $paginator->setCollection(
            $paginator->getCollection()->map(fn (Order $order) => $this->transformOrder($order))
        );

        return $paginator;
    }

    public function paginateRecharges(array $filters, int $perPage = 20): LengthAwarePaginator
    {
        $query = Payment::query()
            ->with([
                'user:id,email,nickname,phone',
                'invoice:id,invoice_no,order_id,user_id,type,status,amount,paid_amount,paid_at,due_date,created_at',
                'invoice.order:id,order_no,type,status',
                'order:id,order_no,type,status',
            ])
            ->whereGatewayKey(PaymentGatewayCode::ALIPAY);

        if (isset($filters['status']) && $filters['status'] !== '') {
            $query->where('status', (int) $filters['status']);
        }

        $keyword = trim((string) ($filters['keyword'] ?? ''));
        if ($keyword !== '') {
            $query->where(function (Builder $query) use ($keyword): void {
                $query->where('payment_no', 'like', "%{$keyword}%")
                    ->orWhere('trade_no', 'like', "%{$keyword}%")
                    ->orWhere('id', $keyword)
                    ->orWhereHas('user', function (Builder $userQuery) use ($keyword): void {
                        $userQuery->where('email', 'like', "%{$keyword}%")
                            ->orWhere('nickname', 'like', "%{$keyword}%")
                            ->orWhere('phone', 'like', "%{$keyword}%");
                    })
                    ->orWhereHas('invoice', function (Builder $invoiceQuery) use ($keyword): void {
                        $invoiceQuery->where('invoice_no', 'like', "%{$keyword}%");
                    })
                    ->orWhereHas('order', function (Builder $orderQuery) use ($keyword): void {
                        $orderQuery->where('order_no', 'like', "%{$keyword}%");
                    });
            });
        }

        $this->applyDateFilter($query, 'created_at', $filters);

        $paginator = $query->orderByDesc('id')->paginate($perPage);
        $paginator->setCollection(
            $paginator->getCollection()->map(fn (Payment $payment) => $this->transformRechargePayment($payment))
        );

        return $paginator;
    }

    public function dailyCustomerSummary(string $monthOrStartDate, ?string $endDate = null): array
    {
        [$start, $end, $month] = $this->reportRange($monthOrStartDate, $endDate);
        $days = $this->emptyDailyRows($start, $end);

        $this->mergeDailyCounts($days, 'new_customers', $this->countsByDay('users', 'created_at', $start, $end));
        $this->mergeDailyCounts($days, 'new_orders', $this->countsByDay('orders', 'created_at', $start, $end));
        $this->mergeDailyCounts($days, 'completed_orders', $this->countsByDay('orders', 'updated_at', $start, $end, ['status' => OrderStatus::COMPLETED]));
        $this->mergeDailyCounts($days, 'new_tickets', $this->countsByDay('tickets', 'created_at', $start, $end));
        $this->mergeDailyCounts($days, 'ticket_replies', $this->countsByDay('ticket_replies', 'created_at', $start, $end, ['is_staff' => 1]));
        $this->mergeDailyCounts($days, 'cancel_requests', $this->countsByDay('orders', 'updated_at', $start, $end, ['status' => OrderStatus::CANCELLED]));

        $list = array_values($days);

        return [
            'month' => $month,
            'start_date' => $start->format('Y-m-d'),
            'end_date' => $end->format('Y-m-d'),
            'summary' => [
                'new_customers' => array_sum(array_column($list, 'new_customers')),
                'new_orders' => array_sum(array_column($list, 'new_orders')),
                'completed_orders' => array_sum(array_column($list, 'completed_orders')),
                'new_tickets' => array_sum(array_column($list, 'new_tickets')),
                'ticket_replies' => array_sum(array_column($list, 'ticket_replies')),
                'cancel_requests' => array_sum(array_column($list, 'cancel_requests')),
            ],
            'list' => $list,
        ];
    }

    public function productIncomeSummary(string $monthOrStartDate, ?string $endDate = null): array
    {
        [$start, $end, $month] = $this->reportRange($monthOrStartDate, $endDate);

        $rows = Invoice::query()
            ->select([
                'product_id',
                'type',
                DB::raw('SUM(CASE WHEN paid_amount > 0 THEN paid_amount ELSE amount END) as income_amount'),
                DB::raw('SUM(COALESCE(quantity, 1)) as quantity_total'),
            ])
            ->where('status', InvoiceStatus::PAID)
            ->whereDoesntHave('payments', fn ($query) => $query
                ->whereGatewayKeyIn(PaymentGatewayCode::thirdPartyGateways())
                ->where('status', PaymentStatus::REFUNDED))
            ->whereNotNull('product_id')
            ->whereBetween('paid_at', [$start, $end])
            ->whereIn('type', ['new', 'normal', 'renew'])
            ->groupBy('product_id', 'type')
            ->get();

        $products = Product::query()
            ->whereIn('id', $rows->pluck('product_id')->filter()->unique()->values())
            ->get()
            ->keyBy('id');

        $grouped = [];
        foreach ($rows as $row) {
            $productId = (int) $row->product_id;
            $type = (string) $row->type;
            $bucket = in_array($type, ['new', 'normal'], true) ? 'new' : 'renew';

            if (! isset($grouped[$productId])) {
                $product = $products->get($productId);
                $grouped[$productId] = [
                    'product_id' => $productId,
                    'product_name' => $this->resolveProductName($product, $productId),
                    'product_type' => (string) ($product?->product_type ?? ''),
                    'new_income' => '0.00',
                    'new_quantity' => 0,
                    'renew_income' => '0.00',
                    'renew_quantity' => 0,
                    'total_amount' => '0.00',
                    'total_quantity' => 0,
                ];
            }

            $amount = round((float) $row->income_amount, 2);
            $quantity = (int) $row->quantity_total;
            $grouped[$productId]["{$bucket}_income"] = $this->money((float) $grouped[$productId]["{$bucket}_income"] + $amount);
            $grouped[$productId]["{$bucket}_quantity"] += $quantity;
            $grouped[$productId]['total_amount'] = $this->money((float) $grouped[$productId]['total_amount'] + $amount);
            $grouped[$productId]['total_quantity'] += $quantity;
        }

        $list = collect($grouped)
            ->sortByDesc(fn (array $item) => (float) $item['total_amount'])
            ->values()
            ->all();

        return [
            'month' => $month,
            'start_date' => $start->format('Y-m-d'),
            'end_date' => $end->format('Y-m-d'),
            'summary' => [
                'new_income' => $this->money(array_sum(array_map(fn ($item) => (float) $item['new_income'], $list))),
                'new_quantity' => array_sum(array_column($list, 'new_quantity')),
                'renew_income' => $this->money(array_sum(array_map(fn ($item) => (float) $item['renew_income'], $list))),
                'renew_quantity' => array_sum(array_column($list, 'renew_quantity')),
                'total_amount' => $this->money(array_sum(array_map(fn ($item) => (float) $item['total_amount'], $list))),
                'total_quantity' => array_sum(array_column($list, 'total_quantity')),
            ],
            'list' => $list,
        ];
    }

    public function paginateUpgradeOrders(array $filters, int $perPage = 20): LengthAwarePaginator
    {
        $query = Order::query()
            ->with([
                'user:id,email,nickname,phone',
                'invoice:id,invoice_no,order_id,type,status,amount,paid_amount,paid_at,due_date,created_at',
                'product:id,product_type,service_type_code,product_group_id,remark,config_options,purchase_requires',
                'service:id,name,domain,status,expires_at',
            ])
            ->where('type', OrderType::UPGRADE);

        $kind = trim((string) ($filters['upgrade_kind'] ?? ''));
        if ($kind !== '' && $kind !== 'all') {
            $query->where('config_pricing_snapshot->meta->kind', $kind);
        }

        if (isset($filters['status']) && $filters['status'] !== '') {
            $query->where('status', (int) $filters['status']);
        }

        $this->applyKeywordFilter($query, trim((string) ($filters['keyword'] ?? '')));
        $this->applyDateFilter($query, 'created_at', $filters);

        $paginator = $query->orderByDesc('id')->paginate($perPage);
        $paginator->setCollection(
            $paginator->getCollection()->map(function (Order $order): array {
                $item = $this->transformOrder($order);
                $kind = $this->resolveUpgradeKind($order);
                $item['upgrade_kind'] = $kind;
                $item['upgrade_kind_label'] = self::UPGRADE_KIND_LABELS[$kind] ?? ($kind !== '' ? $kind : '附加配置');
                $item['upgrade_target_label'] = (string) data_get($order->config_pricing_snapshot ?? [], 'meta.target_label', '');
                $item['upgrade_mode'] = (string) data_get($order->config_pricing_snapshot ?? [], 'meta.mode', '');

                return $item;
            })
        );

        return $paginator;
    }

    /**
     * @return array{0: CarbonImmutable, 1: CarbonImmutable}
     */
    private function monthRange(string $month): array
    {
        $date = CarbonImmutable::createFromFormat('Y-m', $month) ?: CarbonImmutable::now();
        $start = $date->startOfMonth()->startOfDay();

        return [$start, $start->endOfMonth()->endOfDay()];
    }

    /**
     * @return array{0: CarbonImmutable, 1: CarbonImmutable, 2: string|null}
     */
    private function reportRange(string $monthOrStartDate, ?string $endDate = null): array
    {
        if ($endDate !== null) {
            $start = CarbonImmutable::parse($monthOrStartDate)->startOfDay();
            $end = CarbonImmutable::parse($endDate)->endOfDay();

            return [
                $start,
                $end,
                $start->format('Y-m') === $end->format('Y-m') ? $start->format('Y-m') : null,
            ];
        }

        [$start, $end] = $this->monthRange($monthOrStartDate);

        return [$start, $end, $monthOrStartDate];
    }

    private function emptyDailyRows(CarbonImmutable $start, CarbonImmutable $end): array
    {
        $rows = [];
        for ($date = $start; $date->lessThanOrEqualTo($end); $date = $date->addDay()) {
            $day = $date->format('Y-m-d');
            $rows[$day] = [
                'date' => $day,
                'new_customers' => 0,
                'new_orders' => 0,
                'completed_orders' => 0,
                'new_tickets' => 0,
                'ticket_replies' => 0,
                'cancel_requests' => 0,
            ];
        }

        return $rows;
    }

    private function countsByDay(string $table, string $column, CarbonImmutable $start, CarbonImmutable $end, array $conditions = []): array
    {
        $query = DB::table($table)
            ->selectRaw("DATE({$column}) as day, COUNT(*) as total")
            ->whereBetween($column, [$start, $end]);

        foreach ($conditions as $key => $value) {
            $query->where($key, $value);
        }

        return $query
            ->groupByRaw("DATE({$column})")
            ->pluck('total', 'day')
            ->map(fn ($value) => (int) $value)
            ->all();
    }

    private function mergeDailyCounts(array &$days, string $key, array $counts): void
    {
        foreach ($counts as $day => $total) {
            if (isset($days[$day])) {
                $days[$day][$key] = (int) $total;
            }
        }
    }

    private function applyKeywordFilter(Builder $query, string $keyword): void
    {
        if ($keyword === '') {
            return;
        }

        $query->where(function (Builder $query) use ($keyword): void {
            $query->where('order_no', 'like', "%{$keyword}%")
                ->orWhere('id', $keyword)
                ->orWhere('product_spec_snapshot', 'like', "%{$keyword}%")
                ->orWhereHas('user', function (Builder $userQuery) use ($keyword): void {
                    $userQuery->where('email', 'like', "%{$keyword}%")
                        ->orWhere('nickname', 'like', "%{$keyword}%")
                        ->orWhere('phone', 'like', "%{$keyword}%");
                })
                ->orWhereHas('invoice', function (Builder $invoiceQuery) use ($keyword): void {
                    $invoiceQuery->where('invoice_no', 'like', "%{$keyword}%");
                })
                ->orWhereHas('service', function (Builder $serviceQuery) use ($keyword): void {
                    $serviceQuery->where('name', 'like', "%{$keyword}%")
                        ->orWhere('domain', 'like', "%{$keyword}%");
                });
        });
    }

    private function applyDateFilter(Builder $query, string $column, array $filters): void
    {
        $start = trim((string) ($filters['start_date'] ?? ''));
        $end = trim((string) ($filters['end_date'] ?? ''));

        if ($start === '' && $end === '') {
            return;
        }

        if ($start !== '' && $end !== '') {
            $query->whereBetween($column, [
                CarbonImmutable::parse($start)->startOfDay(),
                CarbonImmutable::parse($end)->endOfDay(),
            ]);

            return;
        }

        if ($start !== '') {
            $query->where($column, '>=', CarbonImmutable::parse($start)->startOfDay());

            return;
        }

        $query->where($column, '<=', CarbonImmutable::parse($end)->endOfDay());
    }

    public function getOrderDetail(int $id): array
    {
        $order = Order::query()
            ->with([
                'user:id,email,nickname,phone',
                'invoice:id,invoice_no,order_id,type,status,amount,paid_amount,paid_at,due_date,created_at,trace_id,refund_trace_id',
                'invoice.payments' => fn ($query) => $query->select(Payment::gatewayProjectionColumns([
                    'id',
                    'invoice_id',
                    'payment_no',
                    'plugin_id',
                    'trade_no',
                    'amount',
                    'status',
                    'paid_at',
                    'trace_id',
                ])),
                'product:id,product_type,service_type_code,product_group_id,remark,config_options,purchase_requires',
                'product.productGroup:id,second_product_group_id,name',
                'product.productGroup.secondProductGroup:id,first_product_group_id,name',
                'product.productGroup.secondProductGroup.firstProductGroup:id,code,name',
                'service:id,name,domain,status,expires_at',
                'coupon:id,code,name,type,value',
            ])
            ->findOrFail($id);

        $data = $this->transformOrder($order);
        $data['coupon'] = $order->coupon ? [
            'id' => (int) $order->coupon->id,
            'code' => (string) $order->coupon->code,
            'name' => (string) ($order->coupon->name ?? ''),
            'type' => (string) ($order->coupon->type ?? ''),
            'value' => (string) ($order->coupon->value ?? ''),
        ] : null;
        $data['coupon_code'] = (string) ($order->coupon_code ?? '');
        $data['coupon_snapshot'] = (array) ($order->coupon_snapshot ?? []);
        $data['remark'] = (string) ($order->remark ?? '');
        $data['payments'] = $order->invoice?->payments
            ?->filter(fn (Payment $payment) => $payment->isThirdPartyGateway())
            ?->map(fn ($payment) => [
                'id' => (int) $payment->id,
                'payment_no' => (string) $payment->payment_no,
                'gateway' => $payment->gatewayKey(),
                'gateway_key' => $payment->gatewayKey(),
                'trade_no' => (string) ($payment->trade_no ?? ''),
                'amount' => $this->money($payment->amount),
                'status' => (int) $payment->status,
                'paid_at' => $payment->paid_at?->format('Y-m-d H:i:s'),
                'trace_id' => (string) ($payment->trace_id ?? ''),
            ])?->values()?->toArray() ?? [];

        return $data;
    }

    private function transformOrder(Order $order): array
    {
        $privacy = AdminPrivacy::current();

        return [
            'id' => (int) $order->id,
            'order_no' => (string) $order->order_no,
            'user_id' => (int) $order->user_id,
            'user' => $order->user ? [
                'id' => (int) $order->user->id,
                'email' => $privacy->email($order->user->email),
                'nickname' => (string) ($order->user->nickname ?? ''),
                'phone' => $privacy->phone($order->user->phone),
            ] : null,
            'invoice' => $order->invoice ? [
                'id' => (int) $order->invoice->id,
                'invoice_no' => (string) $order->invoice->invoice_no,
                'status' => (int) $order->invoice->status,
                'amount' => $this->money($order->invoice->amount),
                'paid_amount' => $this->money($order->invoice->paid_amount),
                'paid_at' => $order->invoice->paid_at?->format('Y-m-d H:i:s'),
                'trace_id' => (string) ($order->invoice->trace_id ?? ''),
                'refund_trace_id' => (string) ($order->invoice->refund_trace_id ?? ''),
            ] : null,
            'product_id' => (int) ($order->product_id ?? 0),
            'product_name' => $this->productFullPathResolver()->nameForOrder($order),
            'product_full_path' => $this->productFullPathResolver()->pathForOrder($order),
            'product_type' => (string) ($order->product_type_snapshot ?? $order->product?->product_type ?? ''),
            'service' => $order->service ? [
                'id' => (int) $order->service->id,
                'name' => (string) $order->service->name,
                'domain' => (string) ($order->service->domain ?? ''),
                'status' => (int) $order->service->status,
                'expires_at' => $order->service->expires_at?->format('Y-m-d H:i:s'),
            ] : null,
            'type' => (string) $order->type,
            'type_label' => $this->orderTypeLabel((string) $order->type),
            'status' => (int) $order->status,
            'status_label' => $this->orderStatusLabel((int) $order->status),
            'amount' => $this->money($order->amount),
            'discount' => $this->money($order->discount),
            'paid_amount' => $this->money($order->paid_amount),
            'billing_cycle' => (string) ($order->billing_cycle ?? ''),
            'quantity' => (int) ($order->quantity ?? 1),
            'paid_at' => $order->paid_at?->format('Y-m-d H:i:s'),
            'created_at' => $order->created_at?->format('Y-m-d H:i:s'),
            'updated_at' => $order->updated_at?->format('Y-m-d H:i:s'),
            'trace_id' => (string) ($order->trace_id ?? ''),
            'config_snapshot' => (array) ($order->config_snapshot ?? []),
            'config_pricing_snapshot' => (array) ($order->config_pricing_snapshot ?? []),
        ];
    }

    private function transformRechargePayment(Payment $payment): array
    {
        $privacy = AdminPrivacy::current();
        $invoice = $payment->invoice;
        $order = $payment->order ?? $invoice?->order;
        $gateway = $payment->gatewayKey();

        return [
            'id' => (int) $payment->id,
            'payment_no' => (string) $payment->payment_no,
            'gateway' => $gateway,
            'gateway_key' => $gateway,
            'gateway_label' => $this->paymentGatewayLabel($gateway),
            'trade_no' => (string) ($payment->trade_no ?? ''),
            'user' => $payment->user ? [
                'id' => (int) $payment->user->id,
                'email' => $privacy->email($payment->user->email),
                'nickname' => (string) ($payment->user->nickname ?? ''),
                'phone' => $privacy->phone($payment->user->phone),
            ] : null,
            'invoice_id' => $invoice ? (int) $invoice->id : null,
            'invoice_no' => $invoice ? (string) $invoice->invoice_no : '',
            'invoice' => $invoice ? [
                'id' => (int) $invoice->id,
                'invoice_no' => (string) $invoice->invoice_no,
                'type' => (string) $invoice->type,
                'status' => (int) $invoice->status,
                'status_label' => $this->invoiceStatusLabel((int) $invoice->status),
                'amount' => $this->money($invoice->amount),
                'paid_amount' => $this->money($invoice->paid_amount),
                'paid_at' => $invoice->paid_at?->format('Y-m-d H:i:s'),
            ] : null,
            'order' => $order ? [
                'id' => (int) $order->id,
                'order_no' => (string) $order->order_no,
                'type' => (string) $order->type,
                'status' => (int) $order->status,
            ] : null,
            'amount' => $this->money($payment->amount),
            'paid_amount' => (int) $payment->status === PaymentStatus::SUCCESS ? $this->money($payment->amount) : '0.00',
            'status' => (int) $payment->status,
            'status_label' => $this->paymentStatusLabel((int) $payment->status),
            'payment' => [
                'id' => (int) $payment->id,
                'payment_no' => (string) $payment->payment_no,
                'gateway' => $gateway,
                'gateway_key' => $gateway,
                'gateway_label' => $this->paymentGatewayLabel($gateway),
                'trade_no' => (string) ($payment->trade_no ?? ''),
                'amount' => $this->money($payment->amount),
                'status' => (int) $payment->status,
                'paid_at' => $payment->paid_at?->format('Y-m-d H:i:s'),
                'trace_id' => (string) ($payment->trace_id ?? ''),
            ],
            'paid_at' => $payment->paid_at?->format('Y-m-d H:i:s'),
            'created_at' => $payment->created_at?->format('Y-m-d H:i:s'),
        ];
    }

    private function resolveProductName(?Product $product, int $productId): string
    {
        if ($product instanceof Product) {
            $resolved = $this->productDisplayNameResolver->resolveForProduct($product);
            $name = trim((string) ($resolved['combined_display_name'] ?? $resolved['product_spec_display'] ?? $resolved['product_display_name'] ?? ''));
            if ($name !== '') {
                return $name;
            }
        }

        return $productId > 0 ? "产品 #{$productId}" : '未配置产品';
    }

    private function resolveUpgradeKind(Order $order): string
    {
        return trim((string) data_get($order->config_pricing_snapshot ?? [], 'meta.kind', ''));
    }

    private function orderTypeLabel(string $type): string
    {
        return match ($type) {
            OrderType::NEW, 'normal' => '新购',
            OrderType::RENEW => '续费',
            OrderType::UPGRADE => '附加配置',
            default => $type !== '' ? $type : '普通',
        };
    }

    private function orderStatusLabel(int $status): string
    {
        return match ($status) {
            OrderStatus::PENDING => '待付款',
            OrderStatus::PAID => '已付款',
            OrderStatus::PROCESSING => '开通中',
            OrderStatus::COMPLETED => '已完成',
            OrderStatus::CANCELLED => '已取消',
            OrderStatus::REFUNDED => '已退款',
            default => (string) $status,
        };
    }

    private function invoiceStatusLabel(int $status): string
    {
        return match ($status) {
            InvoiceStatus::UNPAID => '待支付',
            InvoiceStatus::PAID => '已支付',
            InvoiceStatus::CANCELLED => '已取消',
            InvoiceStatus::OVERDUE => '已逾期',
            InvoiceStatus::REFUNDED => '已退款',
            default => (string) $status,
        };
    }

    private function paymentStatusLabel(int $status): string
    {
        return match ($status) {
            PaymentStatus::PENDING => '待支付',
            PaymentStatus::SUCCESS => '已支付',
            PaymentStatus::FAILED => '已失败',
            PaymentStatus::REFUNDED => '已退款',
            PaymentStatus::CANCELLED => '已取消',
            default => (string) $status,
        };
    }

    private function paymentGatewayLabel(string $gateway): string
    {
        return match ($gateway) {
            PaymentGatewayCode::ALIPAY => '支付宝',
            PaymentGatewayCode::YIPAY => '易支付',
            default => $gateway !== '' ? $gateway : '-',
        };
    }

    private function money(mixed $value): string
    {
        return number_format((float) ($value ?? 0), 2, '.', '');
    }

    private function productFullPathResolver(): ProductFullPathResolver
    {
        return $this->productFullPathResolver ?? new ProductFullPathResolver($this->productDisplayNameResolver);
    }
}
