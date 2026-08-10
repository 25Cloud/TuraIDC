<?php

declare(strict_types=1);

namespace App\Services\Finance;

use App\Constants\InvoiceStatus;
use App\Constants\OrderStatus;
use App\Models\Invoice;
use App\Support\OrderInvoiceNoGenerator;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;

class InvoiceOrderReconciliationService
{
    private const PAID_ORDER_STATUSES = [
        OrderStatus::PAID,
        OrderStatus::PROCESSING,
        OrderStatus::COMPLETED,
    ];

    /**
     * @return array<string,mixed>
     */
    public function inspect(int $sampleLimit = 20): array
    {
        $invalidInvoices = $this->invalidInvoices($sampleLimit);
        $ordersWithoutInvoice = $this->ordersWithoutInvoice($sampleLimit);
        $statusMismatches = $this->statusMismatches($sampleLimit);

        return [
            'dry_run' => true,
            'checked_at' => now()->toDateTimeString(),
            'summary' => [
                'invoices_invalid_order' => $this->invalidInvoicesCount(),
                'orders_without_invoice' => $this->ordersWithoutInvoiceCount(),
                'paid_order_invoice_status_mismatch' => $this->statusMismatchesCount(),
            ],
            'samples' => [
                'invoices_invalid_order' => $invalidInvoices->map(fn ($row) => $this->invalidInvoicePayload($row))->values()->all(),
                'orders_without_invoice' => $ordersWithoutInvoice->map(fn ($row) => $this->orderWithoutInvoicePayload($row))->values()->all(),
                'paid_order_invoice_status_mismatch' => $statusMismatches->map(fn ($row) => $this->statusMismatchPayload($row))->values()->all(),
            ],
        ];
    }

    /**
     * @return array<string,mixed>
     */
    public function reconcile(?string $snapshotDir = null): array
    {
        $before = $this->inspect(2000);
        $snapshotPath = $this->writeSnapshot($before, $snapshotDir);
        $changes = [
            'invoices_invalid_order_repaired' => 0,
            'orders_without_invoice_repaired' => 0,
            'paid_order_invoice_status_mismatch_repaired' => 0,
        ];

        DB::transaction(function () use (&$changes): void {
            foreach ($this->invalidInvoices(null) as $invoice) {
                $changes['invoices_invalid_order_repaired'] += $this->repairInvalidInvoiceOrder($invoice);
            }

            foreach ($this->ordersWithoutInvoice(null) as $order) {
                $changes['orders_without_invoice_repaired'] += $this->repairOrderWithoutInvoice($order);
            }

            foreach ($this->statusMismatches(null) as $pair) {
                $changes['paid_order_invoice_status_mismatch_repaired'] += $this->repairStatusMismatch($pair);
            }
        });

        $after = $this->inspect(200);

        return [
            'dry_run' => false,
            'checked_at' => now()->toDateTimeString(),
            'snapshot_path' => $snapshotPath,
            'before' => $before['summary'],
            'changes' => $changes,
            'after' => $after['summary'],
        ];
    }

    private function invalidInvoicesCount(): int
    {
        return (int) $this->invalidInvoicesQuery()->count();
    }

    private function ordersWithoutInvoiceCount(): int
    {
        return (int) $this->ordersWithoutInvoiceQuery()->count();
    }

    private function statusMismatchesCount(): int
    {
        return (int) $this->statusMismatchesQuery()->count();
    }

    /**
     * @return Collection<int,object>
     */
    private function invalidInvoices(?int $limit): Collection
    {
        $query = $this->invalidInvoicesQuery()
            ->select([
                'i.id as invoice_id',
                'i.invoice_no',
                'i.user_id',
                'i.order_id',
                'i.status as invoice_status',
                'i.amount',
                'i.paid_amount',
                'i.paid_at',
                'i.created_at',
            ])
            ->orderBy('i.id');

        if ($limit !== null) {
            $query->limit($limit);
        }

        return $query->get();
    }

    /**
     * @return Collection<int,object>
     */
    private function ordersWithoutInvoice(?int $limit): Collection
    {
        $query = $this->ordersWithoutInvoiceQuery()
            ->select([
                'o.*',
                'o.id as order_id',
                'o.status as order_status',
            ])
            ->orderBy('o.id');

        if ($limit !== null) {
            $query->limit($limit);
        }

        return $query->get();
    }

    /**
     * @return Collection<int,object>
     */
    private function statusMismatches(?int $limit): Collection
    {
        $query = $this->statusMismatchesQuery()
            ->select([
                'o.id as order_id',
                'o.order_no',
                'o.status as order_status',
                'o.paid_amount as order_paid_amount',
                'o.paid_at as order_paid_at',
                'i.id as invoice_id',
                'i.invoice_no',
                'i.status as invoice_status',
                'i.amount as invoice_amount',
                'i.paid_amount as invoice_paid_amount',
                'i.paid_at as invoice_paid_at',
            ])
            ->orderBy('o.id')
            ->orderBy('i.id');

        if ($limit !== null) {
            $query->limit($limit);
        }

        return $query->get();
    }

    private function invalidInvoicesQuery()
    {
        return DB::table('invoices as i')
            ->leftJoin('orders as o', 'o.id', '=', 'i.order_id')
            ->whereNotNull('i.order_id')
            ->whereNull('o.id');
    }

    private function ordersWithoutInvoiceQuery()
    {
        return DB::table('orders as o')
            ->leftJoin('invoices as i', 'i.order_id', '=', 'o.id')
            ->whereNull('i.id');
    }

    private function statusMismatchesQuery()
    {
        return DB::table('orders as o')
            ->join('invoices as i', 'i.order_id', '=', 'o.id')
            ->where(function ($query): void {
                $query->where(function ($paidOrderQuery): void {
                    $paidOrderQuery
                        ->where('o.status', OrderStatus::PAID)
                        ->where('i.status', '<>', InvoiceStatus::PAID);
                })->orWhere(function ($paidInvoiceQuery): void {
                    $paidInvoiceQuery
                        ->where('i.status', InvoiceStatus::PAID)
                        ->whereNotIn('o.status', self::PAID_ORDER_STATUSES);
                });
            });
    }

    /**
     * @return array<string,mixed>
     */
    private function invalidInvoicePayload(object $invoice): array
    {
        $derivedOrderNo = OrderInvoiceNoGenerator::deriveOrderNoFromInvoiceNo((string) $invoice->invoice_no);
        $derivedOrder = $derivedOrderNo
            ? DB::table('orders')->where('order_no', $derivedOrderNo)->first(['id', 'order_no', 'user_id'])
            : null;
        $canBindDerivedOrder = $derivedOrder?->id !== null
            && (int) $derivedOrder->user_id === (int) $invoice->user_id
            && ! DB::table('invoices')->where('order_id', (int) $derivedOrder->id)->exists();

        return [
            'invoice_id' => (int) $invoice->invoice_id,
            'invoice_no' => (string) $invoice->invoice_no,
            'invalid_order_id' => (int) $invoice->order_id,
            'user_id' => (int) $invoice->user_id,
            'invoice_status' => (int) $invoice->invoice_status,
            'amount' => (string) $invoice->amount,
            'derived_order_no' => $derivedOrderNo,
            'recoverable_order_id' => $canBindDerivedOrder ? (int) $derivedOrder->id : null,
            'suggested_action' => $canBindDerivedOrder ? 'bind_derived_order' : 'clear_invalid_order_id',
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function orderWithoutInvoicePayload(object $order): array
    {
        $derivedInvoiceNo = OrderInvoiceNoGenerator::deriveInvoiceNoFromOrderNo((string) $order->order_no);
        $derivedInvoice = $derivedInvoiceNo
            ? DB::table('invoices')->where('invoice_no', $derivedInvoiceNo)->first(['id', 'invoice_no', 'order_id', 'user_id'])
            : null;
        $canBindDerivedInvoice = $derivedInvoice?->id !== null
            && $derivedInvoice->order_id === null
            && (int) $derivedInvoice->user_id === (int) $order->user_id;

        return [
            'order_id' => (int) $order->order_id,
            'order_no' => (string) $order->order_no,
            'user_id' => (int) $order->user_id,
            'order_status' => (int) $order->order_status,
            'type' => $order->type,
            'amount' => (string) $order->amount,
            'discount' => (string) ($order->discount ?? '0.00'),
            'paid_amount' => (string) ($order->paid_amount ?? '0.00'),
            'derived_invoice_no' => $derivedInvoiceNo,
            'recoverable_invoice_id' => $canBindDerivedInvoice ? (int) $derivedInvoice->id : null,
            'suggested_action' => $canBindDerivedInvoice ? 'bind_derived_invoice' : 'create_shadow_invoice',
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function statusMismatchPayload(object $pair): array
    {
        $invoicePaid = (int) $pair->invoice_status === InvoiceStatus::PAID;
        $orderStatus = (int) $pair->order_status;

        $suggestedAction = match (true) {
            $invoicePaid && $orderStatus === OrderStatus::REFUNDED => 'sync_invoice_status_from_refunded_order',
            $invoicePaid && $orderStatus === OrderStatus::CANCELLED => 'sync_invoice_status_from_cancelled_order',
            $invoicePaid => 'sync_order_status_from_paid_invoice',
            default => 'sync_invoice_status_from_paid_order',
        };

        return [
            'order_id' => (int) $pair->order_id,
            'order_no' => (string) $pair->order_no,
            'order_status' => (int) $pair->order_status,
            'invoice_id' => (int) $pair->invoice_id,
            'invoice_no' => (string) $pair->invoice_no,
            'invoice_status' => (int) $pair->invoice_status,
            'invoice_amount' => (string) $pair->invoice_amount,
            'suggested_action' => $suggestedAction,
        ];
    }

    private function repairInvalidInvoiceOrder(object $invoice): int
    {
        $payload = $this->invalidInvoicePayload($invoice);

        if ($payload['recoverable_order_id']) {
            DB::table('invoices')
                ->where('id', (int) $invoice->invoice_id)
                ->update([
                    'order_id' => (int) $payload['recoverable_order_id'],
                    'updated_at' => now(),
                ]);

            return 1;
        }

        DB::table('invoices')
            ->where('id', (int) $invoice->invoice_id)
            ->update([
                'order_id' => null,
                'updated_at' => now(),
            ]);

        return 1;
    }

    private function repairOrderWithoutInvoice(object $order): int
    {
        $payload = $this->orderWithoutInvoicePayload($order);

        if ($payload['recoverable_invoice_id']) {
            DB::table('invoices')
                ->where('id', (int) $payload['recoverable_invoice_id'])
                ->update([
                    'order_id' => (int) $order->order_id,
                    'updated_at' => now(),
                ]);

            return 1;
        }

        DB::table('invoices')->insert($this->filterColumns('invoices', $this->buildInvoiceRowFromOrder($order)));

        return 1;
    }

    private function repairStatusMismatch(object $pair): int
    {
        if ((int) $pair->invoice_status === InvoiceStatus::PAID) {
            if ((int) $pair->order_status === OrderStatus::REFUNDED) {
                DB::table('invoices')
                    ->where('id', (int) $pair->invoice_id)
                    ->update($this->filterColumns('invoices', [
                        'status' => InvoiceStatus::REFUNDED,
                        'refund_amount' => $this->positiveDecimal($pair->invoice_paid_amount ?? null)
                            ?? $this->positiveDecimal($pair->invoice_amount ?? null)
                            ?? '0.00',
                        'refunded_at' => now(),
                        'updated_at' => now(),
                    ]));

                return 1;
            }

            if ((int) $pair->order_status === OrderStatus::CANCELLED) {
                DB::table('invoices')
                    ->where('id', (int) $pair->invoice_id)
                    ->update($this->filterColumns('invoices', [
                        'status' => InvoiceStatus::CANCELLED,
                        'updated_at' => now(),
                    ]));

                return 1;
            }

            DB::table('orders')
                ->where('id', (int) $pair->order_id)
                ->update($this->filterColumns('orders', [
                    'status' => OrderStatus::PAID,
                    'paid_amount' => $this->positiveDecimal($pair->invoice_paid_amount ?? null)
                        ?? $this->positiveDecimal($pair->invoice_amount ?? null)
                        ?? '0.00',
                    'paid_at' => $pair->invoice_paid_at ?: now(),
                    'updated_at' => now(),
                ]));

            return 1;
        }

        DB::table('invoices')
            ->where('id', (int) $pair->invoice_id)
            ->update($this->filterColumns('invoices', [
                'status' => InvoiceStatus::PAID,
                'paid_amount' => $this->positiveDecimal($pair->order_paid_amount ?? null)
                    ?? $this->positiveDecimal($pair->invoice_amount ?? null)
                    ?? '0.00',
                'paid_at' => $pair->order_paid_at ?: now(),
                'updated_at' => now(),
            ]));

        return 1;
    }

    /**
     * @return array<string,mixed>
     */
    private function buildInvoiceRowFromOrder(object $order): array
    {
        $invoiceNo = OrderInvoiceNoGenerator::deriveInvoiceNoFromOrderNo((string) $order->order_no);
        if ($invoiceNo === null || DB::table('invoices')->where('invoice_no', $invoiceNo)->exists()) {
            $invoiceNo = Invoice::generateInvoiceNo();
        }

        $invoiceAmount = max(0, (float) $order->amount - (float) ($order->discount ?? 0));
        $status = $this->invoiceStatusForOrder((int) $order->order_status);
        $paidAmount = $status === InvoiceStatus::PAID
            ? ($this->positiveDecimal($order->paid_amount ?? null) ?? number_format($invoiceAmount, 2, '.', ''))
            : '0.00';
        $paidAt = $status === InvoiceStatus::PAID ? ($order->paid_at ?: now()) : null;
        $createdAt = $order->created_at ?: now();

        return [
            'invoice_no' => $invoiceNo,
            'user_id' => (int) $order->user_id,
            'order_id' => (int) $order->order_id,
            'product_id' => $order->product_id ?? null,
            'service_id' => $order->service_id ?? null,
            'coupon_id' => $order->coupon_id ?? null,
            'user_coupon_id' => $order->user_coupon_id ?? null,
            'coupon_code' => $order->coupon_code ?? null,
            'type' => $order->type === 'renew' ? 'renew' : 'normal',
            'amount' => number_format($invoiceAmount, 2, '.', ''),
            'discount' => number_format((float) ($order->discount ?? 0), 2, '.', ''),
            'paid_amount' => $paidAmount,
            'billing_cycle' => $order->billing_cycle ?? null,
            'quantity' => $order->quantity ?? 1,
            'product_spec_snapshot' => $order->product_spec_snapshot ?? null,
            'product_type_snapshot' => $order->product_type_snapshot ?? null,
            'product_snapshot_json' => $order->product_snapshot_json ?? null,
            'config_snapshot' => $order->config_snapshot ?? null,
            'config_pricing_snapshot' => $order->config_pricing_snapshot ?? null,
            'coupon_snapshot' => $order->coupon_snapshot ?? null,
            'status' => $status,
            'due_date' => $paidAt ?: Carbon::parse($createdAt)->addDays(7),
            'paid_at' => $paidAt,
            'trace_id' => $order->trace_id ?? null,
            'created_at' => $createdAt,
            'updated_at' => now(),
        ];
    }

    private function invoiceStatusForOrder(int $orderStatus): int
    {
        return match ($orderStatus) {
            OrderStatus::PAID, OrderStatus::PROCESSING, OrderStatus::COMPLETED => InvoiceStatus::PAID,
            OrderStatus::CANCELLED => InvoiceStatus::CANCELLED,
            OrderStatus::REFUNDED => InvoiceStatus::REFUNDED,
            default => InvoiceStatus::UNPAID,
        };
    }

    /**
     * @param  array<string,mixed>  $payload
     * @return array<string,mixed>
     */
    private function filterColumns(string $table, array $payload): array
    {
        return array_filter(
            $payload,
            static fn (string $column): bool => Schema::hasColumn($table, $column),
            ARRAY_FILTER_USE_KEY
        );
    }

    private function positiveDecimal(mixed $value): ?string
    {
        if ($value === null || (float) $value <= 0) {
            return null;
        }

        return number_format((float) $value, 2, '.', '');
    }

    private function writeSnapshot(array $before, ?string $snapshotDir): string
    {
        $invoiceIds = collect($before['samples'])
            ->flatten(1)
            ->pluck('invoice_id')
            ->filter()
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->values();
        $orderIds = collect($before['samples'])
            ->flatten(1)
            ->pluck('order_id')
            ->filter()
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->values();

        $dir = $snapshotDir ?: storage_path('app/invoice-order-reconciliations');
        File::ensureDirectoryExists($dir);

        $path = rtrim($dir, DIRECTORY_SEPARATOR)
            .DIRECTORY_SEPARATOR
            .'invoice_order_reconciliation_'.now()->format('Ymd_His').'.json';

        File::put($path, json_encode([
            'generated_at' => now()->toDateTimeString(),
            'before' => $before,
            'orders' => $orderIds->isEmpty()
                ? []
                : DB::table('orders')->whereIn('id', $orderIds)->get()->map(fn ($row) => (array) $row)->all(),
            'invoices' => $invoiceIds->isEmpty()
                ? []
                : DB::table('invoices')->whereIn('id', $invoiceIds)->get()->map(fn ($row) => (array) $row)->all(),
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT));

        return $path;
    }
}
