<?php

declare(strict_types=1);

namespace App\Models;

use App\Constants\BillingCycle;
use App\Models\Concerns\NormalizesTraceId;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class ServiceInstance extends Model
{
    use NormalizesTraceId;

    private const COMPAT_META_KEY = '__legacy_service_compat';

    /** 允许下单/续费的周期，文案与月数统一取自 {@see BillingCycle}。 */
    public const SUPPORTED_RENEW_BILLING_CYCLES = BillingCycle::RENEWABLE_LABELS;

    private const SUPPORTED_RENEW_BILLING_CYCLE_MONTHS = BillingCycle::RENEWABLE_MONTHS;

    protected $table = 'service_instances';

    protected $fillable = [
        'user_id',
        'product_id',
        'source_invoice_id',
        'order_id',
        'invoice_id',
        'supplier_id',
        'supplier_product_id',
        'server_id',
        'service_no',
        'name',
        'instance_identifier',
        'domain',
        'billing_cycle',
        'renewal_price',
        'amount',
        'status',
        'auto_renew',
        'product_snapshot_json',
        'pricing_snapshot_json',
        'locked_pricing',
        'config_snapshot_json',
        'provision_snapshot_json',
        'provision_data',
        'remote_meta_json',
        'opened_at',
        'expires_at',
        'suspended_at',
        'terminated_at',
        'suspended_reason',
        'trace_id',
        'remark',
    ];

    protected function casts(): array
    {
        return [
            'user_id' => 'integer',
            'product_id' => 'integer',
            'source_invoice_id' => 'integer',
            'supplier_id' => 'integer',
            'supplier_product_id' => 'integer',
            'server_id' => 'integer',
            'renewal_price' => 'decimal:2',
            'auto_renew' => 'boolean',
            'product_snapshot_json' => 'array',
            'pricing_snapshot_json' => 'array',
            'config_snapshot_json' => 'array',
            'provision_snapshot_json' => 'array',
            'remote_meta_json' => 'array',
            'opened_at' => 'datetime',
            'expires_at' => 'datetime',
            'suspended_at' => 'datetime',
            'terminated_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    public function server(): BelongsTo
    {
        return $this->belongsTo(Server::class);
    }

    public function sourceInvoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class, 'source_invoice_id');
    }

    public function invoice(): HasOne
    {
        $invoice = new Invoice;
        $foreignKey = $invoice->getConnection()->getSchemaBuilder()->hasColumn($invoice->getTable(), 'service_id')
            ? 'service_id'
            : 'service_instance_id';

        return $this->hasOne(Invoice::class, $foreignKey);
    }

    public function order(): HasOne
    {
        return $this->hasOne(Order::class, 'service_id');
    }

    public function invoices(): HasMany
    {
        $invoice = new Invoice;
        $foreignKey = $invoice->getConnection()->getSchemaBuilder()->hasColumn($invoice->getTable(), 'service_id')
            ? 'service_id'
            : 'service_instance_id';

        return $this->hasMany(Invoice::class, $foreignKey);
    }

    public function lifecycleLogs(): HasMany
    {
        return $this->hasMany(ServiceLifecycleLog::class, 'service_instance_id');
    }

    public function operationLogs(): HasMany
    {
        return $this->hasMany(ServiceOperationLog::class, 'service_instance_id');
    }

    public function remoteSnapshots(): HasMany
    {
        return $this->hasMany(ServiceRemoteSnapshot::class, 'service_instance_id');
    }

    public function tickets(): HasMany
    {
        return $this->hasMany(Ticket::class, 'service_instance_id');
    }

    public function getDomainAttribute(): string
    {
        $meta = $this->compatMeta();
        $domain = trim((string) ($meta['domain'] ?? ''));
        if ($domain !== '') {
            return $domain;
        }

        $domain = trim((string) ($this->instance_identifier ?? ''));
        if ($domain !== '') {
            return $domain;
        }

        $snapshot = is_array($this->provision_snapshot_json ?? null) ? $this->provision_snapshot_json : [];

        return trim((string) ($snapshot['requested_host'] ?? $snapshot['requested_config']['hostname'] ?? ''));
    }

    public function setDomainAttribute(mixed $value): void
    {
        $domain = trim((string) $value);
        $meta = $this->compatMeta();
        $meta['domain'] = $domain;
        $this->writeCompatMeta($meta);

        if ($domain !== '') {
            $this->attributes['instance_identifier'] = $domain;
        } elseif (array_key_exists('instance_identifier', $this->attributes)) {
            $this->attributes['instance_identifier'] = null;
        }
    }

    public function getAmountAttribute(): float
    {
        return round((float) ($this->renewal_price ?? 0), 2);
    }

    public function setAmountAttribute(mixed $value): void
    {
        $normalized = is_numeric($value) ? round((float) $value, 2) : 0.0;
        $this->attributes['renewal_price'] = number_format($normalized, 2, '.', '');
    }

    public function getLockedPricingAttribute(): ?array
    {
        $pricing = is_array($this->pricing_snapshot_json ?? null) ? $this->pricing_snapshot_json : [];

        return $pricing === [] ? null : $pricing;
    }

    public function setLockedPricingAttribute(mixed $value): void
    {
        $this->pricing_snapshot_json = is_array($value) ? $value : [];
    }

    public function getProvisionDataAttribute(): array
    {
        $provisionData = is_array($this->provision_snapshot_json ?? null) ? $this->provision_snapshot_json : [];
        $meta = $this->compatMeta();

        if (! empty($meta['suspended_reason']) && ! isset($provisionData['suspended_reason'])) {
            $provisionData['suspended_reason'] = $meta['suspended_reason'];
        }

        return $provisionData;
    }

    public function setProvisionDataAttribute(mixed $value): void
    {
        $payload = is_array($value) ? $value : [];

        if (array_key_exists('requested_host', $payload) && trim((string) $payload['requested_host']) !== '') {
            $this->attributes['instance_identifier'] = trim((string) $payload['requested_host']);
        }

        $this->provision_snapshot_json = $payload;
    }

    public function getSuspendedReasonAttribute(): ?string
    {
        $meta = $this->compatMeta();
        $reason = trim((string) ($meta['suspended_reason'] ?? ''));
        if ($reason !== '') {
            return $reason;
        }

        $snapshot = is_array($this->provision_snapshot_json ?? null) ? $this->provision_snapshot_json : [];
        $reason = trim((string) ($snapshot['suspended_reason'] ?? ''));

        return $reason !== '' ? $reason : null;
    }

    public function setSuspendedReasonAttribute(mixed $value): void
    {
        $reason = trim((string) $value);
        $meta = $this->compatMeta();
        $meta['suspended_reason'] = $reason !== '' ? $reason : null;
        $this->writeCompatMeta($meta);

        $snapshot = is_array($this->provision_snapshot_json ?? null) ? $this->provision_snapshot_json : [];
        if ($reason !== '') {
            $snapshot['suspended_reason'] = $reason;
        } else {
            unset($snapshot['suspended_reason']);
        }
        $this->provision_snapshot_json = $snapshot;
    }

    public function getInvoiceIdAttribute(): ?int
    {
        $sourceInvoiceId = (int) ($this->source_invoice_id ?? 0);

        return $sourceInvoiceId > 0 ? $sourceInvoiceId : null;
    }

    public function setInvoiceIdAttribute(mixed $value): void
    {
        $normalized = is_numeric($value) ? (int) $value : 0;
        $this->attributes['source_invoice_id'] = $normalized > 0 ? $normalized : null;
    }

    public function getOrderIdAttribute(): ?int
    {
        $meta = $this->compatMeta();
        $orderId = (int) ($meta['order_id'] ?? 0);

        return $orderId > 0 ? $orderId : null;
    }

    public function setOrderIdAttribute(mixed $value): void
    {
        $normalized = is_numeric($value) ? (int) $value : 0;
        $meta = $this->compatMeta();
        $meta['order_id'] = $normalized > 0 ? $normalized : null;
        $this->writeCompatMeta($meta);
    }

    public static function extractSupportedRenewPricing(array $pricing): array
    {
        $normalized = [];

        foreach (self::SUPPORTED_RENEW_BILLING_CYCLES as $cycle => $label) {
            $amount = self::normalizeRenewPricingAmount($pricing[$cycle] ?? null);
            if ($amount === null || $amount <= 0) {
                continue;
            }

            $normalized[$cycle] = self::formatRenewPricingAmount($amount);
        }

        return $normalized;
    }

    public static function buildDefaultRenewPricing(array $pricing = [], string $currentBillingCycle = '', mixed $currentAmount = null): array
    {
        $basePricing = self::extractSupportedRenewPricing($pricing);
        $currentCycle = trim($currentBillingCycle);
        $currentCycleAmount = self::normalizeRenewPricingAmount($currentAmount);
        $currentCycleMonths = self::SUPPORTED_RENEW_BILLING_CYCLE_MONTHS[$currentCycle] ?? 0;

        if ($currentCycleMonths > 0 && $currentCycleAmount !== null && $currentCycleAmount > 0) {
            $monthlyBaseAmount = (float) $currentCycleAmount / $currentCycleMonths;
            $derivedPricing = [];

            foreach (self::SUPPORTED_RENEW_BILLING_CYCLE_MONTHS as $cycle => $months) {
                $derivedPricing[$cycle] = self::formatRenewPricingAmount(round($monthlyBaseAmount * $months, 2));
            }

            $basePricing = array_filter($derivedPricing, fn ($amount) => $amount !== null);
        } elseif (
            $currentCycle !== ''
            && array_key_exists($currentCycle, self::SUPPORTED_RENEW_BILLING_CYCLES)
            && ! isset($basePricing[$currentCycle])
            && $currentCycleAmount !== null
            && $currentCycleAmount > 0
        ) {
            $basePricing[$currentCycle] = self::formatRenewPricingAmount($currentCycleAmount);
        }

        $config = [];

        foreach (self::SUPPORTED_RENEW_BILLING_CYCLES as $cycle => $label) {
            $baseAmount = self::normalizeRenewPricingAmount($basePricing[$cycle] ?? null);
            $config[$cycle] = [
                'enabled' => $baseAmount !== null && $baseAmount > 0,
                'base_amount' => self::formatRenewPricingAmount($baseAmount),
                'manual_amount' => null,
            ];
        }

        return $config;
    }

    public function resolveRenewPricingConfig(array $fallbackPricing = []): array
    {
        $defaultConfig = self::buildDefaultRenewPricing($fallbackPricing, (string) $this->billing_cycle, $this->renewal_price);
        $lockedPricing = is_array($this->pricing_snapshot_json ?? null) ? $this->pricing_snapshot_json : [];

        if ($lockedPricing === []) {
            return $defaultConfig;
        }

        $hasStructuredCycleConfig = false;
        foreach (self::SUPPORTED_RENEW_BILLING_CYCLES as $cycle => $label) {
            if (is_array($lockedPricing[$cycle] ?? null)) {
                $hasStructuredCycleConfig = true;
                break;
            }
        }

        if (! $hasStructuredCycleConfig) {
            $legacyPricing = self::extractSupportedRenewPricing($lockedPricing);
            $currentCycle = trim((string) $this->billing_cycle);
            $legacyCurrentCycleAmount = self::normalizeRenewPricingAmount($legacyPricing[$currentCycle] ?? null);
            $serviceAmount = self::normalizeRenewPricingAmount($this->renewal_price);

            if (
                $currentCycle !== ''
                && $serviceAmount !== null
                && $serviceAmount > 0
                && $legacyCurrentCycleAmount !== null
                && abs($legacyCurrentCycleAmount - $serviceAmount) > 0.0001
            ) {
                return $defaultConfig;
            }

            foreach ($legacyPricing as $cycle => $amount) {
                $defaultConfig[$cycle] = [
                    'enabled' => true,
                    'base_amount' => $amount,
                    'manual_amount' => null,
                ];
            }

            return $defaultConfig;
        }

        $resolved = [];

        foreach (self::SUPPORTED_RENEW_BILLING_CYCLES as $cycle => $label) {
            $current = is_array($lockedPricing[$cycle] ?? null) ? $lockedPricing[$cycle] : [];
            $defaultBaseAmount = self::normalizeRenewPricingAmount($defaultConfig[$cycle]['base_amount'] ?? null);
            $storedBaseAmount = self::normalizeRenewPricingAmount($current['base_amount'] ?? null);
            $baseAmount = $defaultBaseAmount ?? $storedBaseAmount;
            $manualAmount = self::normalizeRenewPricingAmount($current['manual_amount'] ?? null);
            if ($manualAmount !== null && $manualAmount <= 0) {
                $manualAmount = null;
            }

            $effectiveAmount = $manualAmount !== null && $manualAmount > 0 ? $manualAmount : $baseAmount;
            $explicitEnabled = array_key_exists('enabled', $current)
                ? filter_var($current['enabled'], FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE)
                : null;

            $resolved[$cycle] = [
                'enabled' => (bool) (($explicitEnabled ?? true) && $effectiveAmount !== null && $effectiveAmount > 0),
                'base_amount' => self::formatRenewPricingAmount($baseAmount),
                'manual_amount' => self::formatRenewPricingAmount($manualAmount),
            ];
        }

        return $resolved;
    }

    public function getRenewPricingCycle(string $billingCycle, array $fallbackPricing = []): ?array
    {
        $cycle = trim($billingCycle);
        if ($cycle === '' || ! array_key_exists($cycle, self::SUPPORTED_RENEW_BILLING_CYCLES)) {
            return null;
        }

        $config = $this->resolveRenewPricingConfig($fallbackPricing);
        $cycleConfig = $config[$cycle] ?? null;
        if (! is_array($cycleConfig)) {
            return null;
        }

        $baseAmount = self::normalizeRenewPricingAmount($cycleConfig['base_amount'] ?? null);
        $manualAmount = self::normalizeRenewPricingAmount($cycleConfig['manual_amount'] ?? null);
        if ($manualAmount !== null && $manualAmount <= 0) {
            $manualAmount = null;
        }

        $effectiveAmount = $manualAmount !== null && $manualAmount > 0 ? $manualAmount : $baseAmount;

        return [
            'billing_cycle' => $cycle,
            'enabled' => (bool) ($cycleConfig['enabled'] ?? false) && $effectiveAmount !== null && $effectiveAmount > 0,
            'base_amount' => self::formatRenewPricingAmount($baseAmount),
            'manual_amount' => self::formatRenewPricingAmount($manualAmount),
            'effective_amount' => self::formatRenewPricingAmount($effectiveAmount),
        ];
    }

    public function getLockedPrice(string $billingCycle, array $fallbackPricing = []): ?float
    {
        $cycleConfig = $this->getRenewPricingCycle($billingCycle, $fallbackPricing);
        if (! is_array($cycleConfig) || empty($cycleConfig['enabled'])) {
            return null;
        }

        $effectiveAmount = self::normalizeRenewPricingAmount($cycleConfig['effective_amount'] ?? null);
        if ($effectiveAmount === null || $effectiveAmount <= 0) {
            return null;
        }

        return round($effectiveAmount, 2);
    }

    public function usesCustomRenewPricing(array $fallbackPricing = []): bool
    {
        $lockedPricing = is_array($this->pricing_snapshot_json ?? null) ? $this->pricing_snapshot_json : [];
        if ($lockedPricing === []) {
            return false;
        }

        $hasStructuredCycleConfig = false;
        foreach (self::SUPPORTED_RENEW_BILLING_CYCLES as $cycle => $label) {
            if (is_array($lockedPricing[$cycle] ?? null)) {
                $hasStructuredCycleConfig = true;
                break;
            }
        }

        if (! $hasStructuredCycleConfig) {
            return false;
        }

        $config = $this->resolveRenewPricingConfig($fallbackPricing);

        foreach (self::SUPPORTED_RENEW_BILLING_CYCLES as $cycle => $label) {
            $entry = $config[$cycle] ?? [];
            $baseAmount = self::normalizeRenewPricingAmount($entry['base_amount'] ?? null);
            $manualAmount = self::normalizeRenewPricingAmount($entry['manual_amount'] ?? null);

            if ($manualAmount !== null && $manualAmount > 0) {
                return true;
            }

            $defaultEnabled = $baseAmount !== null && $baseAmount > 0;
            if ((bool) ($entry['enabled'] ?? false) !== $defaultEnabled) {
                return true;
            }
        }

        return false;
    }

    private static function normalizeRenewPricingAmount(mixed $value): ?float
    {
        if ($value === null || $value === '' || ! is_numeric($value)) {
            return null;
        }

        return round((float) $value, 2);
    }

    private static function formatRenewPricingAmount(?float $value): ?string
    {
        if ($value === null || $value <= 0) {
            return null;
        }

        return number_format($value, 2, '.', '');
    }

    private function compatMeta(): array
    {
        $remark = trim((string) ($this->attributes['remark'] ?? $this->remark ?? ''));
        if ($remark === '') {
            return [];
        }

        $decoded = json_decode($remark, true);
        if (! is_array($decoded)) {
            return [];
        }

        $meta = $decoded[self::COMPAT_META_KEY] ?? null;

        return is_array($meta) ? $meta : [];
    }

    private function writeCompatMeta(array $meta): void
    {
        $meta = array_filter($meta, static fn ($value) => $value !== null && $value !== '');
        $remark = trim((string) ($this->attributes['remark'] ?? $this->remark ?? ''));
        $decoded = json_decode($remark, true);

        if (! is_array($decoded)) {
            $decoded = [];
        }

        if ($meta === []) {
            unset($decoded[self::COMPAT_META_KEY]);
        } else {
            $decoded[self::COMPAT_META_KEY] = $meta;
        }

        $this->attributes['remark'] = $decoded === []
            ? null
            : json_encode($decoded, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
}
