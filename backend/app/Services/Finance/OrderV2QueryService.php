<?php

declare(strict_types=1);

namespace App\Services\Finance;

use App\Models\Order;
use App\Models\Payment;
use App\Models\Product;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;

class OrderV2QueryService
{
    /**
     * @param  array<string, mixed>  $filters
     */
    public function paginateAdminOrders(array $filters): LengthAwarePaginator
    {
        $query = Order::query()
            ->select([
                'id',
                'order_no',
                'user_id',
                'product_id',
                'service_id',
                'type',
                'status',
                'amount',
                'discount',
                'paid_amount',
                'billing_cycle',
                'quantity',
                'paid_at',
                'created_at',
            ])
            ->with([
                'user:id,email,nickname,phone',
                'invoice:id,invoice_no,order_id,status,amount,paid_amount,paid_at',
                'product' => fn ($query) => $query->select($this->productProjectionColumns()),
                'product.productGroup:id,second_product_group_id,name',
                'product.productGroup.secondProductGroup:id,first_product_group_id,name',
                'product.productGroup.secondProductGroup.firstProductGroup:id,code,name',
                'service:id,name,domain,status,expires_at',
            ]);

        $this->applyFilters($query, $filters);

        return $query
            ->orderByDesc('id')
            ->paginate($this->pageSize($filters));
    }

    public function findAdminOrder(int $id): Order
    {
        return Order::query()
            ->select([
                'id',
                'order_no',
                'user_id',
                'product_id',
                'service_id',
                'coupon_id',
                'coupon_code',
                'type',
                'status',
                'amount',
                'discount',
                'paid_amount',
                'billing_cycle',
                'quantity',
                'config_snapshot',
                'config_pricing_snapshot',
                'coupon_snapshot',
                'service_snapshot',
                'paid_at',
                'remark',
                'trace_id',
                'created_at',
                'updated_at',
            ])
            ->with([
                'user:id,email,nickname,phone',
                'invoice:id,invoice_no,order_id,type,status,amount,paid_amount,paid_at,due_date,created_at,trace_id,refund_trace_id',
                'invoice.payments' => fn ($query) => $query
                    ->select(Payment::gatewayProjectionColumns([
                        'id',
                        'invoice_id',
                        'order_id',
                        'payment_no',
                        'plugin_id',
                        'trade_no',
                        'amount',
                        'status',
                        'paid_at',
                        'trace_id',
                        'created_at',
                    ]))
                    ->orderByDesc('id'),
                'product' => fn ($query) => $query->select($this->productProjectionColumns()),
                'product.productGroup:id,second_product_group_id,name',
                'product.productGroup.secondProductGroup:id,first_product_group_id,name',
                'product.productGroup.secondProductGroup.firstProductGroup:id,code,name',
                'service:id,name,domain,status,expires_at',
                'coupon:id,code,name,type,value',
            ])
            ->findOrFail($id);
    }

    /**
     * @param  array<string, mixed>  $filters
     */
    private function applyFilters(Builder $query, array $filters): void
    {
        $type = trim((string) ($filters['type'] ?? ''));
        if ($type !== '') {
            $query->where('type', $type);
        }

        if (isset($filters['status']) && $filters['status'] !== '') {
            $query->where('status', (int) $filters['status']);
        }

        $this->applyKeywordFilter($query, trim((string) ($filters['keyword'] ?? '')));
        $this->applyDateFilter($query, 'created_at', $filters);
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

    /**
     * @param  array<string, mixed>  $filters
     */
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

    /**
     * @param  array<string, mixed>  $filters
     */
    private function pageSize(array $filters): int
    {
        return max(1, min((int) ($filters['page_size'] ?? 20), 100));
    }

    /**
     * @return list<string>
     */
    private function productProjectionColumns(): array
    {
        return array_values(array_unique(array_merge(
            ['id'],
            Product::optionalSelectColumns([
                'product_type',
                'service_type_code',
                'product_group_id',
                'custom_display_name',
                'remark',
                'config_options',
                'purchase_requires',
            ])
        )));
    }
}
