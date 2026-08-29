<?php

namespace App\Models;

use App\Constants\BillingCycle;
use App\Models\Concerns\EnsuresTraceId;
use App\Models\Concerns\NormalizesTraceId;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\SoftDeletingScope;
use Illuminate\Support\Facades\Schema;

class Service extends Model
{
    use EnsuresTraceId, NormalizesTraceId, SoftDeletes;

    /**
     * The live database may predate the services soft-delete migration.
     * Register the scope only when the column is available.
     */
    protected static function bootSoftDeletes(): void
    {
        $model = new static;

        if (Schema::hasColumn($model->getTable(), $model->getDeletedAtColumn())) {
            static::addGlobalScope(new SoftDeletingScope);
        }
    }

    /** 允许下单/续费的周期，文案与月数统一取自 {@see BillingCycle}。 */
    /**
     * 到期欠费停机的 suspended_reason 标记。
     *
     * 该标记既是「为什么停机」的说明，也是自动取消流程的筛选条件
     * （ServiceLifecycleAutomationService 按 status=SUSPENDED + 该标记挑选待取消服务）。
     * 因此任何清除它的地方都等于让服务永久逃过欠费终止，必须用常量而不是字面量，
     * 避免改了标记而某处守卫悄悄失效。
     */
    public const SUSPENDED_REASON_EXPIRED = 'expired';

    public const SUPPORTED_RENEW_BILLING_CYCLES = BillingCycle::RENEWABLE_LABELS;

    private const SUPPORTED_RENEW_BILLING_CYCLE_MONTHS = BillingCycle::RENEWABLE_MONTHS;

    protected $fillable = [
        'user_id', 'product_id', 'order_id', 'invoice_id', 'name', 'domain',
        'billing_cycle', 'amount', 'locked_pricing', 'status', 'provision_data',
        'expires_at', 'auto_renew', 'suspended_reason', 'trace_id',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'locked_pricing' => 'array',
            'provision_data' => 'array',
            'expires_at' => 'datetime',
        ];
    }

    protected static function booted(): void {}

    public function getProvisionDataAttribute(mixed $value): array
    {
        if (is_array($value)) {
            return $value;
        }

        if (is_string($value) && $value !== '') {
            $decoded = json_decode($value, true);

            return is_array($decoded) ? $decoded : [];
        }

        return [];
    }

    public function getLockedPricingAttribute(mixed $value): ?array
    {
        if (is_array($value)) {
            return $value;
        }

        if (is_string($value) && $value !== '') {
            $decoded = json_decode($value, true);

            return is_array($decoded) ? $decoded : null;
        }

        return null;
    }

    /**
     * 提取受支持的续费周期价格。
     */
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

    /**
     * 根据快照价格构建默认续费配置。
     */
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

    /**
     * 解析服务续费配置，兼容旧版纯金额结构。
     */
    public function resolveRenewPricingConfig(array $fallbackPricing = []): array
    {
        $defaultConfig = self::buildDefaultRenewPricing($fallbackPricing, (string) $this->billing_cycle, $this->amount);
        $lockedPricing = is_array($this->locked_pricing ?? null) ? $this->locked_pricing : [];

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
            $serviceAmount = self::normalizeRenewPricingAmount($this->amount);

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
            // 基础快照价始终优先采用当前服务订单金额折算结果，兼容早期错误写入的旧快照。
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

    /**
     * 恢复默认续费配置：保留基础快照价，清空人工调整。
     */
    public function resetRenewPricingConfig(array $fallbackPricing = []): array
    {
        $currentConfig = $this->resolveRenewPricingConfig($fallbackPricing);
        $resetConfig = [];

        foreach (self::SUPPORTED_RENEW_BILLING_CYCLES as $cycle => $label) {
            $baseAmount = self::normalizeRenewPricingAmount($currentConfig[$cycle]['base_amount'] ?? null);
            $resetConfig[$cycle] = [
                'enabled' => $baseAmount !== null && $baseAmount > 0,
                'base_amount' => self::formatRenewPricingAmount($baseAmount),
                'manual_amount' => null,
            ];
        }

        return $resetConfig;
    }

    /**
     * 获取指定计费周期的续费配置。
     */
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

    /**
     * 获取指定计费周期的有效续费价格，关闭或无效时返回 null。
     */
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

    /**
     * 是否存在人工调整续费配置。
     */
    public function usesCustomRenewPricing(array $fallbackPricing = []): bool
    {
        $lockedPricing = is_array($this->locked_pricing ?? null) ? $this->locked_pricing : [];
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

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    public function invoices(): HasMany
    {
        return $this->hasMany(Invoice::class);
    }

    public function tickets(): HasMany
    {
        return $this->hasMany(Ticket::class);
    }

    public function upstreamBinding(): HasOne
    {
        return $this->hasOne(ServiceUpstreamBinding::class, 'service_id');
    }

    public function runtimeSnapshot(): HasOne
    {
        return $this->hasOne(ServiceRuntimeSnapshot::class, 'service_id');
    }

    public function connectionSnapshots(): HasMany
    {
        return $this->hasMany(ServiceConnectionSnapshot::class, 'service_id');
    }

    public function provisionAttempts(): HasMany
    {
        return $this->hasMany(ServiceProvisionAttempt::class, 'service_id');
    }

    public function scopeActive($query)
    {
        return $query->where('status', 1);
    }

    public function scopeExpiringSoon($query, int $days = 7)
    {
        return $query->where('status', 1)
            ->where('auto_renew', 1)
            ->where('expires_at', '<=', now()->addDays($days));
    }

    public function isExpired(): bool
    {
        return $this->expires_at && $this->expires_at->isPast();
    }
}
