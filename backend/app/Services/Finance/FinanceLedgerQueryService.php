<?php

namespace App\Services\Finance;

use App\Constants\FinanceLedgerEventType;
use App\Constants\InvoiceStatus;
use App\Constants\InvoiceType;
use App\Constants\OrderStatus;
use App\Constants\PaymentStatus;
use App\Http\Resources\Finance\FinanceLedgerResource;
use App\Models\AccountTransaction;
use App\Models\Invoice;
use App\Models\OperationLog;
use App\Models\Order;
use App\Models\Payment;
use App\Models\PaymentCallback;
use App\Models\Service;
use App\Models\User;
use App\Services\Integrations\Plugins\PluginBindingResolver;
use App\Support\AdminPrivacy;
use App\Support\ServiceHostname;
use App\Support\VersionedJson;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;

class FinanceLedgerQueryService
{
    private const SUMMARY_CACHE_TTL_SECONDS = 30;

    public function paginateForClient(User $user, array $filters, int $perPage): array
    {
        $paginator = $this->buildQuery(array_merge($filters, ['user_id' => (int) $user->id]))->paginate($perPage);

        return [
            'list' => collect(FinanceLedgerResource::collection($paginator->items())->resolve())
                ->map(fn (array $item) => $this->sanitizeClientLedgerPayload($item))
                ->values()
                ->all(),
            'total' => $paginator->total(),
            'page' => $paginator->currentPage(),
            'page_size' => $paginator->perPage(),
        ];
    }

    public function paginateForAdmin(array $filters, int $perPage): array
    {
        $paginator = $this->buildQuery($filters)->paginate($perPage);

        return [
            'list' => FinanceLedgerResource::collection($paginator->items())->resolve(),
            'total' => $paginator->total(),
            'page' => $paginator->currentPage(),
            'page_size' => $paginator->perPage(),
        ];
    }

    public function paginatorForUser(User $user, array $filters, int $perPage): LengthAwarePaginator
    {
        return $this->buildQuery(array_merge($filters, ['user_id' => (int) $user->id]))->paginate($perPage);
    }

    public function detailForClient(User $user, int $ledgerId): array
    {
        $record = $this->buildQuery(['user_id' => (int) $user->id])
            ->where('account_transactions.id', $ledgerId)
            ->firstOrFail();

        return $this->buildClientDetailPayload($record);
    }

    public function detailForAdmin(int $ledgerId): array
    {
        $record = $this->buildQuery([])
            ->where('account_transactions.id', $ledgerId)
            ->firstOrFail();

        return $this->buildDetailPayload($record);
    }

    public function summaryForClient(User $user, array $filters): array
    {
        return $this->summary(array_merge($filters, ['user_id' => (int) $user->id]), $user);
    }

    public function summaryForAdmin(array $filters): array
    {
        return $this->summary($filters);
    }

    private function summary(array $filters, ?User $boundUser = null): array
    {
        $cacheKey = $this->buildSummaryCacheKey($filters);
        $cached = Cache::get($cacheKey);

        if (is_array($cached) && $cached !== []) {
            $balance = $boundUser?->balance;
            if ($balance === null && ! empty($filters['user_id'])) {
                $balance = optional(User::query()->find((int) $filters['user_id']))->balance;
            }
            $cached['cash_balance'] = number_format((float) ($balance ?? 0), 2, '.', '');
            unset($cached['balance']);

            return $cached;
        }

        $baseQuery = AccountTransaction::query()
            ->where('account_type', 'cash');

        $this->applyFilters($baseQuery, $filters);

        $normalizedEventSql = $this->normalizedEventTypeSql('account_transactions.event_type');
        $summaryRow = (clone $baseQuery)
            ->selectRaw('COALESCE(SUM(CASE WHEN change_amount > 0 THEN change_amount ELSE 0 END), 0) as total_in')
            ->selectRaw('COALESCE(SUM(CASE WHEN change_amount < 0 THEN ABS(change_amount) ELSE 0 END), 0) as total_out')
            ->selectRaw('COUNT(*) as total_count')
            ->selectRaw(
                "COALESCE(SUM(CASE WHEN {$normalizedEventSql} = ? THEN change_amount ELSE 0 END), 0) as recharge_in",
                array_merge($this->normalizedEventTypeBindings(), [FinanceLedgerEventType::RECHARGE])
            )
            ->selectRaw(
                "COALESCE(SUM(CASE WHEN {$normalizedEventSql} = ? THEN ABS(change_amount) ELSE 0 END), 0) as invoice_payment_out",
                array_merge($this->normalizedEventTypeBindings(), [FinanceLedgerEventType::INVOICE_PAYMENT])
            )
            ->selectRaw(
                "COALESCE(SUM(CASE WHEN {$normalizedEventSql} = ? THEN change_amount ELSE 0 END), 0) as refund_in",
                array_merge($this->normalizedEventTypeBindings(), [FinanceLedgerEventType::INVOICE_REFUND])
            )
            ->selectRaw(
                "COALESCE(SUM(CASE WHEN {$normalizedEventSql} IN (?, ?) THEN ABS(change_amount) ELSE 0 END), 0) as manual_adjust_out",
                array_merge(
                    $this->normalizedEventTypeBindings(),
                    [
                        FinanceLedgerEventType::MANUAL_DEDUCTION,
                        FinanceLedgerEventType::SYSTEM_ADJUSTMENT,
                    ]
                )
            )
            ->first();

        $invoiceQuery = Invoice::query();
        if (! empty($filters['user_id'])) {
            $invoiceQuery->where('user_id', (int) $filters['user_id']);
        }
        $this->applyDateFilter($invoiceQuery, $filters);
        $invoiceSummary = $invoiceQuery
            ->selectRaw('COUNT(*) as total_invoices')
            ->selectRaw('COALESCE(SUM(CASE WHEN status IN (0, 3) THEN amount - COALESCE(paid_amount, 0) ELSE 0 END), 0) as unpaid_amount')
            ->selectRaw('COALESCE(SUM(CASE WHEN status IN (0, 3) THEN 1 ELSE 0 END), 0) as unpaid_count')
            ->first();

        $recharge30Days = (clone $baseQuery)
            ->where('created_at', '>=', now()->subDays(30))
            ->whereRaw(
                $this->normalizedEventTypeSql('account_transactions.event_type').' = ?',
                array_merge($this->normalizedEventTypeBindings(), [FinanceLedgerEventType::RECHARGE])
            )
            ->sum('change_amount');

        $refund30Days = (clone $baseQuery)
            ->where('created_at', '>=', now()->subDays(30))
            ->whereRaw(
                $this->normalizedEventTypeSql('account_transactions.event_type').' = ?',
                array_merge($this->normalizedEventTypeBindings(), [FinanceLedgerEventType::INVOICE_REFUND])
            )
            ->sum('change_amount');

        $balance = $boundUser?->balance;
        if ($balance === null && ! empty($filters['user_id'])) {
            $balance = optional(User::query()->find((int) $filters['user_id']))->balance;
        }

        $result = [
            'cash_balance' => number_format((float) ($balance ?? 0), 2, '.', ''),
            'total_in' => number_format((float) ($summaryRow?->total_in ?? 0), 2, '.', ''),
            'total_out' => number_format((float) ($summaryRow?->total_out ?? 0), 2, '.', ''),
            'total_count' => (int) ($summaryRow?->total_count ?? 0),
            'recharge_in' => number_format((float) ($summaryRow?->recharge_in ?? 0), 2, '.', ''),
            'invoice_payment_out' => number_format((float) ($summaryRow?->invoice_payment_out ?? 0), 2, '.', ''),
            'refund_in' => number_format((float) ($summaryRow?->refund_in ?? 0), 2, '.', ''),
            'manual_adjust_out' => number_format((float) ($summaryRow?->manual_adjust_out ?? 0), 2, '.', ''),
            'unpaid_amount' => number_format((float) ($invoiceSummary?->unpaid_amount ?? 0), 2, '.', ''),
            'unpaid_count' => (int) ($invoiceSummary?->unpaid_count ?? 0),
            'total_invoices' => (int) ($invoiceSummary?->total_invoices ?? 0),
            'recent_30d_recharge' => number_format((float) $recharge30Days, 2, '.', ''),
            'recent_30d_refund' => number_format((float) $refund30Days, 2, '.', ''),
        ];

        Cache::put($cacheKey, $result, now()->addSeconds(self::SUMMARY_CACHE_TTL_SECONDS));

        return $result;
    }

    private function buildSummaryCacheKey(array $filters): string
    {
        $userId = (int) ($filters['user_id'] ?? 0);
        $dateRange = trim((string) ($filters['start_date'] ?? '')).'_'.trim((string) ($filters['end_date'] ?? ''));

        return 'finance:ledger:summary:'.$userId.':'.md5($dateRange);
    }

    private function buildQuery(array $filters, bool $withRelations = true): Builder
    {
        $query = AccountTransaction::query()
            ->where('account_type', 'cash')
            ->select('account_transactions.*')
            ->selectRaw(
                $this->normalizedEventTypeSql('account_transactions.event_type').' as normalized_event_type',
                $this->normalizedEventTypeBindings()
            );

        if ($withRelations) {
            $query->with(['user']);
        }

        $this->applyFilters($query, $filters);

        $rows = $query->orderByDesc('created_at')->orderByDesc('id');

        if ($withRelations) {
            $this->attachContextResolvers($rows);
        }

        return $rows;
    }

    private function applyFilters(Builder $query, array $filters): void
    {
        if (! empty($filters['user_id'])) {
            $query->where('user_id', (int) $filters['user_id']);
        }

        if (! empty($filters['event_type'])) {
            $query->whereRaw(
                $this->normalizedEventTypeSql('account_transactions.event_type').' = ?',
                array_merge($this->normalizedEventTypeBindings(), [$filters['event_type']])
            );
        }

        if (! empty($filters['direction'])) {
            if ($filters['direction'] === FinanceLedgerEventType::DIRECTION_IN) {
                $query->where('change_amount', '>', 0);
            } elseif ($filters['direction'] === FinanceLedgerEventType::DIRECTION_OUT) {
                $query->where('change_amount', '<', 0);
            }
        }

        $this->applyDateFilter($query, $filters);

        if (! empty($filters['service_id'])) {
            $this->applyServiceFilter($query, (int) $filters['service_id']);
        }

        if (! empty($filters['tab'])) {
            match ($filters['tab']) {
                'invoices' => $query->whereRaw(
                    $this->normalizedEventTypeSql('account_transactions.event_type').' IN (?, ?)',
                    array_merge(
                        $this->normalizedEventTypeBindings(),
                        [
                            FinanceLedgerEventType::INVOICE_PAYMENT,
                            FinanceLedgerEventType::INVOICE_REFUND,
                        ]
                    )
                ),
                'balance' => $query->whereRaw(
                    $this->normalizedEventTypeSql('account_transactions.event_type').' IN (?, ?, ?, ?, ?)',
                    array_merge(
                        $this->normalizedEventTypeBindings(),
                        [
                            FinanceLedgerEventType::RECHARGE,
                            FinanceLedgerEventType::MANUAL_RECHARGE,
                            FinanceLedgerEventType::MANUAL_DEDUCTION,
                            FinanceLedgerEventType::SYSTEM_ADJUSTMENT,
                            FinanceLedgerEventType::REFERRAL_CREDIT_CASH,
                        ]
                    )
                ),
                'recharge' => $query->whereRaw(
                    $this->normalizedEventTypeSql('account_transactions.event_type').' IN (?, ?)',
                    array_merge(
                        $this->normalizedEventTypeBindings(),
                        [
                            FinanceLedgerEventType::RECHARGE,
                            FinanceLedgerEventType::MANUAL_RECHARGE,
                        ]
                    )
                ),
                'adjustment' => $query->whereRaw(
                    $this->normalizedEventTypeSql('account_transactions.event_type').' IN (?, ?)',
                    array_merge(
                        $this->normalizedEventTypeBindings(),
                        [
                            FinanceLedgerEventType::MANUAL_DEDUCTION,
                            FinanceLedgerEventType::SYSTEM_ADJUSTMENT,
                        ]
                    )
                ),
                default => null,
            };
        }

        if (isset($filters['status']) && $filters['status'] !== '' && $filters['status'] !== null) {
            $status = (int) $filters['status'];
            $query->where(function (Builder $builder) use ($status) {
                $matchingInvoiceIds = Invoice::query()->where('status', $status)->pluck('id');
                $matchingPaymentIds = Payment::query()->where('status', $status)->pluck('id');

                $builder->where(function (Builder $inner) use ($matchingInvoiceIds) {
                    $inner->where('source_type', 'invoice')->whereIn('source_id', $matchingInvoiceIds);
                })->orWhere(function (Builder $inner) use ($matchingPaymentIds) {
                    $inner->where('source_type', 'payment')->whereIn('source_id', $matchingPaymentIds);
                });
            });
        }

        if (! empty($filters['invoice_no'])) {
            $invoiceIds = Invoice::query()
                ->where('invoice_no', 'like', '%'.trim((string) $filters['invoice_no']).'%')
                ->pluck('id');
            $paymentIds = Payment::query()->whereIn('invoice_id', $invoiceIds)->pluck('id');

            $query->where(function (Builder $builder) use ($invoiceIds, $paymentIds) {
                $builder->where(function (Builder $inner) use ($invoiceIds) {
                    $inner->where('source_type', 'invoice')->whereIn('source_id', $invoiceIds);
                })->orWhere(function (Builder $inner) use ($paymentIds) {
                    $inner->where('source_type', 'payment')->whereIn('source_id', $paymentIds);
                });
            });
        }

        if (! empty($filters['payment_no'])) {
            $paymentIds = Payment::query()
                ->where('payment_no', 'like', '%'.trim((string) $filters['payment_no']).'%')
                ->pluck('id');
            $invoiceIds = Payment::query()->whereIn('id', $paymentIds)->whereNotNull('invoice_id')->pluck('invoice_id');

            $query->where(function (Builder $builder) use ($paymentIds, $invoiceIds) {
                $builder->where(function (Builder $inner) use ($paymentIds) {
                    $inner->where('source_type', 'payment')->whereIn('source_id', $paymentIds);
                })->orWhere(function (Builder $inner) use ($invoiceIds) {
                    $inner->where('source_type', 'invoice')->whereIn('source_id', $invoiceIds);
                });
            });
        }

        if (! empty($filters['keyword'])) {
            $keyword = trim((string) $filters['keyword']);

            // digital exact match → user_id
            if (ctype_digit($keyword)) {
                $query->where('user_id', (int) $keyword);
            } else {
                $invoiceIds = Invoice::query()
                    ->where('invoice_no', 'like', '%'.$keyword.'%')
                    ->pluck('id');
                $paymentIdsFromInvoice = Payment::query()->whereIn('invoice_id', $invoiceIds)->pluck('id');

                $paymentIds = Payment::query()
                    ->where('payment_no', 'like', '%'.$keyword.'%')
                    ->pluck('id');
                $invoiceIdsFromPayment = Payment::query()->whereIn('id', $paymentIds)->whereNotNull('invoice_id')->pluck('invoice_id');

                $allInvoiceIds = $invoiceIds->merge($invoiceIdsFromPayment)->unique();
                $allPaymentIds = $paymentIds->merge($paymentIdsFromInvoice)->unique();

                $query->where(function (Builder $builder) use ($allInvoiceIds, $allPaymentIds) {
                    $builder->where(function (Builder $inner) use ($allInvoiceIds) {
                        $inner->where('source_type', 'invoice')->whereIn('source_id', $allInvoiceIds);
                    })->orWhere(function (Builder $inner) use ($allPaymentIds) {
                        $inner->where('source_type', 'payment')->whereIn('source_id', $allPaymentIds);
                    });
                });
            }
        }
    }

    private function applyServiceFilter(Builder $query, int $serviceId): void
    {
        if ($serviceId <= 0) {
            return;
        }

        $invoiceIds = Invoice::query()
            ->where('service_id', $serviceId)
            ->pluck('id');
        $orderInvoiceIds = Invoice::query()
            ->whereHas('order', fn (Builder $builder) => $builder->where('service_id', $serviceId))
            ->pluck('id');
        $invoiceIds = $invoiceIds->merge($orderInvoiceIds)->unique()->values();
        $paymentIds = Payment::query()
            ->whereIn('invoice_id', $invoiceIds)
            ->pluck('id');

        $query->where(function (Builder $builder) use ($invoiceIds, $paymentIds) {
            $builder->where(function (Builder $inner) use ($invoiceIds) {
                $inner->where('source_type', 'invoice')->whereIn('source_id', $invoiceIds);
            })->orWhere(function (Builder $inner) use ($paymentIds) {
                $inner->where('source_type', 'payment')->whereIn('source_id', $paymentIds);
            });
        });
    }

    private function applyDateFilter(Builder $query, array $filters): void
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

    private function attachContextResolvers(Builder $query): void
    {
        $query->withCasts(['normalized_event_type' => 'string']);
        $query->afterQuery(function ($records) {
            $isCollection = $records instanceof Collection;
            $items = $isCollection ? $records : collect($records);
            if ($items->isEmpty()) {
                return $records;
            }

            $invoiceIds = $items
                ->filter(fn ($item) => (string) ($item->source_type ?? '') === 'invoice')
                ->pluck('source_id')
                ->filter()
                ->map(fn ($id) => (int) $id)
                ->values()
                ->all();

            $paymentIds = $items
                ->filter(fn ($item) => (string) ($item->source_type ?? '') === 'payment')
                ->pluck('source_id')
                ->filter()
                ->map(fn ($id) => (int) $id)
                ->values()
                ->all();

            $invoiceMap = Invoice::query()
                ->with([
                    'payments' => fn ($relation) => $relation->orderByDesc('id'),
                    'service:id,name,domain,status,expires_at,provision_data',
                    'order:id,order_no,type,service_id,billing_cycle,quantity,remark,operator,trace_id,product_spec_snapshot',
                ])
                ->whereIn('id', array_unique($invoiceIds))
                ->get()
                ->map(function (Invoice $invoice) {
                    $invoice->type_label = InvoiceType::label((string) $invoice->type);

                    return $invoice;
                })
                ->keyBy('id');

            $paymentMap = Payment::query()
                ->whereIn('id', array_unique($paymentIds))
                ->get()
                ->keyBy('id');

            foreach ($items as $item) {
                $invoice = null;
                $payment = null;

                if ((string) ($item->source_type ?? '') === 'invoice') {
                    $invoice = $invoiceMap->get((int) ($item->source_id ?? 0));
                    $payment = $invoice?->payments?->first();
                } elseif ((string) ($item->source_type ?? '') === 'payment') {
                    $payment = $paymentMap->get((int) ($item->source_id ?? 0));
                    $invoice = $payment?->invoice_id ? $invoiceMap->get((int) $payment->invoice_id) : null;
                    if (! $invoice && $payment?->invoice_id) {
                        $invoice = Invoice::query()->with([
                            'payments' => fn ($relation) => $relation->orderByDesc('id'),
                            'service:id,name,domain,status,expires_at,provision_data',
                            'order:id,order_no,type,service_id,billing_cycle,quantity,remark,operator,trace_id,product_spec_snapshot',
                        ])->find((int) $payment->invoice_id);
                    }
                }

                $item->setRelation('invoice', $invoice);
                $item->setRelation('payment', $payment);
            }

            return $isCollection ? $items : $items->all();
        });
    }

    private function buildDetailPayload(AccountTransaction $record): array
    {
        $base = FinanceLedgerResource::make($record)->resolve();
        $invoice = $record->getRelationValue('invoice');
        $payment = $record->getRelationValue('payment');
        $order = $invoice?->order_id ? Order::query()->find((int) $invoice->order_id) : null;
        $paymentCallbacks = $this->buildPaymentCallbacks($payment);

        return array_merge($base, [
            'related_service' => $this->buildRelatedServicePayload($invoice, $order),
            'audit' => [
                'source_chain' => $this->buildSourceChain($record, $invoice, $payment, $order),
                'status_timeline' => $this->buildStatusTimeline($record, $invoice, $payment, $order, $paymentCallbacks),
                'audit_logs' => $this->buildAuditLogs($record, $invoice, $payment, $order),
                'payment_callbacks' => $paymentCallbacks,
                'trace_links' => $this->buildTraceLinks($record, $invoice, $payment, $order),
            ],
        ]);
    }

    private function buildClientDetailPayload(AccountTransaction $record): array
    {
        $base = $this->sanitizeClientLedgerPayload(FinanceLedgerResource::make($record)->resolve());
        $invoice = $record->getRelationValue('invoice');
        $order = $invoice?->order_id ? Order::query()->find((int) $invoice->order_id) : null;

        return array_merge($base, [
            'related_service' => $this->buildRelatedServicePayload($invoice, $order),
        ]);
    }

    private function sanitizeClientLedgerPayload(array $payload): array
    {
        unset($payload['trace_id']);

        return $payload;
    }

    private function buildRelatedServicePayload(?Invoice $invoice, ?Order $order): ?array
    {
        $service = $invoice?->service;
        $serviceId = (int) (($service?->id ?? ($invoice?->service_id ?? $order?->service_id ?? 0)) ?: 0);

        $configSnapshot = is_array($invoice?->config_snapshot ?? null)
            ? (array) $invoice->config_snapshot
            : (is_array($order?->config_snapshot ?? null) ? (array) $order->config_snapshot : []);
        $configSnapshot = VersionedJson::withoutMeta($configSnapshot) ?? [];

        $pricingSnapshot = is_array($invoice?->config_pricing_snapshot ?? null)
            ? (array) $invoice->config_pricing_snapshot
            : (is_array($order?->config_pricing_snapshot ?? null) ? (array) $order->config_pricing_snapshot : []);
        $pricingSnapshot = VersionedJson::withoutMeta($pricingSnapshot) ?? [];

        $productDisplayName = trim((string) ($invoice?->display_product_name ?? $order?->display_product_name ?? $invoice?->product_spec_snapshot ?? $order?->product_spec_snapshot ?? ''));
        if ($serviceId <= 0 && $productDisplayName === '' && $configSnapshot === [] && $pricingSnapshot === []) {
            return null;
        }

        $provisionData = $this->serviceProvisionData($service);
        $configItems = collect((array) data_get($pricingSnapshot, 'items', []))
            ->filter(fn ($item) => is_array($item))
            ->map(fn (array $item) => [
                'field' => trim((string) ($item['field'] ?? '')),
                'label' => trim((string) ($item['label'] ?? '')),
                'value' => trim((string) (($item['value_label'] ?? $item['value'] ?? ''))),
                'amount' => number_format((float) ($item['amount'] ?? 0), 2, '.', ''),
            ])
            ->values()
            ->all();

        $totalAmount = trim((string) data_get($pricingSnapshot, 'total_amount', ''));
        if ($totalAmount === '') {
            $totalAmount = number_format((float) ($invoice?->amount ?? $order?->amount ?? 0), 2, '.', '');
        }

        return [
            'service_id' => $serviceId,
            'service_name' => ServiceHostname::resolveInstanceName($service, $provisionData),
            'hostname' => ServiceHostname::resolveDisplayDomain($service, $provisionData),
            'product_display_name' => $productDisplayName,
            'billing_cycle' => (string) ($invoice?->billing_cycle ?? $order?->billing_cycle ?? ''),
            'quantity' => (int) data_get($pricingSnapshot, 'quantity', $invoice?->quantity ?? $order?->quantity ?? 1),
            'base_amount' => trim((string) data_get($pricingSnapshot, 'base_amount', '')),
            'config_amount' => trim((string) data_get($pricingSnapshot, 'config_amount', '')),
            'setup_fee' => trim((string) data_get($pricingSnapshot, 'setup_fee', '')),
            'total_amount' => $totalAmount,
            'config_snapshot' => $configSnapshot,
            'config_items' => $configItems,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function serviceProvisionData(?Service $service): array
    {
        if (! $service instanceof Service) {
            return [];
        }

        $legacy = is_array($service->provision_data ?? null) ? $service->provision_data : [];
        $projection = app(PluginBindingResolver::class)->serviceProvisionProjection($service);
        $provisionData = $projection === [] ? $legacy : array_replace($legacy, $projection);

        return app(PluginBindingResolver::class)->sanitizeServiceProvisionData($provisionData);
    }

    private function buildSourceChain(AccountTransaction $record, ?Invoice $invoice, ?Payment $payment, ?Order $order): array
    {
        $items = [];

        $standaloneSourceNode = $this->buildStandaloneSourceNode($record);
        if ($standaloneSourceNode !== null) {
            $items[] = $standaloneSourceNode;
        }

        if ($order instanceof Order) {
            $items[] = [
                'key' => 'order',
                'label' => '来源订单',
                'id' => (int) $order->id,
                'no' => (string) $order->order_no,
                'status' => (int) $order->status,
                'status_label' => OrderStatus::$labels[(int) $order->status] ?? (string) $order->status,
                'occurred_at' => ($order->paid_at ?? $order->created_at)?->format('Y-m-d H:i:s'),
                'description' => trim((string) $order->display_product_name) !== '' ? (string) $order->display_product_name : '订单记录',
            ];
        }

        if ($invoice instanceof Invoice) {
            $items[] = [
                'key' => 'invoice',
                'label' => '关联账单',
                'id' => (int) $invoice->id,
                'no' => (string) $invoice->invoice_no,
                'status' => (int) $invoice->status,
                'status_label' => InvoiceStatus::$labels[(int) $invoice->status] ?? (string) $invoice->status,
                'occurred_at' => $invoice->created_at?->format('Y-m-d H:i:s'),
                'description' => InvoiceType::label((string) $invoice->type),
            ];
        }

        if ($payment instanceof Payment) {
            $items[] = [
                'key' => 'payment',
                'label' => '关联支付',
                'id' => (int) $payment->id,
                'no' => (string) $payment->payment_no,
                'status' => (int) $payment->status,
                'status_label' => PaymentStatus::$labels[(int) $payment->status] ?? (string) $payment->status,
                'occurred_at' => ($payment->paid_at ?? $payment->created_at)?->format('Y-m-d H:i:s'),
                'description' => $payment->gatewayKey() !== '' ? $payment->gatewayKey() : '支付记录',
            ];
        }

        $items[] = [
            'key' => 'ledger',
            'label' => '资金台账',
            'id' => (int) $record->id,
            'no' => 'LEDGER-'.(int) $record->id,
            'status' => null,
            'status_label' => $this->eventStatusLabel($record, $invoice, $payment),
            'occurred_at' => $record->created_at?->format('Y-m-d H:i:s'),
            'description' => trim((string) ($record->remark ?? '')) ?: FinanceLedgerEventType::label((string) $record->event_type),
        ];

        return $items;
    }

    private function buildStatusTimeline(
        AccountTransaction $record,
        ?Invoice $invoice,
        ?Payment $payment,
        ?Order $order,
        array $paymentCallbacks = [],
    ): array {
        $timeline = collect();

        if ($order instanceof Order) {
            $timeline->push([
                'key' => 'order',
                'label' => '订单状态',
                'status' => (int) $order->status,
                'status_label' => OrderStatus::$labels[(int) $order->status] ?? (string) $order->status,
                'occurred_at' => ($order->paid_at ?? $order->updated_at ?? $order->created_at)?->format('Y-m-d H:i:s'),
                'source' => $order->order_no,
                'description' => trim((string) $order->display_product_name),
            ]);
        }

        if ($invoice instanceof Invoice) {
            $timeline->push([
                'key' => 'invoice',
                'label' => '账单状态',
                'status' => (int) $invoice->status,
                'status_label' => InvoiceStatus::$labels[(int) $invoice->status] ?? (string) $invoice->status,
                'occurred_at' => ($invoice->paid_at ?? $invoice->updated_at ?? $invoice->created_at)?->format('Y-m-d H:i:s'),
                'source' => $invoice->invoice_no,
                'description' => InvoiceType::label((string) $invoice->type),
            ]);
        }

        if ($payment instanceof Payment) {
            $timeline->push([
                'key' => 'payment',
                'label' => '支付状态',
                'status' => (int) $payment->status,
                'status_label' => PaymentStatus::$labels[(int) $payment->status] ?? (string) $payment->status,
                'occurred_at' => ($payment->paid_at ?? $payment->updated_at ?? $payment->created_at)?->format('Y-m-d H:i:s'),
                'source' => $payment->payment_no,
                'description' => $payment->gatewayKey(),
            ]);
        }

        foreach ($paymentCallbacks as $callback) {
            $timeline->push([
                'key' => 'payment_callback_'.(int) ($callback['id'] ?? 0),
                'label' => ($callback['callback_type'] ?? '') === 'refund' ? '退款回调' : '支付回调',
                'status' => (int) ($callback['is_verified'] ?? 0),
                'status_label' => (int) ($callback['is_verified'] ?? 0) === 1 ? '回调已核验' : '回调待核验',
                'occurred_at' => (string) ($callback['received_at'] ?? ''),
                'source' => trim((string) ($callback['gateway_trade_no'] ?? '')) !== ''
                    ? (string) $callback['gateway_trade_no']
                    : ($payment?->payment_no ?? ('CALLBACK-'.(int) ($callback['id'] ?? 0))),
                'description' => trim((string) ($callback['summary'] ?? '')),
            ]);
        }

        $timeline->push([
            'key' => 'ledger',
            'label' => '资金台账入账',
            'status' => null,
            'status_label' => $this->eventStatusLabel($record, $invoice, $payment),
            'occurred_at' => $record->created_at?->format('Y-m-d H:i:s'),
            'source' => 'LEDGER-'.(int) $record->id,
            'description' => trim((string) ($record->remark ?? '')) ?: FinanceLedgerEventType::label((string) $record->event_type),
        ]);

        return $timeline
            ->filter(fn (array $item) => trim((string) ($item['occurred_at'] ?? '')) !== '')
            ->sortBy('occurred_at')
            ->values()
            ->all();
    }

    private function buildAuditLogs(AccountTransaction $record, ?Invoice $invoice, ?Payment $payment, ?Order $order): array
    {
        $logs = collect();

        if ($invoice instanceof Invoice && $invoice->order_id) {
            $logs = $logs->merge(
                $this->queryOperationLogs('order', (int) $invoice->order_id)
            );
        }

        if ($invoice instanceof Invoice) {
            $logs = $logs->merge(
                $this->queryOperationLogs('invoice', (int) $invoice->id)
            );
        }

        if (trim((string) ($record->trace_id ?? '')) !== '') {
            $logs = $logs->merge($this->queryTraceLogs((string) $record->trace_id));
        }

        return $logs
            ->unique('id')
            ->sortByDesc('created_at')
            ->values()
            ->all();
    }

    private function buildPaymentCallbacks(?Payment $payment): array
    {
        if (! $payment instanceof Payment) {
            return [];
        }

        $callbacks = PaymentCallback::query()
            ->where('payment_id', (int) $payment->id)
            ->orderBy('received_at')
            ->orderBy('id')
            ->get();

        return $callbacks->map(function (PaymentCallback $callback) {
            $payload = (array) ($callback->payload_json ?? []);

            return [
                'id' => (int) $callback->id,
                'callback_type' => (string) ($callback->callback_type ?? ''),
                'gateway_trade_no' => (string) ($callback->gateway_trade_no ?? ''),
                'is_verified' => (int) ($callback->is_verified ?? 0),
                'received_at' => $callback->received_at?->format('Y-m-d H:i:s'),
                'summary' => $this->stringifyDetail($payload),
                'payload' => $payload,
            ];
        })->values()->all();
    }

    private function buildTraceLinks(AccountTransaction $record, ?Invoice $invoice, ?Payment $payment, ?Order $order): array
    {
        $links = [];
        $traceId = trim((string) ($record->trace_id ?? ''));

        if ($traceId !== '') {
            $links[] = [
                'label' => '台账 Trace ID',
                'value' => $traceId,
                'kind' => 'trace_id',
            ];
        }

        if ($payment instanceof Payment) {
            $paymentTraceId = trim((string) data_get($payment->callbackPayload(), 'trace_id', ''));
            if ($paymentTraceId !== '' && $paymentTraceId !== $traceId) {
                $links[] = [
                    'label' => '支付回调 Trace ID',
                    'value' => $paymentTraceId,
                    'kind' => 'trace_id',
                ];
            }

            $tradeNo = trim((string) ($payment->trade_no ?? ''));
            if ($tradeNo !== '') {
                $links[] = [
                    'label' => '渠道交易号',
                    'value' => $tradeNo,
                    'kind' => 'trade_no',
                ];
            }
        }

        if ($invoice instanceof Invoice) {
            $links[] = [
                'label' => '账单号',
                'value' => (string) $invoice->invoice_no,
                'kind' => 'invoice_no',
            ];
        }

        if ($order instanceof Order) {
            $links[] = [
                'label' => '订单号',
                'value' => (string) $order->order_no,
                'kind' => 'order_no',
            ];
        }

        return $links;
    }

    private function queryOperationLogs(string $module, int $subjectId): array
    {
        if ($subjectId <= 0) {
            return [];
        }

        return OperationLog::query()
            ->where('module', $module)
            ->where('subject_id', $subjectId)
            ->orderByDesc('id')
            ->limit(50)
            ->get()
            ->map(fn (OperationLog $log) => $this->transformOperationLog($log))
            ->values()
            ->all();
    }

    private function queryTraceLogs(string $traceId): array
    {
        return OperationLog::query()
            ->where('context->trace_id', $traceId)
            ->orderByDesc('id')
            ->limit(50)
            ->get()
            ->map(fn (OperationLog $log) => $this->transformOperationLog($log))
            ->values()
            ->all();
    }

    private function buildStandaloneSourceNode(AccountTransaction $record): ?array
    {
        $sourceType = trim((string) ($record->source_type ?: $record->origin_type));
        $sourceId = (int) ($record->source_id ?: $record->origin_id ?: 0);

        if ($sourceType === '' || in_array($sourceType, ['invoice', 'payment', 'order'], true)) {
            return null;
        }

        $sourceNo = $sourceId > 0 ? strtoupper($sourceType).'-'.$sourceId : strtoupper($sourceType);

        return [
            'key' => 'source',
            'label' => $this->sourceTypeLabel($sourceType),
            'id' => $sourceId > 0 ? $sourceId : null,
            'no' => $sourceNo,
            'status' => null,
            'status_label' => trim((string) ($record->operator ?? '')) ?: '系统触发',
            'occurred_at' => $record->created_at?->format('Y-m-d H:i:s'),
            'description' => trim((string) ($record->remark ?? '')) ?: $this->sourceTypeDescription($sourceType),
        ];
    }

    private function sourceTypeLabel(string $sourceType): string
    {
        return match ($sourceType) {
            'manual_adjustment' => '人工调账来源',
            'referral_withdrawal' => '返佣转余额来源',
            default => '来源对象',
        };
    }

    private function sourceTypeDescription(string $sourceType): string
    {
        return match ($sourceType) {
            'manual_adjustment' => '后台人工发起的加款或扣款动作',
            'referral_withdrawal' => '返佣或奖励转入现金余额',
            default => $sourceType,
        };
    }

    private function transformOperationLog(OperationLog $log): array
    {
        $privacy = AdminPrivacy::current();
        $detail = (array) $privacy->payload((array) ($log->detail ?? []));

        return [
            'id' => (int) $log->id,
            'module' => (string) ($log->module ?? ''),
            'action' => (string) ($log->action ?? ''),
            'user_type' => (string) ($log->user_type ?? ''),
            'user_id' => $log->user_id !== null ? (int) $log->user_id : null,
            'subject_id' => $log->subject_id !== null ? (int) $log->subject_id : null,
            'created_at' => $log->created_at?->format('Y-m-d H:i:s'),
            'ip_address' => $privacy->ip($log->ip_address ?? ''),
            'trace_id' => trim((string) ($detail['trace_id'] ?? '')),
            'operator_name' => trim((string) ($detail['operator_name'] ?? $detail['actor_name'] ?? '')),
            'summary' => $this->stringifyDetail($detail),
            'detail' => $detail,
            'tone' => $this->resolveOperationLogTone((string) ($log->action ?? '')),
        ];
    }

    private function resolveOperationLogTone(string $action): string
    {
        return match (true) {
            str_contains($action, 'refund') => 'danger',
            str_contains($action, 'cancel'), str_contains($action, 'unpaid') => 'warning',
            str_contains($action, 'paid'), str_contains($action, 'create') => 'success',
            default => 'info',
        };
    }

    private function eventStatusLabel(AccountTransaction $record, ?Invoice $invoice, ?Payment $payment): string
    {
        if ($payment instanceof Payment) {
            return PaymentStatus::$labels[(int) $payment->status] ?? (string) $payment->status;
        }

        if ($invoice instanceof Invoice) {
            return InvoiceStatus::$labels[(int) $invoice->status] ?? (string) $invoice->status;
        }

        return FinanceLedgerEventType::label((string) $record->event_type);
    }

    private function stringifyDetail(array $detail): string
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

    private function normalizedEventTypeSql(string $column): string
    {
        return "CASE
            WHEN {$column} = 'consume' THEN ?
            WHEN {$column} = 'refund' THEN ?
            WHEN {$column} IN ('admin_deduct', 'deduct') THEN ?
            WHEN {$column} = 'adjust' THEN ?
            WHEN {$column} = 'referral_withdraw_approved' THEN ?
            ELSE {$column}
        END";
    }

    private function normalizedEventTypeBindings(): array
    {
        return [
            FinanceLedgerEventType::INVOICE_PAYMENT,
            FinanceLedgerEventType::INVOICE_REFUND,
            FinanceLedgerEventType::MANUAL_DEDUCTION,
            FinanceLedgerEventType::SYSTEM_ADJUSTMENT,
            FinanceLedgerEventType::REFERRAL_CREDIT_CASH,
        ];
    }
}
