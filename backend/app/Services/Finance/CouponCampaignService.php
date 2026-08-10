<?php

declare(strict_types=1);

namespace App\Services\Finance;

use App\Constants\CouponStatus;
use App\Exceptions\BusinessException;
use App\Models\AutomationLog;
use App\Models\Coupon;
use App\Models\CouponCampaign;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Pagination\LengthAwarePaginator as SimpleLengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

class CouponCampaignService
{
    private const TASK_KEY = 'coupon-campaign-dispatch';

    private const SUPPORTED_BILLING_CYCLE_LABELS = [
        'monthly' => '月付',
        'quarterly' => '季付',
        'semiannually' => '半年付',
        'annually' => '年付',
    ];

    private const WEEKDAY_LABELS = [
        0 => '周日',
        1 => '周一',
        2 => '周二',
        3 => '周三',
        4 => '周四',
        5 => '周五',
        6 => '周六',
    ];

    public function __construct(
        private CouponService $couponService,
    ) {}

    public function adminSummary(array $filters = []): array
    {
        if (! Schema::hasTable('coupon_campaigns')) {
            return [
                'total' => 0,
                'active' => 0,
                'disabled' => 0,
                'generated_today' => 0,
            ];
        }

        $query = $this->buildAdminCampaignQuery($filters, false);
        $todayStart = now()->startOfDay();
        $todayEnd = now()->endOfDay();

        return [
            'total' => (clone $query)->count(),
            'active' => (clone $query)->where('status', CouponStatus::ACTIVE)->count(),
            'disabled' => (clone $query)->where('status', CouponStatus::DISABLED)->count(),
            'generated_today' => Schema::hasTable('coupons')
                ? (int) Coupon::query()
                    ->whereNotNull('coupon_campaign_id')
                    ->whereBetween('created_at', [$todayStart, $todayEnd])
                    ->count()
                : 0,
        ];
    }

    public function adminList(array $filters = [], int $perPage = 20): LengthAwarePaginator
    {
        if (! Schema::hasTable('coupon_campaigns')) {
            return new SimpleLengthAwarePaginator([], 0, $perPage, 1);
        }

        $paginator = $this->buildAdminCampaignQuery($filters)
            ->with(['lastCoupon:id,name,code,created_at'])
            ->withCount('coupons')
            ->orderByDesc('sort_order')
            ->orderByDesc('id')
            ->paginate($perPage);

        $items = collect($paginator->items());
        $productNameMap = $this->resolveProductNameMapFromCampaigns($items);

        $paginator->setCollection(
            $items
                ->map(fn (CouponCampaign $campaign) => $this->transformCampaignForAdmin($campaign, $productNameMap))
                ->values()
        );

        return $paginator;
    }

    public function createCampaign(array $payload, array $context = []): array
    {
        $campaign = CouponCampaign::query()->create(
            $this->normalizeAdminCampaignPayload($payload, $context)
        );

        $campaign = $campaign->fresh(['lastCoupon'])->loadCount('coupons');

        return $this->transformCampaignForAdmin($campaign, $this->resolveProductNameMapFromCampaigns(collect([$campaign])));
    }

    public function updateCampaign(CouponCampaign $campaign, array $payload, array $context = []): array
    {
        $campaign = DB::transaction(function () use ($campaign, $payload, $context) {
            $lockedCampaign = CouponCampaign::query()
                ->whereKey($campaign->id)
                ->lockForUpdate()
                ->first();

            throw_if(! $lockedCampaign, new BusinessException('活动不存在或已删除'));
            throw_if(
                $this->campaignHasGeneratedCoupons($lockedCampaign),
                new BusinessException('活动已生成优惠券批次，不允许修改')
            );

            $lockedCampaign->fill($this->normalizeAdminCampaignPayload($payload, $context, $lockedCampaign));
            $lockedCampaign->save();

            return $lockedCampaign->fresh(['lastCoupon'])->loadCount('coupons');
        });

        return $this->transformCampaignForAdmin($campaign, $this->resolveProductNameMapFromCampaigns(collect([$campaign])));
    }

    public function toggleCampaignStatus(CouponCampaign $campaign, array $context = []): array
    {
        $campaign->forceFill([
            'status' => (int) $campaign->status === CouponStatus::ACTIVE
                ? CouponStatus::DISABLED
                : CouponStatus::ACTIVE,
            'operator' => (string) ($context['operator'] ?? $campaign->operator ?? ''),
            'trace_id' => (string) ($context['trace_id'] ?? $campaign->trace_id ?? ''),
        ])->save();

        $campaign = $campaign->fresh(['lastCoupon'])->loadCount('coupons');

        return $this->transformCampaignForAdmin($campaign, $this->resolveProductNameMapFromCampaigns(collect([$campaign])));
    }

    public function deleteCampaign(CouponCampaign $campaign): void
    {
        DB::transaction(function () use ($campaign): void {
            $lockedCampaign = CouponCampaign::query()
                ->whereKey($campaign->id)
                ->lockForUpdate()
                ->first();

            throw_if(! $lockedCampaign, new BusinessException('活动不存在或已删除'));
            throw_if(
                $this->campaignHasGeneratedCoupons($lockedCampaign),
                new BusinessException('活动已生成优惠券批次，不允许删除')
            );

            $lockedCampaign->delete();
        });
    }

    public function triggerCampaign(CouponCampaign $campaign, array $context = []): array
    {
        throw_if((int) $campaign->status !== CouponStatus::ACTIVE, new BusinessException('请先启用活动后再手动发放'));

        $triggerAt = CarbonImmutable::now(config('app.timezone'));
        $ruleKey = 'manual-'.$triggerAt->format('YmdHis').'-'.Str::lower(Str::random(6));
        $result = $this->dispatchSingleCampaign($campaign, $triggerAt, $ruleKey, $context, true);
        throw_if($result === null, new BusinessException('该活动今日已发放过批次，请勿重复操作'));
        $campaign = $campaign->fresh(['lastCoupon'])->loadCount('coupons');

        return [
            'campaign' => $this->transformCampaignForAdmin(
                $campaign,
                $this->resolveProductNameMapFromCampaigns(collect([$campaign]))
            ),
            'coupon' => $result['coupon'],
            'triggered_at' => $triggerAt->format('Y-m-d H:i:s'),
        ];
    }

    public function dispatchDueCampaigns(?CarbonImmutable $now = null, array $context = []): array
    {
        $resolvedNow = $now ?: CarbonImmutable::now(config('app.timezone'));
        $campaigns = CouponCampaign::query()
            ->where('status', CouponStatus::ACTIVE)
            ->orderByDesc('sort_order')
            ->orderByDesc('id')
            ->get();

        $summary = [
            'matched' => $campaigns->count(),
            'triggered' => 0,
            'skipped' => 0,
            'failed' => 0,
            'coupon_ids' => [],
        ];

        foreach ($campaigns as $campaign) {
            $scheduledAt = $this->resolveDueScheduleAt($campaign, $resolvedNow);
            if (! $scheduledAt) {
                $summary['skipped']++;

                continue;
            }

            $ruleKey = $scheduledAt->format('YmdHi');
            if (! AutomationLog::recordOnce(self::TASK_KEY, 'dispatch', 'coupon_campaign', (int) $campaign->id, $ruleKey, [
                'scheduled_at' => $scheduledAt->format('Y-m-d H:i:s'),
            ])) {
                $summary['skipped']++;

                continue;
            }

            try {
                $result = $this->dispatchSingleCampaign($campaign, $scheduledAt, $ruleKey, $context, false);
                if ($result === null) {
                    $summary['skipped']++;
                    AutomationLog::markExecuted(self::TASK_KEY, 'dispatch', 'coupon_campaign', (int) $campaign->id, $ruleKey, [
                        'scheduled_at' => $scheduledAt->format('Y-m-d H:i:s'),
                        'skipped_reason' => 'already_dispatched_today',
                        'last_dispatched_at' => $campaign->fresh()?->last_dispatched_at?->format('Y-m-d H:i:s'),
                    ]);

                    continue;
                }

                $summary['triggered']++;
                $summary['coupon_ids'][] = (int) ($result['coupon']['id'] ?? 0);
            } catch (\Throwable $exception) {
                $summary['failed']++;
                AutomationLog::forgetRecord(self::TASK_KEY, 'dispatch', 'coupon_campaign', (int) $campaign->id, $ruleKey);

                Log::error('[定时任务] 优惠券活动自动发放失败', [
                    'campaign_id' => (int) $campaign->id,
                    'campaign_name' => (string) $campaign->name,
                    'scheduled_at' => $scheduledAt->format('Y-m-d H:i:s'),
                    'error' => $exception->getMessage(),
                    'exception' => $exception::class,
                ]);
            }
        }

        return $summary;
    }

    private function dispatchSingleCampaign(
        CouponCampaign $campaign,
        CarbonImmutable $dispatchAt,
        string $ruleKey,
        array $context = [],
        bool $manual = false,
    ): ?array {
        $couponResult = DB::transaction(function () use ($campaign, $dispatchAt, $context) {
            $lockedCampaign = CouponCampaign::query()
                ->lockForUpdate()
                ->findOrFail((int) $campaign->id);

            if ($this->campaignDispatchedOnDay($lockedCampaign, $dispatchAt)) {
                return null;
            }

            $payload = $this->buildCouponPayloadFromCampaign($lockedCampaign, $dispatchAt);
            $coupon = $this->couponService->createCoupon($payload, $context);

            $lockedCampaign->forceFill([
                'last_dispatched_at' => $dispatchAt->format('Y-m-d H:i:s'),
                'last_coupon_id' => (int) ($coupon['id'] ?? 0),
                'operator' => (string) ($context['operator'] ?? $lockedCampaign->operator ?? ''),
                'trace_id' => (string) ($context['trace_id'] ?? $lockedCampaign->trace_id ?? ''),
            ])->save();

            return $coupon;
        });

        if ($couponResult === null) {
            return null;
        }

        if (! $manual) {
            AutomationLog::markExecuted(self::TASK_KEY, 'dispatch', 'coupon_campaign', (int) $campaign->id, $ruleKey, [
                'scheduled_at' => $dispatchAt->format('Y-m-d H:i:s'),
                'coupon_id' => (int) ($couponResult['id'] ?? 0),
                'coupon_name' => (string) ($couponResult['name'] ?? ''),
            ]);
        }

        return [
            'coupon' => $couponResult,
        ];
    }

    private function buildAdminCampaignQuery(array $filters = [], bool $applyStatusFilter = true)
    {
        $keyword = trim((string) ($filters['keyword'] ?? ''));
        $status = array_key_exists('status', $filters) ? (string) $filters['status'] : '';

        return CouponCampaign::query()
            ->when($keyword !== '', function ($query) use ($keyword) {
                $query->where(function ($builder) use ($keyword) {
                    $builder
                        ->where('name', 'like', '%'.$keyword.'%')
                        ->orWhere('description', 'like', '%'.$keyword.'%')
                        ->orWhere('remark', 'like', '%'.$keyword.'%');
                });
            })
            ->when($applyStatusFilter && $status !== '', fn ($query) => $query->where('status', (int) $status));
    }

    private function transformCampaignForAdmin(CouponCampaign $campaign, array $productNameMap = []): array
    {
        $weekdays = $this->normalizeWeekdays((array) ($campaign->weekdays ?? []));
        $rawBillingCycles = (array) ($campaign->billing_cycles ?? []);
        $billingCycles = $this->normalizeBillingCycles($rawBillingCycles);
        $productIds = $this->normalizeProductIds((array) ($campaign->product_ids ?? []));
        $coupon = $campaign->lastCoupon;
        $generatedCouponCount = (int) ($campaign->coupons_count ?? 0);
        $canUpdate = $generatedCouponCount === 0;
        $canDelete = $canUpdate;
        $lockReason = $canUpdate ? '' : '活动已生成优惠券批次，不允许修改或删除';

        return [
            'id' => (int) $campaign->id,
            'name' => (string) $campaign->name,
            'description' => (string) ($campaign->description ?? ''),
            'weekdays' => $weekdays,
            'weekdays_text' => $this->formatWeekdaysText($weekdays),
            'trigger_time' => $this->normalizeTimeValue($campaign->trigger_time),
            'trigger_time_text' => substr($this->normalizeTimeValue($campaign->trigger_time), 0, 5),
            'schedule_text' => $this->formatWeekdaysText($weekdays).' '.substr($this->normalizeTimeValue($campaign->trigger_time), 0, 5),
            'issue_quantity' => (int) ($campaign->issue_quantity ?? 0),
            'valid_duration_hours' => $campaign->valid_duration_hours ? (int) $campaign->valid_duration_hours : null,
            'discount_scope' => (string) ($campaign->discount_scope ?? 'first_month'),
            'discount_scope_label' => $this->resolveDiscountScopeLabel((string) ($campaign->discount_scope ?? 'first_month')),
            'discount_type' => (string) ($campaign->discount_type ?? 'fixed'),
            'discount_type_label' => $this->resolveDiscountTypeLabel((string) ($campaign->discount_type ?? 'fixed')),
            'discount_value' => $this->formatAmount((float) ($campaign->discount_value ?? 0)),
            'discount_value_raw' => (float) ($campaign->discount_value ?? 0),
            'discount_label' => $this->buildDiscountLabel((string) ($campaign->discount_type ?? 'fixed'), (float) ($campaign->discount_value ?? 0)),
            'min_amount' => $this->formatAmount((float) ($campaign->min_amount ?? 0)),
            'min_amount_raw' => (float) ($campaign->min_amount ?? 0),
            'max_discount_amount' => $campaign->max_discount_amount !== null
                ? $this->formatAmount((float) $campaign->max_discount_amount)
                : null,
            'max_discount_amount_raw' => $campaign->max_discount_amount !== null
                ? (float) $campaign->max_discount_amount
                : null,
            'billing_cycles' => $billingCycles,
            'billing_cycle_text' => $this->formatBillingCycleText($rawBillingCycles),
            'product_ids' => $productIds,
            'product_scope_text' => $this->formatProductScopeText($productIds, $productNameMap),
            'first_order_only' => (bool) $campaign->first_order_only,
            'per_user_limit' => $campaign->per_user_limit ? (int) $campaign->per_user_limit : null,
            'status' => (int) ($campaign->status ?? CouponStatus::ACTIVE),
            'status_label' => CouponStatus::$labels[(int) ($campaign->status ?? CouponStatus::ACTIVE)] ?? '未知',
            'display_status' => (int) ($campaign->status ?? CouponStatus::ACTIVE) === CouponStatus::ACTIVE ? 'active' : 'disabled',
            'display_status_label' => (int) ($campaign->status ?? CouponStatus::ACTIVE) === CouponStatus::ACTIVE ? '运行中' : '已停用',
            'sort_order' => (int) ($campaign->sort_order ?? 0),
            'generated_coupon_count' => $generatedCouponCount,
            'next_run_at' => $this->resolveNextRunAt($weekdays, $this->normalizeTimeValue($campaign->trigger_time)),
            'last_dispatched_at' => $campaign->last_dispatched_at?->format('Y-m-d H:i:s'),
            'last_coupon_id' => (int) ($campaign->last_coupon_id ?? 0),
            'last_coupon_name' => (string) ($coupon?->name ?? ''),
            'last_coupon_code' => (string) ($coupon?->code ?? ''),
            'remark' => (string) ($campaign->remark ?? ''),
            'operator' => (string) ($campaign->operator ?? ''),
            'trace_id' => (string) ($campaign->trace_id ?? ''),
            'created_at' => $campaign->created_at?->format('Y-m-d H:i:s'),
            'updated_at' => $campaign->updated_at?->format('Y-m-d H:i:s'),
            'can_update' => $canUpdate,
            'can_delete' => $canDelete,
            'lock_reason' => $lockReason,
        ];
    }

    private function campaignHasGeneratedCoupons(CouponCampaign $campaign): bool
    {
        if (array_key_exists('coupons_count', $campaign->getAttributes())) {
            return (int) ($campaign->getAttribute('coupons_count') ?? 0) > 0;
        }

        return $campaign->coupons()->exists();
    }

    private function normalizeAdminCampaignPayload(array $payload, array $context = [], ?CouponCampaign $campaign = null): array
    {
        $discountType = trim((string) ($payload['discount_type'] ?? ''));
        $discountScope = trim((string) ($payload['discount_scope'] ?? 'first_month'));
        $discountValue = round((float) ($payload['discount_value'] ?? 0), 2);
        $minAmount = round((float) ($payload['min_amount'] ?? 0), 2);
        $maxDiscountAmount = $payload['max_discount_amount'] ?? null;
        $weekdays = $this->normalizeWeekdays((array) ($payload['weekdays'] ?? []));
        $triggerTime = $this->normalizeTimeValue($payload['trigger_time'] ?? null);
        $issueQuantity = (int) ($payload['issue_quantity'] ?? 0);
        $validDurationHours = $this->normalizePositiveInteger($payload['valid_duration_hours'] ?? null);

        throw_if(trim((string) ($payload['name'] ?? '')) === '', new BusinessException('活动名称不能为空'));
        throw_if($weekdays === [], new BusinessException('至少选择一个发放星期'));
        throw_if($triggerTime === '', new BusinessException('发放时间不能为空'));
        throw_if(! in_array($discountType, ['fixed', 'percentage'], true), new BusinessException('优惠类型不正确'));
        throw_if(! in_array($discountScope, ['first_month', 'recurring', 'renew'], true), new BusinessException('优惠阶段不正确'));
        throw_if($discountValue <= 0, new BusinessException('优惠值必须大于 0'));
        throw_if($issueQuantity <= 0, new BusinessException('发放数量必须大于 0'));

        if ($discountType === 'percentage') {
            throw_if($discountValue > 100, new BusinessException('折扣百分比不能大于 100'));
        }

        if ($maxDiscountAmount !== null && $maxDiscountAmount !== '') {
            $maxDiscountAmount = round((float) $maxDiscountAmount, 2);
            throw_if($maxDiscountAmount < 0, new BusinessException('最高优惠金额不能小于 0'));
        } else {
            $maxDiscountAmount = null;
        }

        return [
            'name' => trim((string) ($payload['name'] ?? '')),
            'description' => trim((string) ($payload['description'] ?? '')) ?: null,
            'weekdays' => $weekdays,
            'trigger_time' => $triggerTime,
            'issue_quantity' => $issueQuantity,
            'valid_duration_hours' => $validDurationHours,
            'discount_scope' => $discountScope,
            'discount_type' => $discountType,
            'discount_value' => $discountValue,
            'min_amount' => $minAmount,
            'max_discount_amount' => $maxDiscountAmount,
            'billing_cycles' => $this->normalizeBillingCycles((array) ($payload['billing_cycles'] ?? [])),
            'product_ids' => $this->normalizeProductIds((array) ($payload['product_ids'] ?? [])),
            'first_order_only' => (bool) ($payload['first_order_only'] ?? false),
            'per_user_limit' => $this->normalizePositiveInteger($payload['per_user_limit'] ?? null),
            'status' => (int) ($payload['status'] ?? ($campaign?->status ?? CouponStatus::ACTIVE)),
            'sort_order' => max((int) ($payload['sort_order'] ?? 0), 0),
            'remark' => trim((string) ($payload['remark'] ?? '')) ?: null,
            'operator' => (string) ($context['operator'] ?? ''),
            'trace_id' => (string) ($context['trace_id'] ?? ''),
        ];
    }

    private function buildCouponPayloadFromCampaign(CouponCampaign $campaign, CarbonImmutable $dispatchAt): array
    {
        $batchLabel = $dispatchAt->format('m-d H:i');
        $remarkParts = array_filter([
            trim((string) ($campaign->remark ?? '')) ?: null,
            '活动批次：'.$dispatchAt->format('Y-m-d H:i'),
        ]);

        return [
            'coupon_campaign_id' => (int) $campaign->id,
            'name' => trim((string) $campaign->name).' '.$batchLabel,
            'description' => trim((string) ($campaign->description ?? '')) ?: null,
            'distribution_type' => 'public',
            'discount_scope' => (string) ($campaign->discount_scope ?? 'first_month'),
            'discount_type' => (string) ($campaign->discount_type ?? 'fixed'),
            'discount_value' => (float) ($campaign->discount_value ?? 0),
            'min_amount' => (float) ($campaign->min_amount ?? 0),
            'max_discount_amount' => $campaign->max_discount_amount !== null ? (float) $campaign->max_discount_amount : null,
            'billing_cycles' => $this->normalizeBillingCycles((array) ($campaign->billing_cycles ?? [])),
            'product_ids' => $this->normalizeProductIds((array) ($campaign->product_ids ?? [])),
            'first_order_only' => (bool) ($campaign->first_order_only ?? false),
            'total_usage_limit' => (int) ($campaign->issue_quantity ?? 0),
            'per_user_limit' => $campaign->per_user_limit ? (int) $campaign->per_user_limit : null,
            'status' => CouponStatus::ACTIVE,
            'sort_order' => (int) ($campaign->sort_order ?? 0),
            'starts_at' => $dispatchAt->format('Y-m-d H:i:s'),
            'expires_at' => $campaign->valid_duration_hours
                ? $dispatchAt->addHours((int) $campaign->valid_duration_hours)->format('Y-m-d H:i:s')
                : null,
            'remark' => $remarkParts === [] ? null : implode(' / ', $remarkParts),
        ];
    }

    private function resolveDueScheduleAt(CouponCampaign $campaign, CarbonImmutable $now): ?CarbonImmutable
    {
        $weekdays = $this->normalizeWeekdays((array) ($campaign->weekdays ?? []));
        if ($weekdays === [] || ! in_array((int) $now->dayOfWeek, $weekdays, true)) {
            return null;
        }

        $triggerTime = $this->normalizeTimeValue($campaign->trigger_time);
        if ($triggerTime === '') {
            return null;
        }

        $scheduledAt = $now->startOfDay()->setTimeFromTimeString($triggerTime);

        if ($scheduledAt->greaterThan($now)) {
            return null;
        }

        if ($this->campaignDispatchedOnDay($campaign, $now)) {
            return null;
        }

        return $scheduledAt;
    }

    private function campaignDispatchedOnDay(CouponCampaign $campaign, CarbonImmutable $day): bool
    {
        $lastDispatchedAt = $campaign->last_dispatched_at;
        if (! $lastDispatchedAt) {
            return false;
        }

        $lastCarbon = $lastDispatchedAt instanceof \DateTimeInterface
            ? CarbonImmutable::instance($lastDispatchedAt)->setTimezone(config('app.timezone'))
            : CarbonImmutable::parse((string) $lastDispatchedAt, config('app.timezone'));

        return $lastCarbon->isSameDay($day);
    }

    private function resolveNextRunAt(array $weekdays, string $triggerTime): ?string
    {
        if ($weekdays === [] || $triggerTime === '') {
            return null;
        }

        $now = CarbonImmutable::now(config('app.timezone'));

        for ($offset = 0; $offset <= 13; $offset++) {
            $candidateDate = $now->startOfDay()->addDays($offset);
            if (! in_array((int) $candidateDate->dayOfWeek, $weekdays, true)) {
                continue;
            }

            $candidateAt = $candidateDate->setTimeFromTimeString($triggerTime);
            if ($candidateAt->greaterThanOrEqualTo($now)) {
                return $candidateAt->format('Y-m-d H:i:s');
            }
        }

        return null;
    }

    private function formatWeekdaysText(array $weekdays): string
    {
        if ($weekdays === []) {
            return '未配置星期';
        }

        return collect($weekdays)
            ->map(fn (int $weekday) => self::WEEKDAY_LABELS[$weekday] ?? '未知')
            ->implode(' / ');
    }

    private function buildDiscountLabel(string $discountType, float $discountValue): string
    {
        if ($discountType === 'fixed') {
            return '立减 ¥'.$this->formatAmount($discountValue);
        }

        if ($discountType === 'percentage') {
            return rtrim(rtrim(number_format($discountValue / 10, 1, '.', ''), '0'), '.').' 折优惠';
        }

        return '优惠券';
    }

    private function resolveDiscountTypeLabel(string $discountType): string
    {
        return match ($discountType) {
            'fixed' => '满减券',
            'percentage' => '折扣券',
            default => '优惠券',
        };
    }

    private function resolveDiscountScopeLabel(string $discountScope): string
    {
        return match ($discountScope) {
            'recurring' => '持续优惠',
            'renew' => '续费优惠',
            default => '首月优惠',
        };
    }

    private function formatBillingCycleText(array $billingCycles): string
    {
        if (! $this->hasConfiguredBillingCycles($billingCycles)) {
            return '全部周期可用';
        }

        $normalizedBillingCycles = $this->normalizeBillingCycles($billingCycles);
        if ($normalizedBillingCycles === []) {
            return '未配置可用周期';
        }

        return collect($normalizedBillingCycles)
            ->map(fn (string $cycle) => self::SUPPORTED_BILLING_CYCLE_LABELS[$cycle])
            ->implode(' / ');
    }

    private function formatProductScopeText(array $productIds, array $productNameMap): string
    {
        if ($productIds === []) {
            return '全站商品可用';
        }

        $names = array_values(array_filter(array_map(
            fn (int $productId) => $productNameMap[$productId] ?? '',
            $productIds
        )));

        if ($names === []) {
            return '指定商品可用';
        }

        return implode(' / ', $names);
    }

    private function resolveProductNameMapFromCampaigns(Collection $campaigns): array
    {
        $productIds = $campaigns
            ->flatMap(fn (CouponCampaign $campaign) => $this->normalizeProductIds((array) ($campaign->product_ids ?? [])))
            ->unique()
            ->values()
            ->all();

        if ($productIds === []) {
            return [];
        }

        return DB::table('products')
            ->whereIn('id', $productIds)
            ->pluck('name', 'id')
            ->mapWithKeys(fn ($name, $id) => [(int) $id => (string) $name])
            ->all();
    }

    private function normalizeWeekdays(array $weekdays): array
    {
        return collect($weekdays)
            ->map(fn ($day) => (int) $day)
            ->filter(fn (int $day) => array_key_exists($day, self::WEEKDAY_LABELS))
            ->unique()
            ->sort()
            ->values()
            ->all();
    }

    private function normalizeProductIds(array $productIds): array
    {
        return collect($productIds)
            ->map(function ($id) {
                if (is_string($id) && str_starts_with($id, 'group-')) {
                    return null;
                }

                return (int) $id;
            })
            ->filter(fn (?int $id) => $id !== null && $id > 0)
            ->unique()
            ->values()
            ->all();
    }

    private function normalizeBillingCycles(array $billingCycles): array
    {
        return collect($billingCycles)
            ->map(fn ($item) => trim((string) $item))
            ->filter(fn (string $cycle) => $cycle !== '' && array_key_exists($cycle, self::SUPPORTED_BILLING_CYCLE_LABELS))
            ->unique()
            ->values()
            ->all();
    }

    private function hasConfiguredBillingCycles(array $billingCycles): bool
    {
        return collect($billingCycles)
            ->map(fn ($item) => trim((string) $item))
            ->contains(fn (string $cycle) => $cycle !== '');
    }

    private function normalizePositiveInteger(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        $number = (int) $value;

        return $number > 0 ? $number : null;
    }

    private function normalizeTimeValue(mixed $value): string
    {
        $text = trim((string) ($value ?? ''));
        if ($text === '') {
            return '';
        }

        if (preg_match('/^\d{2}:\d{2}$/', $text)) {
            return $text.':00';
        }

        return preg_match('/^\d{2}:\d{2}:\d{2}$/', $text) ? $text : '';
    }

    private function formatAmount(float $amount): string
    {
        return number_format($amount, 2, '.', '');
    }
}
