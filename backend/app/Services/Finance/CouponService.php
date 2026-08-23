<?php

declare(strict_types=1);

namespace App\Services\Finance;

use App\Constants\CouponStatus;
use App\Constants\InvoiceStatus;
use App\Constants\OrderStatus;
use App\Constants\ProductType;
use App\Constants\UserCouponStatus;
use App\Exceptions\BusinessException;
use App\Jobs\SyncInvoiceCouponUsageJob;
use App\Models\Coupon;
use App\Models\FirstProductGroup;
use App\Models\Invoice;
use App\Models\Order;
use App\Models\Product;
use App\Models\User;
use App\Models\UserCoupon;
use App\Services\ProductCatalog\InstanceSpecCatalogService;
use App\Services\ProductCatalog\ProductDisplayNameResolver;
use App\Support\ProductGroupHierarchyFields;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

class CouponService
{
    public function __construct(
        private readonly ?ProductDisplayNameResolver $productDisplayNameResolver = null,
        private readonly ?InstanceSpecCatalogService $instanceSpecCatalogService = null,
    ) {}

    private const USED_INVOICE_STATUSES = [
        InvoiceStatus::PAID,
    ];

    private const USED_ORDER_STATUSES = [
        OrderStatus::PAID,
        OrderStatus::PROCESSING,
        OrderStatus::COMPLETED,
        OrderStatus::REFUNDED,
    ];

    private const SUPPORTED_BILLING_CYCLE_LABELS = [
        'monthly' => '月付',
        'quarterly' => '季付',
        'semiannually' => '半年付',
        'annually' => '年付',
    ];

    public function adminSummary(array $filters = []): array
    {
        if (! $this->hasCouponsTable()) {
            return [
                'total' => 0,
                'active' => 0,
                'expired' => 0,
                'disabled' => 0,
                'public_total' => 0,
                'private_total' => 0,
                'total_used' => 0,
            ];
        }

        $query = $this->buildAdminCouponQuery($filters, false);
        $now = now();

        return [
            'total' => (clone $query)->count(),
            'active' => (clone $query)
                ->where('status', CouponStatus::ACTIVE)
                ->where(function ($builder) use ($now) {
                    $builder->whereNull('starts_at')->orWhere('starts_at', '<=', $now);
                })
                ->where(function ($builder) use ($now) {
                    $builder->whereNull('expires_at')->orWhere('expires_at', '>=', $now);
                })
                ->count(),
            'expired' => (clone $query)
                ->where('status', CouponStatus::ACTIVE)
                ->whereNotNull('expires_at')
                ->where('expires_at', '<', $now)
                ->count(),
            'disabled' => (clone $query)
                ->where('status', CouponStatus::DISABLED)
                ->count(),
            'public_total' => (clone $query)->where('distribution_type', 'public')->count(),
            'private_total' => (clone $query)->where('distribution_type', 'private')->count(),
            'total_used' => (int) ((clone $query)->sum('used_count') ?? 0),
        ];
    }

    public function isCouponFeatureEnabled(): bool
    {
        return $this->hasCouponsTable();
    }

    public function adminList(array $filters = [], int $perPage = 20): LengthAwarePaginator
    {
        if (! $this->hasCouponsTable()) {
            return new \Illuminate\Pagination\LengthAwarePaginator([], 0, $perPage, 1);
        }

        $paginator = $this->buildAdminCouponQuery($filters)
            ->with(['userCoupons:id,coupon_id,user_id,receive_type,last_used_at', 'couponCampaign:id,name'])
            ->withCount([
                'orders',
                'userCoupons',
                'orders as used_orders_count' => fn ($query) => $query->whereIn('status', self::USED_ORDER_STATUSES),
                'invoices as used_invoices_count' => fn ($query) => $query->whereIn('status', self::USED_INVOICE_STATUSES),
                'userCoupons as used_user_coupons_count' => fn ($query) => $query->whereNotNull('last_used_at'),
                'orders as deletion_blocking_orders_count' => fn ($query) => $query->where('status', '!=', OrderStatus::CANCELLED),
                'invoices as deletion_blocking_invoices_count' => fn ($query) => $query->where('status', '!=', InvoiceStatus::CANCELLED),
            ])
            ->orderByDesc('sort_order')
            ->orderByDesc('id')
            ->paginate($perPage);

        $items = collect($paginator->items());
        $productNameMap = $this->resolveProductNameMapFromCoupons($items);

        $paginator->setCollection(
            $items
                ->map(fn (Coupon $coupon) => $this->transformCouponForAdmin($coupon, $productNameMap))
                ->values()
        );

        return $paginator;
    }

    public function createCoupon(array $payload, array $context = []): array
    {
        $this->assertCouponsTableAvailable();

        $coupon = DB::transaction(function () use ($payload, $context) {
            $coupon = Coupon::query()->create(
                $this->normalizeAdminCouponPayload($payload, $context)
            );

            if (($coupon->distribution_type ?? 'public') === 'private') {
                $this->grantCouponToUsers(
                    $coupon,
                    $this->normalizeUserIds((array) ($payload['user_ids'] ?? [])),
                    $context,
                    false
                );
            }

            return $this->loadCouponForAdmin($coupon->fresh());
        });

        return $this->transformCouponForAdmin($coupon, $this->resolveProductNameMapFromCoupons(collect([$coupon])));
    }

    public function updateCoupon(Coupon $coupon, array $payload, array $context = []): array
    {
        $this->assertCouponsTableAvailable();

        $updatedCoupon = DB::transaction(function () use ($coupon, $payload, $context) {
            $lockedCoupon = Coupon::query()
                ->whereKey($coupon->id)
                ->lockForUpdate()
                ->first();

            throw_if(! $lockedCoupon, new BusinessException('优惠券不存在或已删除'));
            $this->assertCouponCanBeUpdated($lockedCoupon, $payload);

            $lockedCoupon->fill(
                $this->normalizeAdminCouponPayload($payload, $context, $lockedCoupon)
            );
            $lockedCoupon->save();

            if (($lockedCoupon->distribution_type ?? 'public') === 'private') {
                $this->grantCouponToUsers(
                    $lockedCoupon,
                    $this->normalizeUserIds((array) ($payload['user_ids'] ?? [])),
                    $context,
                    true
                );
            }

            return $this->loadCouponForAdmin($lockedCoupon->fresh());
        });

        return $this->transformCouponForAdmin($updatedCoupon, $this->resolveProductNameMapFromCoupons(collect([$updatedCoupon])));
    }

    public function toggleCouponStatus(Coupon $coupon, array $context = []): array
    {
        $this->assertCouponsTableAvailable();

        $coupon->forceFill([
            'status' => (int) $coupon->status === CouponStatus::ACTIVE
                ? CouponStatus::DISABLED
                : CouponStatus::ACTIVE,
            'operator' => (string) ($context['operator'] ?? $coupon->operator ?? ''),
            'trace_id' => (string) ($context['trace_id'] ?? $coupon->trace_id ?? ''),
        ])->save();

        return $this->transformCouponForAdmin($coupon->fresh(), $this->resolveProductNameMapFromCoupons(collect([$coupon])));
    }

    public function deleteCoupon(Coupon $coupon, array $context = []): void
    {
        $this->assertCouponsTableAvailable();

        DB::transaction(function () use ($coupon, $context) {
            $lockedCoupon = Coupon::query()
                ->whereKey($coupon->id)
                ->lockForUpdate()
                ->first();

            throw_if(! $lockedCoupon, new BusinessException('优惠券不存在或已删除'));
            throw_if(
                $this->couponHasRecordedUsage($lockedCoupon),
                new BusinessException('该优惠券已有人使用，不能删除')
            );
            throw_if(
                $this->couponHasDeletionBlockingReferences($lockedCoupon),
                new BusinessException('该优惠券仍有关联订单或账单，不能删除')
            );

            if ($this->hasUserCouponsTable()) {
                UserCoupon::query()
                    ->where('coupon_id', $lockedCoupon->id)
                    ->delete();
            }

            $deleted = Coupon::query()
                ->whereKey($lockedCoupon->id)
                ->delete();

            throw_if($deleted !== 1, new BusinessException('优惠券删除失败'));

            $campaignId = (int) ($lockedCoupon->coupon_campaign_id ?? 0);
            if ($campaignId > 0 && Schema::hasTable('coupon_campaigns')) {
                $nextLastCouponId = Coupon::query()
                    ->where('coupon_campaign_id', $campaignId)
                    ->max('id');

                DB::table('coupon_campaigns')
                    ->where('id', $campaignId)
                    ->where('last_coupon_id', $lockedCoupon->id)
                    ->update([
                        'last_coupon_id' => $nextLastCouponId ?: null,
                        'operator' => (string) ($context['operator'] ?? ''),
                        'trace_id' => (string) ($context['trace_id'] ?? ''),
                        'updated_at' => now(),
                    ]);
            }
        });
    }

    public function adminProductTree(): array
    {
        $groups = FirstProductGroup::query()
            ->select(['id', 'code', 'name', 'product_type', 'sort_order', 'is_visible'])
            ->with([
                'secondProductGroups' => fn ($query) => $query
                    ->select(['id', 'first_product_group_id', 'name', 'sort_order', 'is_visible'])
                    ->orderBy('sort_order')
                    ->orderBy('id'),
                'secondProductGroups.thirdProductGroups' => fn ($query) => $query
                    ->select(['id', 'second_product_group_id', 'name', 'sort_order', 'is_visible'])
                    ->orderBy('sort_order')
                    ->orderBy('id'),
            ])
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get();

        $productsByGroup = Product::query()
            ->select([
                'id',
                'product_group_id',
                'service_type_code',
                'product_type',
                'custom_display_name',
                'pricing',
                'status',
                'sort_order',
                'purchase_requires',
                'config_options',
            ])
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get()
            ->groupBy(fn (Product $product) => (int) ($product->product_group_id ?? 0));

        $firstGroupTypePayload = static function (FirstProductGroup $firstGroup): array {
            $productType = ProductType::businessValueForFirstGroup($firstGroup, (string) $firstGroup->code);
            $productTypeLabel = ProductType::businessLabelOf($productType);

            return [
                'first_product_group_code' => (string) $firstGroup->code,
                'product_type' => $productType,
                'product_type_label' => $productTypeLabel,
                'service_type_code' => $productType,
                'service_type_label' => $productTypeLabel,
            ];
        };

        $buildProductNodes = function (int $effectiveGroupId, string $fullName) use ($productsByGroup): array {
            return collect($productsByGroup->get($effectiveGroupId, collect()))
                ->map(function (Product $product) use ($fullName) {
                    $displayPayload = $this->resolveProductDisplayNameResolver()->resolveForProduct($product);
                    $customDisplayName = trim((string) ($displayPayload['custom_display_name'] ?? ''));
                    $productSpecDisplay = trim((string) ($displayPayload['product_spec_display'] ?? ''));
                    $cpuMemoryDisplay = trim((string) ($displayPayload['cpu_memory_display'] ?? ''));
                    $combinedDisplayName = trim((string) ($displayPayload['combined_display_name'] ?? ''));
                    $defaultDisplayName = '未配置规格 #'.(int) $product->id;
                    $displayName = $customDisplayName
                        ?: ($productSpecDisplay ?: ($cpuMemoryDisplay ?: ($combinedDisplayName ?: $defaultDisplayName)));

                    return [
                        'id' => (int) $product->id,
                        'label' => $displayName,
                        'node_type' => 'product',
                        'leaf' => true,
                        'disabled' => false,
                        'product_name' => $displayName,
                        'display_name' => $displayName,
                        'product_display_name' => $displayName,
                        'custom_display_name' => $customDisplayName,
                        'cpu_memory_display' => $cpuMemoryDisplay,
                        'product_spec_display' => $productSpecDisplay,
                        'combined_display_name' => $combinedDisplayName,
                        'effective_product_group_full_name' => $fullName,
                        'primary_price' => $this->resolvePrimaryPrice((array) $product->pricing),
                        'status' => (int) ($product->status ?? 0),
                        'sort_order' => (int) ($product->sort_order ?? 0),
                    ];
                })
                ->values()
                ->all();
        };

        $buildThirdNode = function ($thirdGroup, $firstGroup, $secondGroup, array $path) use ($buildProductNodes, $firstGroupTypePayload): array {
            $thirdId = (int) ($thirdGroup->id ?? 0);
            $currentPath = [...$path, trim((string) $thirdGroup->name)];
            $categoryFullName = implode(' / ', array_values(array_filter($currentPath, fn ($item) => trim((string) $item) !== '')));

            return [
                'id' => 'group-'.$thirdId,
                'label' => (string) $thirdGroup->name,
                'node_type' => 'third_product_group',
                'first_product_group_id' => (int) $firstGroup->id,
                'first_product_group_name' => (string) $firstGroup->name,
                ...$firstGroupTypePayload($firstGroup),
                'second_product_group_id' => (int) $secondGroup->id,
                'second_product_group_name' => (string) $secondGroup->name,
                'third_product_group_id' => $thirdId,
                'third_product_group_name' => (string) $thirdGroup->name,
                'effective_product_group_id' => $thirdId,
                'effective_product_group_level' => 3,
                'effective_product_group_full_name' => $categoryFullName,
                'leaf' => false,
                'disabled' => false,
                'children' => $buildProductNodes($thirdId, $categoryFullName),
            ];
        };

        $buildSecondNode = function ($secondGroup, $firstGroup, array $path) use ($buildThirdNode, $buildProductNodes, $firstGroupTypePayload): array {
            $secondId = (int) ($secondGroup->id ?? 0);
            $currentPath = [...$path, trim((string) $secondGroup->name)];
            $categoryFullName = implode(' / ', array_values(array_filter($currentPath, fn ($item) => trim((string) $item) !== '')));
            $children = collect($secondGroup->thirdProductGroups ?? [])
                ->map(fn ($thirdGroup) => $buildThirdNode($thirdGroup, $firstGroup, $secondGroup, $currentPath))
                ->values()
                ->all();

            $directProducts = $buildProductNodes($secondId, $categoryFullName);

            if ($children !== [] && $directProducts !== []) {
                // 有三级分组时，直属产品归入虚拟"其他"分组，避免产品与分类同级显示
                $otherPath = [...$currentPath, '其他'];
                $otherFullName = implode(' / ', array_values(array_filter($otherPath, fn ($item) => trim((string) $item) !== '')));
                $children[] = [
                    'id' => 'group-'.$secondId.'-other',
                    'label' => '其他',
                    'node_type' => 'third_product_group',
                    'first_product_group_id' => (int) $firstGroup->id,
                    'first_product_group_name' => (string) $firstGroup->name,
                    ...$firstGroupTypePayload($firstGroup),
                    'second_product_group_id' => $secondId,
                    'second_product_group_name' => (string) $secondGroup->name,
                    'third_product_group_id' => null,
                    'third_product_group_name' => '其他',
                    'effective_product_group_id' => $secondId,
                    'effective_product_group_level' => 3,
                    'effective_product_group_full_name' => $otherFullName,
                    'leaf' => false,
                    'disabled' => false,
                    'children' => $directProducts,
                ];
            }

            return [
                'id' => 'group-'.$secondId,
                'label' => (string) $secondGroup->name,
                'node_type' => 'second_product_group',
                'first_product_group_id' => (int) $firstGroup->id,
                'first_product_group_name' => (string) $firstGroup->name,
                ...$firstGroupTypePayload($firstGroup),
                'second_product_group_id' => $secondId,
                'second_product_group_name' => (string) $secondGroup->name,
                'third_product_group_id' => null,
                'third_product_group_name' => null,
                'effective_product_group_id' => $secondId,
                'effective_product_group_level' => 2,
                'effective_product_group_full_name' => $categoryFullName,
                'leaf' => false,
                'disabled' => false,
                'children' => [...$children, ...($children === [] ? $directProducts : [])],
            ];
        };

        $buildFirstNode = function (FirstProductGroup $firstGroup) use ($buildSecondNode, $firstGroupTypePayload): array {
            $path = [trim((string) $firstGroup->name)];
            $children = collect($firstGroup->secondProductGroups ?? [])
                ->map(fn ($secondGroup) => $buildSecondNode($secondGroup, $firstGroup, $path))
                ->values()
                ->all();

            return [
                'id' => 'first-'.$firstGroup->id,
                'label' => (string) $firstGroup->name,
                'node_type' => 'first_product_group',
                'first_product_group_id' => (int) $firstGroup->id,
                'first_product_group_name' => (string) $firstGroup->name,
                ...$firstGroupTypePayload($firstGroup),
                'leaf' => false,
                'disabled' => false,
                'children' => $children,
            ];
        };

        return $groups->map(fn (FirstProductGroup $group) => $buildFirstNode($group))->values()->all();
    }

    /**
     * @return array{cycle: string, amount: string}
     */
    private function resolvePrimaryPrice(array $pricing): array
    {
        foreach ($pricing as $cycle => $amount) {
            if ((float) $amount > 0) {
                return [
                    'cycle' => (string) $cycle,
                    'amount' => number_format((float) $amount, 2, '.', ''),
                ];
            }
        }

        return [
            'cycle' => '',
            'amount' => '0.00',
        ];
    }

    public function paginateForUser(User $user, array $filters, int $page = 1, int $pageSize = 15): array
    {
        if (! $this->hasCouponsTable()) {
            return [
                'list' => [],
                'total' => 0,
                'page' => max($page, 1),
                'page_size' => max($pageSize, 1),
            ];
        }

        $currentPage = max($page, 1);
        $pageSize = max($pageSize, 1);
        $hasPlacedOrder = $this->hasUserPlacedOrder((int) $user->id);
        $paginator = $this->buildOwnedCouponPageQuery($user, $filters)
            ->orderByDesc('user_coupons.id')
            ->paginate($pageSize, ['user_coupons.*'], 'page', $currentPage);
        $items = $this->transformOwnedCouponCollection($paginator->getCollection(), $hasPlacedOrder);

        return [
            'list' => $items->values()->all(),
            'total' => $paginator->total(),
            'page' => $paginator->currentPage(),
            'page_size' => $paginator->perPage(),
        ];
    }

    public function summaryForUser(User $user, array $filters = []): array
    {
        if (! $this->hasCouponsTable()) {
            return [
                'total' => 0,
                'available' => 0,
                'used_up' => 0,
                'expired' => 0,
            ];
        }

        $hasPlacedOrder = $this->hasUserPlacedOrder((int) $user->id);
        $baseQuery = $this->buildOwnedCouponPageQuery($user, [
            'keyword' => $filters['keyword'] ?? null,
        ], false);
        $userId = (int) $user->id;

        return [
            'total' => (clone $baseQuery)->count(),
            'available' => $this->countOwnedCouponsByStatus(clone $baseQuery, 'available', $hasPlacedOrder, $userId),
            'used_up' => $this->countOwnedCouponsByStatus(clone $baseQuery, 'used_up', $hasPlacedOrder, $userId),
            'expired' => $this->countOwnedCouponsByStatus(clone $baseQuery, 'expired', $hasPlacedOrder, $userId),
        ];
    }

    public function paginatePublicForUser(User $user, array $filters, int $page = 1, int $pageSize = 15): array
    {
        if (! $this->hasCouponsTable()) {
            return [
                'list' => [],
                'total' => 0,
                'page' => max($page, 1),
                'page_size' => max($pageSize, 1),
            ];
        }

        $currentPage = max($page, 1);
        $pageSize = max($pageSize, 1);
        $paginator = $this->buildPublicClaimableCouponQuery($user, $filters)
            ->orderByDesc('sort_order')
            ->orderByDesc('id')
            ->paginate($pageSize, ['coupons.*'], 'page', $currentPage);
        $items = $this->transformPublicCouponCollection($paginator->getCollection());

        return [
            'list' => $items->values()->all(),
            'total' => $paginator->total(),
            'page' => $paginator->currentPage(),
            'page_size' => $paginator->perPage(),
        ];
    }

    public function summaryPublicForUser(User $user, array $filters = []): array
    {
        if (! $this->hasCouponsTable()) {
            return [
                'total' => 0,
                'available' => 0,
                'used_up' => 0,
                'expired' => 0,
            ];
        }

        $baseQuery = $this->buildPublicClaimableCouponQuery($user, [
            'keyword' => $filters['keyword'] ?? null,
        ], false);

        return [
            'total' => (clone $baseQuery)->count(),
            'available' => $this->countPublicCouponsByStatus(clone $baseQuery, 'available'),
            'used_up' => $this->countPublicCouponsByStatus(clone $baseQuery, 'used_up'),
            'expired' => $this->countPublicCouponsByStatus(clone $baseQuery, 'expired'),
        ];
    }

    public function claimPublicCoupon(User $user, Coupon $coupon): array
    {
        $this->assertCouponsTableAvailable();
        throw_if(! $this->hasUserCouponsTable(), new BusinessException('当前系统未启用已领优惠券功能'));

        $userCoupon = DB::transaction(function () use ($user, $coupon) {
            $lockedCoupon = Coupon::query()
                ->whereKey($coupon->id)
                ->lockForUpdate()
                ->first();

            throw_if(! $lockedCoupon, new BusinessException('优惠券不存在或已失效'));

            $this->assertCouponCanBeClaimedByUser($lockedCoupon, (int) $user->id);

            return UserCoupon::query()->create([
                'coupon_id' => (int) $lockedCoupon->id,
                'user_id' => (int) $user->id,
                'receive_type' => 'claim',
                'status' => 1,
                'claimed_at' => now(),
            ]);
        });

        $ownedItem = $this->transformOwnedCouponCollection(
            $this->buildOwnedCouponPageQuery($user, [], false)
                ->where('user_coupons.id', (int) $userCoupon->id)
                ->get(),
            $this->hasUserPlacedOrder((int) $user->id)
        )->first(fn (array $item) => (int) ($item['id'] ?? 0) === (int) $userCoupon->id);

        return $ownedItem ?: [
            'id' => (int) $userCoupon->id,
            'coupon_id' => (int) $userCoupon->coupon_id,
        ];
    }

    public function availableCouponsForCheckout(int $userId, Product $product, string $billingCycle, float $amount, string $orderType = 'new'): array
    {
        if (! $this->hasCouponsTable() || ! $this->hasUserCouponsTable()) {
            return [];
        }

        $userCoupons = UserCoupon::query()
            ->with('coupon')
            ->where('user_id', $userId)
            ->whereHas('coupon')
            ->where('status', 1)
            ->orderByDesc('id')
            ->get();

        if ($userCoupons->isEmpty()) {
            return [];
        }

        return $userCoupons
            ->map(function (UserCoupon $userCoupon) use ($product, $billingCycle, $amount, $userId, $orderType) {
                try {
                    $payload = $this->buildOwnedCouponPayload($userCoupon, $product, $billingCycle, $amount, $userId, $orderType);

                    return [
                        'id' => (int) $userCoupon->id,
                        'coupon_id' => (int) ($payload['coupon_id'] ?? 0),
                        'name' => (string) ($payload['name'] ?? ''),
                        'description' => (string) ($payload['description'] ?? ''),
                        'discount_type' => (string) ($payload['discount_type'] ?? ''),
                        'discount_label' => (string) ($payload['discount_label'] ?? ''),
                        'discount_amount' => (string) ($payload['discount_amount'] ?? '0.00'),
                        'final_amount' => (string) ($payload['final_amount'] ?? '0.00'),
                        'product_scope_text' => (string) ($payload['product_scope_text'] ?? ''),
                        'billing_cycle_text' => (string) ($payload['billing_cycle_text'] ?? ''),
                        'validity_text' => (string) ($payload['validity_text'] ?? ''),
                    ];
                } catch (\Throwable) {
                    return null;
                }
            })
            ->filter()
            ->values()
            ->all();
    }

    public function previewOwnedCoupon(?int $userCouponId, int $userId, Product $product, string $billingCycle, float $amount, string $orderType = 'new'): ?array
    {
        if (! $this->hasCouponsTable() || ! $this->hasUserCouponsTable()) {
            return null;
        }

        $resolvedUserCouponId = (int) ($userCouponId ?? 0);
        if ($resolvedUserCouponId <= 0) {
            return null;
        }

        $userCoupon = UserCoupon::query()
            ->with('coupon')
            ->where('user_id', $userId)
            ->whereHas('coupon')
            ->find($resolvedUserCouponId);

        throw_if(! $userCoupon, new BusinessException('优惠券不存在或已失效'));

        return $this->buildOwnedCouponPayload($userCoupon, $product, $billingCycle, $amount, $userId, $orderType);
    }

    public function reserveOwnedCouponForInvoice(?int $userCouponId, int $userId, Product $product, string $billingCycle, float $amount, string $orderType = 'new'): ?array
    {
        if (! $this->hasUserCouponsTable()) {
            return null;
        }

        $resolvedUserCouponId = (int) ($userCouponId ?? 0);
        if ($resolvedUserCouponId <= 0) {
            return null;
        }

        $userCoupon = UserCoupon::query()
            ->with('coupon')
            ->where('user_id', $userId)
            ->whereHas('coupon')
            ->where('status', UserCouponStatus::OWNED)
            ->where(function ($query): void {
                $query->whereNull('reserved_until')
                    ->orWhere('reserved_until', '<', now());
            })
            ->lockForUpdate()
            ->find($resolvedUserCouponId);

        throw_if(! $userCoupon, new BusinessException('优惠券不存在或已失效'));

        // 防双花兜底：该券已有已支付账单但异步同步尚未完成（reserved_until 已过期的窗口），
        // 直接拒绝再次占用，避免同一张券重复抵扣
        throw_if(
            Invoice::query()
                ->where('user_id', $userId)
                ->where('user_coupon_id', $resolvedUserCouponId)
                ->whereIn('status', self::USED_INVOICE_STATUSES)
                ->exists(),
            new BusinessException('优惠券已使用')
        );

        $payload = $this->buildOwnedCouponPayload($userCoupon, $product, $billingCycle, $amount, $userId, $orderType);
        $userCoupon->forceFill([
            'reserved_until' => now()->addMinutes(10),
        ])->save();

        return $payload;
    }

    public function reserveOwnedCouponForOrder(?int $userCouponId, int $userId, Product $product, string $billingCycle, float $amount, string $orderType = 'new'): ?array
    {
        return $this->reserveOwnedCouponForInvoice($userCouponId, $userId, $product, $billingCycle, $amount, $orderType);
    }

    public function releaseInvoiceCoupon(Invoice $invoice): void
    {
        $this->syncInvoiceCouponUsage($invoice);
    }

    /**
     * @deprecated Use syncInvoiceCouponUsage() with the related invoice instead.
     */
    public function releaseOrderCoupon(Order $order): void
    {
        $order->loadMissing('invoice');
        if ($order->invoice instanceof Invoice) {
            $this->syncInvoiceCouponUsage($order->invoice);
        }
    }

    /**
     * @deprecated Use syncInvoiceCouponUsage() with the related invoice instead.
     */
    public function syncOrderCouponUsage(Order $order): void
    {
        $order->loadMissing('invoice');
        if ($order->invoice instanceof Invoice) {
            $this->syncInvoiceCouponUsage($order->invoice);

            return;
        }

        $couponId = (int) ($order->coupon_id ?? 0);
        $userCouponId = (int) ($order->user_coupon_id ?? 0);
        if ($couponId <= 0 || $userCouponId <= 0) {
            return;
        }

        $usedCount = Order::query()
            ->where('coupon_id', $couponId)
            ->whereIn('status', [OrderStatus::PAID, OrderStatus::REFUNDED])
            ->count();

        Coupon::query()
            ->where('id', $couponId)
            ->update([
                'used_count' => $usedCount,
            ]);

        $currentOrderUsed = in_array((int) ($order->status ?? 0), [OrderStatus::PAID, OrderStatus::REFUNDED], true);
        UserCoupon::query()
            ->where('id', $userCouponId)
            ->update([
                'last_used_at' => $currentOrderUsed ? ($order->paid_at ?? now()) : null,
            ]);
    }

    /**
     * @deprecated Use syncInvoiceCouponUsageAfterResponse() with the related invoice instead.
     */
    public function syncOrderCouponUsageAfterResponse(Order $order): void
    {
        $order->loadMissing('invoice');
        if ($order->invoice instanceof Invoice) {
            $this->syncInvoiceCouponUsageAfterResponse($order->invoice);
        }
    }

    public function syncInvoiceCouponUsageAfterResponse(Invoice $invoice): void
    {
        $invoiceId = (int) $invoice->id;

        if ($invoiceId <= 0) {
            return;
        }

        SyncInvoiceCouponUsageJob::dispatch($invoiceId)->onQueue('coupon');
    }

    public function syncInvoiceCouponUsage(Invoice $invoice): void
    {
        $couponId = (int) ($invoice->coupon_id ?? 0);
        $userCouponId = (int) ($invoice->user_coupon_id ?? 0);

        if ($couponId <= 0 && $userCouponId <= 0) {
            return;
        }

        DB::transaction(function () use ($couponId, $userCouponId) {
            if ($couponId > 0) {
                $coupon = Coupon::query()
                    ->whereKey($couponId)
                    ->lockForUpdate()
                    ->first();

                if ($coupon instanceof Coupon) {
                    $coupon->forceFill([
                        'used_count' => $this->countCouponUsedInvoices($couponId),
                    ])->save();
                }
            }

            if ($userCouponId > 0 && $this->hasUserCouponsTable()) {
                $userCoupon = UserCoupon::query()
                    ->with('coupon')
                    ->whereKey($userCouponId)
                    ->lockForUpdate()
                    ->first();

                if ($userCoupon instanceof UserCoupon) {
                    if ((int) $userCoupon->status === UserCouponStatus::REVOKED) {
                        return;
                    }

                    $lastUsedAt = $this->resolveUserCouponLastUsedAt($userCouponId);
                    $hasActivePaidInvoice = Invoice::query()
                        ->where('user_coupon_id', $userCouponId)
                        ->whereIn('status', self::USED_INVOICE_STATUSES)
                        ->exists();

                    if ($hasActivePaidInvoice) {
                        $resolvedUsedAt = $userCoupon->used_at ?? $lastUsedAt ?? now();
                        $userCoupon->forceFill([
                            'status' => UserCouponStatus::USED,
                            'used_at' => $resolvedUsedAt,
                            'last_used_at' => $lastUsedAt ?? $resolvedUsedAt,
                            'reserved_until' => null,
                        ])->save();

                        return;
                    }

                    if ($this->shouldKeepFirstOrderUserCouponUsed($userCoupon)) {
                        $resolvedUsedAt = $userCoupon->used_at ?? $userCoupon->last_used_at ?? now();
                        $userCoupon->forceFill([
                            'status' => UserCouponStatus::USED,
                            'used_at' => $resolvedUsedAt,
                            'last_used_at' => $userCoupon->last_used_at ?? $resolvedUsedAt,
                            'reserved_until' => null,
                        ])->save();

                        return;
                    }

                    $userCoupon->forceFill([
                        'status' => UserCouponStatus::OWNED,
                        'used_at' => null,
                        'last_used_at' => null,
                        'reserved_until' => null,
                    ])->save();
                }
            }
        });
    }

    private function buildAdminCouponQuery(array $filters = [], bool $applyStatusFilter = true)
    {
        $keyword = trim((string) ($filters['keyword'] ?? ''));
        $discountType = trim((string) ($filters['discount_type'] ?? ''));
        $distributionType = trim((string) ($filters['distribution_type'] ?? ''));
        $discountScope = trim((string) ($filters['discount_scope'] ?? ''));
        $statusFilter = array_key_exists('status', $filters) ? (string) $filters['status'] : '';
        $now = now();

        return Coupon::query()
            ->when($keyword !== '', function ($query) use ($keyword) {
                $query->where(function ($builder) use ($keyword) {
                    $builder
                        ->where('name', 'like', '%'.$keyword.'%')
                        ->orWhere('description', 'like', '%'.$keyword.'%');
                });
            })
            ->when($discountType !== '', fn ($query) => $query->where('discount_type', $discountType))
            ->when($distributionType !== '', fn ($query) => $query->where('distribution_type', $distributionType))
            ->when($discountScope !== '', fn ($query) => $query->where('discount_scope', $discountScope))
            ->when($applyStatusFilter && $statusFilter !== '', function ($query) use ($statusFilter, $now) {
                if ($statusFilter === 'expired') {
                    $query
                        ->where('status', CouponStatus::ACTIVE)
                        ->whereNotNull('expires_at')
                        ->where('expires_at', '<', $now);

                    return;
                }

                if ($statusFilter === '1') {
                    $query
                        ->where('status', CouponStatus::ACTIVE)
                        ->where(function ($builder) use ($now) {
                            $builder->whereNull('starts_at')->orWhere('starts_at', '<=', $now);
                        })
                        ->where(function ($builder) use ($now) {
                            $builder->whereNull('expires_at')->orWhere('expires_at', '>=', $now);
                        });

                    return;
                }

                $query->where('status', (int) $statusFilter);
            });
    }

    private function buildOwnedCouponItems(User $user, array $filters = []): Collection
    {
        if (! $this->hasUserCouponsTable()) {
            return collect();
        }

        $keyword = trim((string) ($filters['keyword'] ?? ''));
        $statusFilter = trim((string) ($filters['status'] ?? 'all'));
        $hasPlacedOrder = $this->hasUserPlacedOrder((int) $user->id);

        $userCoupons = UserCoupon::query()
            ->with('coupon')
            ->where('user_id', $user->id)
            ->whereHas('coupon')
            ->when($keyword !== '', function ($query) use ($keyword) {
                $query->whereHas('coupon', function ($couponQuery) use ($keyword) {
                    $couponQuery->where(function ($builder) use ($keyword) {
                        $builder
                            ->where('name', 'like', '%'.$keyword.'%')
                            ->orWhere('description', 'like', '%'.$keyword.'%');
                    });
                });
            })
            ->orderByDesc('id')
            ->get();

        if ($userCoupons->isEmpty()) {
            return collect();
        }

        $couponIds = $userCoupons->pluck('coupon_id')->map(fn ($id) => (int) $id)->unique()->all();
        $userUsedCounts = Invoice::query()
            ->selectRaw('coupon_id, COUNT(*) as used_count')
            ->where('user_id', $user->id)
            ->whereNotNull('coupon_id')
            ->where('status', '!=', InvoiceStatus::CANCELLED)
            ->whereIn('coupon_id', $couponIds)
            ->groupBy('coupon_id')
            ->pluck('used_count', 'coupon_id');

        $productNameMap = $this->resolveProductNameMapFromCoupons(
            $userCoupons->map(fn (UserCoupon $userCoupon) => $userCoupon->coupon)->filter()
        );

        $items = $userCoupons->map(function (UserCoupon $userCoupon) use ($userUsedCounts, $productNameMap, $hasPlacedOrder) {
            return $this->transformOwnedCouponForUser(
                $userCoupon,
                (int) ($userUsedCounts[(int) $userCoupon->coupon_id] ?? 0),
                $hasPlacedOrder,
                $productNameMap
            );
        });

        if (in_array($statusFilter, ['available', 'used_up', 'expired'], true)) {
            $items = $items->where('status', $statusFilter)->values();
        }

        return $items->values();
    }

    private function buildOwnedCouponPageQuery(User $user, array $filters = [], bool $applyStatusFilter = true)
    {
        $userId = (int) $user->id;
        $keyword = trim((string) ($filters['keyword'] ?? ''));
        $statusFilter = trim((string) ($filters['status'] ?? 'all'));
        $hasPlacedOrder = $this->hasUserPlacedOrder($userId);

        $query = UserCoupon::query()
            ->select('user_coupons.*')
            ->join('coupons', 'coupons.id', '=', 'user_coupons.coupon_id')
            ->with('coupon')
            ->where('user_coupons.user_id', $userId)
            ->when($keyword !== '', function ($builder) use ($keyword) {
                $builder->where(function ($query) use ($keyword) {
                    $query->where('coupons.name', 'like', '%'.$keyword.'%')
                        ->orWhere('coupons.description', 'like', '%'.$keyword.'%');
                });
            })
            ->selectRaw('('.$this->ownedCouponUsageCountSql().') as user_used_count', [$userId, InvoiceStatus::CANCELLED]);

        if ($applyStatusFilter && in_array($statusFilter, ['available', 'used_up', 'expired'], true)) {
            $this->applyOwnedCouponStatusFilter($query, $statusFilter, $hasPlacedOrder, $userId);
        }

        return $query;
    }

    private function transformOwnedCouponCollection(Collection $userCoupons, bool $hasPlacedOrder): Collection
    {
        if ($userCoupons->isEmpty()) {
            return collect();
        }

        $productNameMap = $this->resolveProductNameMapFromCoupons(
            $userCoupons->map(fn (UserCoupon $userCoupon) => $userCoupon->coupon)->filter()
        );

        return $userCoupons->map(function (UserCoupon $userCoupon) use ($productNameMap, $hasPlacedOrder) {
            return $this->transformOwnedCouponForUser(
                $userCoupon,
                (int) ($userCoupon->getAttribute('user_used_count') ?? 0),
                $hasPlacedOrder,
                $productNameMap
            );
        });
    }

    private function buildClaimablePublicCouponItems(User $user, array $filters = []): Collection
    {
        $keyword = trim((string) ($filters['keyword'] ?? ''));
        $statusFilter = trim((string) ($filters['status'] ?? 'all'));
        $ownedCouponIds = $this->hasUserCouponsTable()
            ? UserCoupon::query()
                ->where('user_id', $user->id)
                ->pluck('coupon_id')
                ->map(fn ($id) => (int) $id)
                ->all()
            : [];

        $coupons = Coupon::query()
            ->where('distribution_type', 'public')
            ->when($ownedCouponIds !== [], fn ($query) => $query->whereNotIn('id', $ownedCouponIds))
            ->when($keyword !== '', function ($query) use ($keyword) {
                $query->where(function ($builder) use ($keyword) {
                    $builder
                        ->where('name', 'like', '%'.$keyword.'%')
                        ->orWhere('description', 'like', '%'.$keyword.'%');
                });
            })
            ->withCount('userCoupons')
            ->orderByDesc('sort_order')
            ->orderByDesc('id')
            ->get();

        if ($coupons->isEmpty()) {
            return collect();
        }

        $productNameMap = $this->resolveProductNameMapFromCoupons($coupons);
        $items = $coupons->map(fn (Coupon $coupon) => $this->transformPublicCouponForUser($coupon, $productNameMap));

        if (in_array($statusFilter, ['available', 'used_up', 'expired'], true)) {
            $items = $items->where('status', $statusFilter)->values();
        }

        return $items->values();
    }

    private function buildPublicClaimableCouponQuery(User $user, array $filters = [], bool $applyStatusFilter = true)
    {
        $keyword = trim((string) ($filters['keyword'] ?? ''));
        $statusFilter = trim((string) ($filters['status'] ?? 'all'));
        $userId = (int) $user->id;

        $query = Coupon::query()
            ->where('distribution_type', 'public')
            ->whereNotExists(function ($builder) use ($userId) {
                $builder->selectRaw('1')
                    ->from('user_coupons')
                    ->whereColumn('user_coupons.coupon_id', 'coupons.id')
                    ->where('user_coupons.user_id', $userId);
            })
            ->when($keyword !== '', function ($builder) use ($keyword) {
                $builder->where(function ($query) use ($keyword) {
                    $query->where('name', 'like', '%'.$keyword.'%')
                        ->orWhere('description', 'like', '%'.$keyword.'%');
                });
            })
            ->withCount('userCoupons');

        if ($applyStatusFilter && in_array($statusFilter, ['available', 'used_up', 'expired'], true)) {
            $this->applyPublicCouponStatusFilter($query, $statusFilter);
        }

        return $query;
    }

    private function transformPublicCouponCollection(Collection $coupons): Collection
    {
        if ($coupons->isEmpty()) {
            return collect();
        }

        $productNameMap = $this->resolveProductNameMapFromCoupons($coupons);

        return $coupons->map(fn (Coupon $coupon) => $this->transformPublicCouponForUser($coupon, $productNameMap));
    }

    private function applyOwnedCouponStatusFilter($query, string $statusFilter, bool $hasPlacedOrder, int $userId): void
    {
        $now = now();

        if ($statusFilter === 'expired') {
            $query->where(function ($builder) use ($now) {
                $builder->where('user_coupons.status', '!=', 1)
                    ->orWhere('coupons.status', '!=', CouponStatus::ACTIVE)
                    ->orWhere(function ($subQuery) {
                        $subQuery->where('coupons.distribution_type', 'private')
                            ->where(function ($receiveTypeQuery) {
                                $receiveTypeQuery->whereNull('user_coupons.receive_type')
                                    ->orWhere('user_coupons.receive_type', '!=', 'grant');
                            });
                    })
                    ->orWhere(function ($subQuery) use ($now) {
                        $subQuery->whereNotNull('coupons.starts_at')->where('coupons.starts_at', '>', $now);
                    })
                    ->orWhere(function ($subQuery) use ($now) {
                        $subQuery->whereNotNull('coupons.expires_at')->where('coupons.expires_at', '<', $now);
                    });
            });

            return;
        }

        $query->where('user_coupons.status', 1)
            ->where('coupons.status', CouponStatus::ACTIVE)
            ->where(function ($builder) use ($now) {
                $builder->whereNull('coupons.starts_at')->orWhere('coupons.starts_at', '<=', $now);
            })
            ->where(function ($builder) use ($now) {
                $builder->whereNull('coupons.expires_at')->orWhere('coupons.expires_at', '>=', $now);
            })
            ->where(function ($builder) {
                $builder->where('coupons.distribution_type', '!=', 'private')
                    ->orWhere('user_coupons.receive_type', 'grant');
            });

        if ($statusFilter === 'used_up') {
            $query->where(function ($builder) use ($hasPlacedOrder, $userId) {
                if ($hasPlacedOrder) {
                    $builder->where('coupons.first_order_only', 1);
                }

                $builder->orWhere(function ($subQuery) use ($userId) {
                    $subQuery->where('coupons.per_user_limit', '>', 0)
                        ->whereRaw('('.$this->ownedCouponUsageCountSql().') >= coupons.per_user_limit', [$userId, InvoiceStatus::CANCELLED]);
                });
            });

            return;
        }

        if ($hasPlacedOrder) {
            $query->where('coupons.first_order_only', 0);
        }

        $query->where(function ($builder) use ($userId) {
            $builder->whereNull('coupons.per_user_limit')
                ->orWhere('coupons.per_user_limit', '<=', 0)
                ->orWhereRaw('('.$this->ownedCouponUsageCountSql().') < coupons.per_user_limit', [$userId, InvoiceStatus::CANCELLED]);
        });
    }

    private function applyPublicCouponStatusFilter($query, string $statusFilter): void
    {
        $now = now();

        if ($statusFilter === 'expired') {
            $query->where(function ($builder) use ($now) {
                $builder->where('status', '!=', CouponStatus::ACTIVE)
                    ->orWhere(function ($subQuery) use ($now) {
                        $subQuery->whereNotNull('starts_at')->where('starts_at', '>', $now);
                    })
                    ->orWhere(function ($subQuery) use ($now) {
                        $subQuery->whereNotNull('expires_at')->where('expires_at', '<', $now);
                    });
            });

            return;
        }

        $query->where('status', CouponStatus::ACTIVE)
            ->where(function ($builder) use ($now) {
                $builder->whereNull('starts_at')->orWhere('starts_at', '<=', $now);
            })
            ->where(function ($builder) use ($now) {
                $builder->whereNull('expires_at')->orWhere('expires_at', '>=', $now);
            });

        if ($statusFilter === 'used_up') {
            $query->where('total_usage_limit', '>', 0)
                ->whereRaw('('.$this->publicCouponClaimCountSql().') >= coupons.total_usage_limit');

            return;
        }

        $query->where(function ($builder) {
            $builder->whereNull('total_usage_limit')
                ->orWhere('total_usage_limit', '<=', 0)
                ->orWhereRaw('('.$this->publicCouponClaimCountSql().') < coupons.total_usage_limit');
        });
    }

    private function countOwnedCouponsByStatus($query, string $statusFilter, bool $hasPlacedOrder, int $userId): int
    {
        $this->applyOwnedCouponStatusFilter($query, $statusFilter, $hasPlacedOrder, $userId);

        return (int) $query->count();
    }

    private function countPublicCouponsByStatus($query, string $statusFilter): int
    {
        $this->applyPublicCouponStatusFilter($query, $statusFilter);

        return (int) $query->count();
    }

    private function ownedCouponUsageCountSql(): string
    {
        return 'SELECT COUNT(*) FROM invoices WHERE invoices.user_id = ? AND invoices.coupon_id = user_coupons.coupon_id AND invoices.status != ?';
    }

    private function publicCouponClaimCountSql(): string
    {
        return 'SELECT COUNT(*) FROM user_coupons WHERE user_coupons.coupon_id = coupons.id';
    }

    private function transformCouponForAdmin(Coupon $coupon, array $productNameMap): array
    {
        $productIds = $this->normalizeProductIds((array) ($coupon->product_ids ?? []));
        $rawBillingCycles = (array) ($coupon->billing_cycles ?? []);
        $billingCycles = $this->normalizeBillingCycles($rawBillingCycles);
        $displayStatus = $this->resolveAdminDisplayStatus($coupon);
        $canUpdate = $this->couponCanBeUpdatedForAdmin($coupon);
        $canDelete = $this->couponCanBeDeletedForAdmin($coupon);
        $lockReason = $this->resolveCouponLockReason($coupon);

        return [
            'id' => (int) $coupon->id,
            'coupon_campaign_id' => (int) ($coupon->coupon_campaign_id ?? 0),
            'coupon_campaign_name' => (string) ($coupon->couponCampaign?->name ?? ''),
            'name' => (string) $coupon->name,
            'description' => (string) ($coupon->description ?? ''),
            'distribution_type' => (string) ($coupon->distribution_type ?? 'public'),
            'distribution_type_label' => $this->resolveDistributionTypeLabel((string) ($coupon->distribution_type ?? 'public')),
            'discount_scope' => (string) ($coupon->discount_scope ?? 'first_month'),
            'discount_scope_label' => $this->resolveDiscountScopeLabel((string) ($coupon->discount_scope ?? 'first_month')),
            'discount_type' => (string) $coupon->discount_type,
            'discount_type_label' => $this->resolveDiscountTypeLabel((string) $coupon->discount_type),
            'discount_value' => $this->formatAmount((float) ($coupon->discount_value ?? 0)),
            'discount_value_raw' => (float) ($coupon->discount_value ?? 0),
            'discount_label' => $this->buildDiscountLabel($coupon),
            'min_amount' => $this->formatAmount((float) ($coupon->min_amount ?? 0)),
            'min_amount_raw' => (float) ($coupon->min_amount ?? 0),
            'max_discount_amount' => $coupon->max_discount_amount !== null
                ? $this->formatAmount((float) $coupon->max_discount_amount)
                : null,
            'max_discount_amount_raw' => $coupon->max_discount_amount !== null
                ? (float) $coupon->max_discount_amount
                : null,
            'billing_cycles' => $billingCycles,
            'billing_cycle_text' => $this->formatBillingCycleText($rawBillingCycles),
            'product_ids' => $productIds,
            'product_names' => array_values(array_filter(array_map(
                fn (int $productId) => $productNameMap[$productId] ?? '',
                $productIds
            ))),
            'product_scope_text' => $this->formatProductScopeText($productIds, $productNameMap),
            'first_order_only' => (bool) $coupon->first_order_only,
            'total_usage_limit' => $coupon->total_usage_limit ? (int) $coupon->total_usage_limit : null,
            'per_user_limit' => $coupon->per_user_limit ? (int) $coupon->per_user_limit : null,
            'used_count' => (int) ($coupon->used_count ?? 0),
            'recipient_count' => (int) ($coupon->user_coupons_count ?? 0),
            'user_ids' => collect($coupon->userCoupons ?? [])
                ->filter(fn (UserCoupon $userCoupon) => (string) ($userCoupon->receive_type ?? '') === 'grant')
                ->map(fn (UserCoupon $userCoupon) => (int) $userCoupon->user_id)
                ->values()
                ->all(),
            'remaining_stock' => $coupon->total_usage_limit
                ? max((int) $coupon->total_usage_limit - (int) ($coupon->user_coupons_count ?? 0), 0)
                : null,
            'status' => (int) $coupon->status,
            'status_label' => CouponStatus::$labels[(int) $coupon->status] ?? '未知',
            'display_status' => $displayStatus['status'],
            'display_status_label' => $displayStatus['label'],
            'display_status_reason' => $displayStatus['reason'],
            'sort_order' => (int) ($coupon->sort_order ?? 0),
            'starts_at' => $coupon->starts_at?->format('Y-m-d H:i:s'),
            'expires_at' => $coupon->expires_at?->format('Y-m-d H:i:s'),
            'validity_text' => $this->formatValidityText($coupon),
            'remark' => (string) ($coupon->remark ?? ''),
            'operator' => (string) ($coupon->operator ?? ''),
            'trace_id' => (string) ($coupon->trace_id ?? ''),
            'created_at' => $coupon->created_at?->format('Y-m-d H:i:s'),
            'updated_at' => $coupon->updated_at?->format('Y-m-d H:i:s'),
            'can_update' => $canUpdate,
            'can_delete' => $canDelete,
            'lock_reason' => $lockReason,
            'locked_fields' => $this->resolveCouponLockedFields($coupon),
            'delete_reason' => $canDelete ? '' : $this->resolveCouponDeleteBlockReason($coupon),
        ];
    }

    private function loadCouponForAdmin(?Coupon $coupon): Coupon
    {
        throw_if(! $coupon, new BusinessException('优惠券不存在或已删除'));

        return $coupon
            ->loadMissing(['userCoupons:id,coupon_id,user_id,receive_type,last_used_at', 'couponCampaign:id,name'])
            ->loadCount([
                'orders',
                'userCoupons',
                'orders as used_orders_count' => fn ($query) => $query->whereIn('status', self::USED_ORDER_STATUSES),
                'invoices as used_invoices_count' => fn ($query) => $query->whereIn('status', self::USED_INVOICE_STATUSES),
                'userCoupons as used_user_coupons_count' => fn ($query) => $query->whereNotNull('last_used_at'),
                'orders as deletion_blocking_orders_count' => fn ($query) => $query->where('status', '!=', OrderStatus::CANCELLED),
                'invoices as deletion_blocking_invoices_count' => fn ($query) => $query->where('status', '!=', InvoiceStatus::CANCELLED),
            ]);
    }

    private function assertCouponCanBeUpdated(Coupon $coupon, array $payload = []): void
    {
        $reason = $this->resolveCouponLockReason($coupon);

        if ($reason === '') {
            return;
        }

        if ($payload === []) {
            return;
        }

        foreach ($this->resolveCouponLockedFields($coupon) as $field) {
            if (! array_key_exists($field, $payload)) {
                continue;
            }

            $currentValue = $coupon->{$field} ?? null;
            $newValue = $payload[$field];

            if ($field === 'product_ids') {
                $currentValue = $this->normalizeProductIds((array) ($currentValue ?? []));
                $newValue = $this->normalizeProductIds((array) ($newValue ?? []));
            } elseif ($field === 'billing_cycles') {
                $currentValue = $this->normalizeBillingCycles((array) ($currentValue ?? []));
                $newValue = $this->normalizeBillingCycles((array) ($newValue ?? []));
            } elseif (in_array($field, ['discount_value', 'min_amount', 'max_discount_amount'], true)) {
                $currentValue = round((float) ($currentValue ?? 0), 2);
                $newValue = round((float) ($newValue ?? 0), 2);
            } elseif ($field === 'first_order_only') {
                $currentValue = (bool) $currentValue;
                $newValue = (bool) $newValue;
            }

            if ($currentValue != $newValue) {
                throw new BusinessException("{$reason}，无法修改「{$field}」");
            }
        }
    }

    private function couponCanBeUpdatedForAdmin(Coupon $coupon): bool
    {
        return true;
    }

    private function couponCanBeDeletedForAdmin(Coupon $coupon): bool
    {
        return ! $this->couponHasRecordedUsage($coupon)
            && ! $this->couponHasDeletionBlockingReferences($coupon);
    }

    private function resolveCouponLockReason(Coupon $coupon): string
    {
        if ((int) ($coupon->coupon_campaign_id ?? 0) > 0) {
            return '活动生成的优惠券';
        }

        if ($this->couponHasIssuedRecords($coupon)) {
            return '已发放的优惠券';
        }

        return '';
    }

    /**
     * @return array<string>
     */
    private function resolveCouponLockedFields(Coupon $coupon): array
    {
        $isLocked = $this->resolveCouponLockReason($coupon) !== '';

        if (! $isLocked) {
            return [];
        }

        return [
            'distribution_type',
            'discount_type',
            'discount_scope',
        ];
    }

    private function resolveCouponDeleteBlockReason(Coupon $coupon): string
    {
        if ($this->couponHasRecordedUsage($coupon)) {
            return '该优惠券已有人使用，不能删除';
        }

        if ($this->couponHasDeletionBlockingReferences($coupon)) {
            return '该优惠券仍有关联订单或账单，不能删除';
        }

        return '';
    }

    private function couponHasIssuedRecords(Coupon $coupon): bool
    {
        $count = $this->loadedCountValue($coupon, 'user_coupons_count');
        if ($count !== null) {
            return $count > 0;
        }

        return $this->hasUserCouponsTable()
            && UserCoupon::query()->where('coupon_id', (int) $coupon->id)->exists();
    }

    private function couponHasRecordedUsage(Coupon $coupon): bool
    {
        if ((int) ($coupon->used_count ?? 0) > 0) {
            return true;
        }

        $usedOrdersCount = $this->loadedCountValue($coupon, 'used_orders_count');
        $usedInvoicesCount = $this->loadedCountValue($coupon, 'used_invoices_count');
        $usedUserCouponsCount = $this->loadedCountValue($coupon, 'used_user_coupons_count');
        if ($usedOrdersCount !== null && $usedInvoicesCount !== null && $usedUserCouponsCount !== null) {
            return ($usedOrdersCount + $usedInvoicesCount + $usedUserCouponsCount) > 0;
        }

        $couponId = (int) $coupon->id;

        if ($couponId <= 0) {
            return false;
        }

        if ($this->hasUserCouponsTable()
            && UserCoupon::query()->where('coupon_id', $couponId)->whereNotNull('last_used_at')->exists()
        ) {
            return true;
        }

        return Order::query()
            ->where('coupon_id', $couponId)
            ->whereIn('status', self::USED_ORDER_STATUSES)
            ->exists()
            || Invoice::query()
                ->where('coupon_id', $couponId)
                ->whereIn('status', self::USED_INVOICE_STATUSES)
                ->exists();
    }

    private function couponHasDeletionBlockingReferences(Coupon $coupon): bool
    {
        $blockingOrdersCount = $this->loadedCountValue($coupon, 'deletion_blocking_orders_count');
        $blockingInvoicesCount = $this->loadedCountValue($coupon, 'deletion_blocking_invoices_count');
        if ($blockingOrdersCount !== null && $blockingInvoicesCount !== null) {
            return ($blockingOrdersCount + $blockingInvoicesCount) > 0;
        }

        $couponId = (int) $coupon->id;

        if ($couponId <= 0) {
            return false;
        }

        return Order::query()
            ->where('coupon_id', $couponId)
            ->where('status', '!=', OrderStatus::CANCELLED)
            ->exists()
            || Invoice::query()
                ->where('coupon_id', $couponId)
                ->where('status', '!=', InvoiceStatus::CANCELLED)
                ->exists();
    }

    private function loadedCountValue(Coupon $coupon, string $attribute): ?int
    {
        if (! array_key_exists($attribute, $coupon->getAttributes())) {
            return null;
        }

        return (int) ($coupon->getAttribute($attribute) ?? 0);
    }

    private function transformOwnedCouponForUser(UserCoupon $userCoupon, int $userUsedCount, bool $hasPlacedOrder, array $productNameMap): array
    {
        $coupon = $userCoupon->coupon;
        if (! $coupon instanceof Coupon) {
            return [
                'id' => (int) $userCoupon->id,
                'uid' => (string) ($userCoupon->uid ?? ''),
                'coupon_id' => (int) ($userCoupon->coupon_id ?? 0),
                'name' => '已失效优惠券',
                'description' => '关联优惠券记录不存在，已自动忽略展示',
                'distribution_type' => 'public',
                'distribution_type_label' => '公开优惠券',
                'discount_scope' => 'first_month',
                'discount_scope_label' => '首月优惠',
                'receive_type' => (string) ($userCoupon->receive_type ?? 'claim'),
                'receive_type_label' => $this->resolveReceiveTypeLabel((string) ($userCoupon->receive_type ?? 'claim')),
                'discount_type' => '',
                'discount_value' => '0.00',
                'discount_label' => '不可用',
                'min_amount' => '0.00',
                'max_discount_amount' => null,
                'status' => 'expired',
                'status_label' => '已失效',
                'status_reason' => '关联优惠券记录不存在',
                'can_use' => false,
                'used_times' => 0,
                'per_user_limit' => null,
                'remaining_times' => null,
                'first_order_only' => false,
                'product_ids' => [],
                'target_product_id' => 0,
                'product_names' => [],
                'product_scope_text' => '不可用',
                'billing_cycles' => [],
                'billing_cycle_text' => '不可用',
                'validity_text' => '已失效',
                'claimed_at' => $userCoupon->claimed_at?->format('Y-m-d H:i:s'),
                'used_at' => null,
                'revoked_at' => $userCoupon->revoked_at?->format('Y-m-d H:i:s'),
                'granted_at' => $userCoupon->granted_at?->format('Y-m-d H:i:s'),
                'created_at' => $userCoupon->created_at?->format('Y-m-d H:i:s'),
                'expires_at' => null,
            ];
        }

        $productIds = $this->normalizeProductIds((array) ($coupon->product_ids ?? []));
        $rawBillingCycles = (array) ($coupon->billing_cycles ?? []);
        $billingCycles = $this->normalizeBillingCycles($rawBillingCycles);
        $statusMeta = $this->resolveOwnedCouponStatus($coupon, $userCoupon, $userUsedCount, $hasPlacedOrder);
        $remainingTimes = $coupon->per_user_limit
            ? max((int) $coupon->per_user_limit - $userUsedCount, 0)
            : null;

        return [
            'id' => (int) $userCoupon->id,
            'uid' => (string) ($userCoupon->uid ?? ''),
            'coupon_id' => (int) $coupon->id,
            'name' => (string) $coupon->name,
            'description' => (string) ($coupon->description ?? ''),
            'distribution_type' => (string) ($coupon->distribution_type ?? 'public'),
            'distribution_type_label' => $this->resolveDistributionTypeLabel((string) ($coupon->distribution_type ?? 'public')),
            'discount_scope' => (string) ($coupon->discount_scope ?? 'first_month'),
            'discount_scope_label' => $this->resolveDiscountScopeLabel((string) ($coupon->discount_scope ?? 'first_month')),
            'receive_type' => (string) ($userCoupon->receive_type ?? 'claim'),
            'receive_type_label' => $this->resolveReceiveTypeLabel((string) ($userCoupon->receive_type ?? 'claim')),
            'discount_type' => (string) $coupon->discount_type,
            'discount_value' => $this->formatAmount((float) ($coupon->discount_value ?? 0)),
            'discount_label' => $this->buildDiscountLabel($coupon),
            'min_amount' => $this->formatAmount((float) ($coupon->min_amount ?? 0)),
            'max_discount_amount' => $coupon->max_discount_amount !== null
                ? $this->formatAmount((float) $coupon->max_discount_amount)
                : null,
            'status' => $statusMeta['status'],
            'status_label' => $statusMeta['label'],
            'status_reason' => $statusMeta['reason'],
            'can_use' => $statusMeta['status'] === 'available',
            'used_times' => $userUsedCount,
            'per_user_limit' => $coupon->per_user_limit ? (int) $coupon->per_user_limit : null,
            'remaining_times' => $remainingTimes,
            'first_order_only' => (bool) $coupon->first_order_only,
            'product_ids' => $productIds,
            'target_product_id' => count($productIds) === 1 ? (int) $productIds[0] : 0,
            'product_names' => array_values(array_filter(array_map(
                fn (int $productId) => $productNameMap[$productId] ?? '',
                $productIds
            ))),
            'product_scope_text' => $this->formatProductScopeText($productIds, $productNameMap),
            'products' => $this->resolveProductHierarchyList($productIds),
            'billing_cycles' => $billingCycles,
            'billing_cycle_text' => $this->formatBillingCycleText($rawBillingCycles),
            'validity_text' => $this->formatValidityText($coupon),
            'claimed_at' => $userCoupon->claimed_at?->format('Y-m-d H:i:s'),
            'used_at' => $userCoupon->used_at?->format('Y-m-d H:i:s'),
            'revoked_at' => $userCoupon->revoked_at?->format('Y-m-d H:i:s'),
            'granted_at' => $userCoupon->granted_at?->format('Y-m-d H:i:s'),
            'created_at' => $userCoupon->created_at?->format('Y-m-d H:i:s'),
            'expires_at' => $coupon->expires_at?->format('Y-m-d H:i:s'),
        ];
    }

    private function transformPublicCouponForUser(Coupon $coupon, array $productNameMap): array
    {
        $productIds = $this->normalizeProductIds((array) ($coupon->product_ids ?? []));
        $rawBillingCycles = (array) ($coupon->billing_cycles ?? []);
        $statusMeta = $this->resolvePublicCouponStatus($coupon, (int) ($coupon->user_coupons_count ?? 0));

        return [
            'id' => (int) $coupon->id,
            'name' => (string) $coupon->name,
            'description' => (string) ($coupon->description ?? ''),
            'discount_scope' => (string) ($coupon->discount_scope ?? 'first_month'),
            'discount_scope_label' => $this->resolveDiscountScopeLabel((string) ($coupon->discount_scope ?? 'first_month')),
            'discount_type' => (string) $coupon->discount_type,
            'discount_label' => $this->buildDiscountLabel($coupon),
            'min_amount' => $this->formatAmount((float) ($coupon->min_amount ?? 0)),
            'max_discount_amount' => $coupon->max_discount_amount !== null
                ? $this->formatAmount((float) $coupon->max_discount_amount)
                : null,
            'status' => $statusMeta['status'],
            'status_label' => $statusMeta['label'],
            'status_reason' => $statusMeta['reason'],
            'can_claim' => $statusMeta['status'] === 'available',
            'claimed_count' => (int) ($coupon->user_coupons_count ?? 0),
            'total_usage_limit' => $coupon->total_usage_limit ? (int) $coupon->total_usage_limit : null,
            'remaining_stock' => $coupon->total_usage_limit
                ? max((int) $coupon->total_usage_limit - (int) ($coupon->user_coupons_count ?? 0), 0)
                : null,
            'first_order_only' => (bool) $coupon->first_order_only,
            'product_ids' => $productIds,
            'products' => $this->resolveProductHierarchyList($productIds),
            'product_scope_text' => $this->formatProductScopeText($productIds, $productNameMap),
            'billing_cycle_text' => $this->formatBillingCycleText($rawBillingCycles),
            'validity_text' => $this->formatValidityText($coupon),
            'expires_at' => $coupon->expires_at?->format('Y-m-d H:i:s'),
        ];
    }

    private function buildOwnedCouponPayload(UserCoupon $userCoupon, Product $product, string $billingCycle, float $amount, ?int $userId = null, string $orderType = 'new'): array
    {
        $coupon = $userCoupon->coupon;
        throw_if(! $coupon instanceof Coupon, new BusinessException('优惠券不存在或已失效'));
        throw_if((int) $userCoupon->status !== UserCouponStatus::OWNED, new BusinessException('优惠券已失效'));

        $now = now();
        throw_if((int) $coupon->status !== CouponStatus::ACTIVE, new BusinessException('优惠券已停用'));
        if ($userId && ! (bool) ($coupon->allow_agent ?? true)) {
            $user = User::query()->select(['id', 'agent_group_id'])->find($userId);
            throw_if((int) ($user?->agent_group_id ?? 0) > 0, new BusinessException('代理用户不可使用当前优惠券'));
        }
        throw_if(
            (string) ($coupon->distribution_type ?? 'public') === 'private'
                && (string) ($userCoupon->receive_type ?? 'claim') !== 'grant',
            new BusinessException('当前优惠券仅对指定用户发放')
        );
        throw_if($coupon->starts_at && $coupon->starts_at->gt($now), new BusinessException('优惠券尚未开始使用'));
        throw_if($coupon->expires_at && $coupon->expires_at->lt($now), new BusinessException('优惠券已过期'));
        throw_if(
            ! $this->isCouponScopeAllowedForOrderType((string) ($coupon->discount_scope ?? 'first_month'), $orderType),
            new BusinessException($orderType === 'renew' ? '该优惠券不支持续费使用' : '该优惠券仅支持续费使用')
        );

        $normalizedProductIds = $this->normalizeProductIds((array) ($coupon->product_ids ?? []));
        if ($normalizedProductIds !== []) {
            throw_if(! in_array((int) $product->id, $normalizedProductIds, true), new BusinessException('该商品不可使用当前优惠券'));
        }

        $rawBillingCycles = (array) ($coupon->billing_cycles ?? []);
        $normalizedBillingCycles = $this->normalizeBillingCycles($rawBillingCycles);
        if ($this->hasConfiguredBillingCycles($rawBillingCycles) && $normalizedBillingCycles === []) {
            throw new BusinessException('当前优惠券未配置可用计费周期');
        }

        if ($normalizedBillingCycles !== []) {
            throw_if(! in_array($billingCycle, $normalizedBillingCycles, true), new BusinessException('当前计费周期不可使用此优惠券'));
        }

        throw_if($amount < (float) ($coupon->min_amount ?? 0), new BusinessException('未达到优惠券最低消费门槛'));

        $userUsedCount = 0;
        if ($userId) {
            if ($coupon->first_order_only) {
                throw_if($this->hasUserPlacedOrder($userId), new BusinessException('该优惠券仅限首单使用'));
            }

            if ($coupon->per_user_limit) {
                $userUsedCount = Invoice::query()
                    ->where('user_id', $userId)
                    ->where('coupon_id', $coupon->id)
                    ->where('status', '!=', InvoiceStatus::CANCELLED)
                    ->count();

                throw_if($userUsedCount >= (int) $coupon->per_user_limit, new BusinessException('该优惠券已达到使用上限'));
            }
        }

        $discountAmount = $this->calculateDiscountAmount($coupon, $amount);
        throw_if($discountAmount <= 0, new BusinessException('当前优惠券不可用'));

        return [
            'id' => (int) $coupon->id,
            'coupon_id' => (int) $coupon->id,
            'user_coupon_id' => (int) $userCoupon->id,
            'code' => (string) $coupon->code,
            'name' => (string) $coupon->name,
            'description' => (string) ($coupon->description ?? ''),
            'discount_scope' => (string) ($coupon->discount_scope ?? 'first_month'),
            'discount_scope_label' => $this->resolveDiscountScopeLabel((string) ($coupon->discount_scope ?? 'first_month')),
            'discount_type' => (string) $coupon->discount_type,
            'discount_value' => $this->formatAmount((float) ($coupon->discount_value ?? 0)),
            'discount_label' => $this->buildDiscountLabel($coupon),
            'discount_amount' => $this->formatAmount($discountAmount),
            'final_amount' => $this->formatAmount(max($amount - $discountAmount, 0)),
            'min_amount' => $this->formatAmount((float) ($coupon->min_amount ?? 0)),
            'max_discount_amount' => $coupon->max_discount_amount !== null
                ? $this->formatAmount((float) $coupon->max_discount_amount)
                : null,
            'first_order_only' => (bool) $coupon->first_order_only,
            'per_user_limit' => $coupon->per_user_limit ? (int) $coupon->per_user_limit : null,
            'used_times' => $userUsedCount,
            'product_scope_text' => $this->formatProductScopeText(
                $normalizedProductIds,
                $this->resolveProductNameMapFromCoupons(collect([$coupon]))
            ),
            'billing_cycle_text' => $this->formatBillingCycleText($rawBillingCycles),
            'validity_text' => $this->formatValidityText($coupon),
            'expires_at' => $coupon->expires_at?->format('Y-m-d H:i:s'),
        ];
    }

    private function assertCouponCanBeClaimedByUser(Coupon $coupon, int $userId): void
    {
        throw_if(! $this->hasUserCouponsTable(), new BusinessException('当前系统未启用已领优惠券功能'));

        $now = now();

        throw_if((string) ($coupon->distribution_type ?? 'public') !== 'public', new BusinessException('该优惠券不是公开领取类型'));
        throw_if((int) $coupon->status !== CouponStatus::ACTIVE, new BusinessException('优惠券已停用'));
        throw_if($coupon->starts_at && $coupon->starts_at->gt($now), new BusinessException('优惠券尚未开始领取'));
        throw_if($coupon->expires_at && $coupon->expires_at->lt($now), new BusinessException('优惠券已过期'));
        throw_if(
            UserCoupon::query()->where('coupon_id', $coupon->id)->where('user_id', $userId)->exists(),
            new BusinessException('你已经领取过这张优惠券')
        );

        if ($coupon->total_usage_limit) {
            $receivedCount = UserCoupon::query()->where('coupon_id', $coupon->id)->count();
            throw_if($receivedCount >= (int) $coupon->total_usage_limit, new BusinessException('优惠券已被领完'));
        }
    }

    private function grantCouponToUsers(Coupon $coupon, array $userIds, array $context = [], bool $replace = false): void
    {
        throw_if(! $this->hasUserCouponsTable(), new BusinessException('当前系统未启用已领优惠券功能'));

        if ($userIds === []) {
            throw new BusinessException('私有优惠券必须选择至少一个用户');
        }

        $existing = UserCoupon::query()
            ->where('coupon_id', $coupon->id)
            ->where('receive_type', 'grant')
            ->lockForUpdate()
            ->get()
            ->keyBy(fn (UserCoupon $userCoupon) => (int) $userCoupon->user_id);

        $existingUserIds = $existing->keys()->map(fn ($id) => (int) $id)->all();
        $toCreate = array_values(array_diff($userIds, $existingUserIds));
        $toDelete = $replace ? array_values(array_diff($existingUserIds, $userIds)) : [];
        $toReactivate = $existing
            ->filter(fn (UserCoupon $userCoupon, int|string $userId) => in_array((int) $userId, $userIds, true))
            ->filter(fn (UserCoupon $userCoupon) => (int) ($userCoupon->status ?? 0) !== UserCouponStatus::OWNED);

        if ($coupon->total_usage_limit) {
            $receivedCount = UserCoupon::query()->where('coupon_id', $coupon->id)->count();
            $hardDeleteCount = 0;

            if ($toDelete !== []) {
                $recordsToDelete = $existing
                    ->filter(fn (UserCoupon $userCoupon, int|string $userId) => in_array((int) $userId, $toDelete, true))
                    ->values();

                $boundUserCouponIds = $this->boundUserCouponIdSet(
                    $recordsToDelete->pluck('id')->map(fn ($id) => (int) $id)->all()
                );

                $hardDeleteCount = $recordsToDelete
                    ->reject(function (UserCoupon $record) use ($boundUserCouponIds) {
                        $recordId = (int) $record->id;

                        return $boundUserCouponIds->has($recordId) || $record->last_used_at !== null;
                    })
                    ->count();
            }
            throw_if(
                max($receivedCount - $hardDeleteCount, 0) + count($toCreate) > (int) $coupon->total_usage_limit,
                new BusinessException('超过优惠券总发放上限')
            );
        }

        if ($toDelete !== []) {
            $recordsToDelete = $existing
                ->filter(fn (UserCoupon $userCoupon, int|string $userId) => in_array((int) $userId, $toDelete, true))
                ->values();

            $boundUserCouponIds = $this->boundUserCouponIdSet(
                $recordsToDelete->pluck('id')->map(fn ($id) => (int) $id)->all()
            );

            foreach ($recordsToDelete as $record) {
                $recordId = (int) $record->id;
                $shouldPreserve = $boundUserCouponIds->has($recordId) || $record->last_used_at !== null;

                if ($shouldPreserve) {
                    $record->forceFill([
                        'status' => UserCouponStatus::REVOKED,
                        'revoked_at' => now(),
                        'operator' => (string) ($context['operator'] ?? $record->operator ?? ''),
                        'trace_id' => (string) ($context['trace_id'] ?? $record->trace_id ?? ''),
                    ])->save();

                    continue;
                }

                $record->delete();
            }
        }

        foreach ($toReactivate as $userCoupon) {
            $userCoupon->forceFill([
                'status' => UserCouponStatus::OWNED,
                'revoked_at' => null,
                'granted_at' => now(),
                'operator' => (string) ($context['operator'] ?? $userCoupon->operator ?? ''),
                'trace_id' => (string) ($context['trace_id'] ?? $userCoupon->trace_id ?? ''),
            ])->save();
        }

        $validUserIds = User::query()
            ->whereIn('id', $toCreate)
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->all();

        foreach ($validUserIds as $userId) {
            UserCoupon::query()->create([
                'coupon_id' => (int) $coupon->id,
                'user_id' => $userId,
                'receive_type' => 'grant',
                'status' => UserCouponStatus::OWNED,
                'granted_at' => now(),
                'operator' => (string) ($context['operator'] ?? ''),
                'trace_id' => (string) ($context['trace_id'] ?? ''),
            ]);
        }
    }

    private function boundUserCouponIdSet(array $userCouponIds): Collection
    {
        $ids = collect($userCouponIds)
            ->map(fn ($id) => (int) $id)
            ->filter(fn (int $id) => $id > 0)
            ->unique()
            ->values()
            ->all();

        if ($ids === []) {
            return collect();
        }

        $orderUserCouponIds = Order::query()
            ->whereIn('user_coupon_id', $ids)
            ->pluck('user_coupon_id');

        $invoiceUserCouponIds = Invoice::query()
            ->whereIn('user_coupon_id', $ids)
            ->pluck('user_coupon_id');

        return $orderUserCouponIds
            ->merge($invoiceUserCouponIds)
            ->map(fn ($id) => (int) $id)
            ->filter(fn (int $id) => $id > 0)
            ->flip();
    }

    private function hasUserCouponsTable(): bool
    {
        static $resolved = null;

        if ($resolved !== null) {
            return $resolved;
        }

        $resolved = Schema::hasTable('user_coupons');

        return $resolved;
    }

    private function hasCouponsTable(): bool
    {
        static $resolved = null;

        if ($resolved !== null) {
            return $resolved;
        }

        $resolved = Schema::hasTable('coupons');

        return $resolved;
    }

    private function assertCouponsTableAvailable(): void
    {
        throw_if(! $this->hasCouponsTable(), new BusinessException('当前系统未启用优惠券功能'));
    }

    private function resolveAdminDisplayStatus(Coupon $coupon): array
    {
        $now = now();

        if ((int) $coupon->status !== CouponStatus::ACTIVE) {
            return ['status' => 'disabled', 'label' => '已停用', 'reason' => '管理员已手动停用'];
        }

        if ($coupon->starts_at && $coupon->starts_at->gt($now)) {
            return ['status' => 'pending', 'label' => '未开始', 'reason' => '未到生效时间'];
        }

        if ($coupon->expires_at && $coupon->expires_at->lt($now)) {
            return ['status' => 'expired', 'label' => '已过期', 'reason' => '已超过结束时间'];
        }

        return ['status' => 'active', 'label' => '生效中', 'reason' => '当前可正常使用'];
    }

    private function resolveOwnedCouponStatus(Coupon $coupon, UserCoupon $userCoupon, int $userUsedCount, bool $hasPlacedOrder): array
    {
        $now = now();
        if ((int) $userCoupon->status === UserCouponStatus::USED) {
            return ['status' => 'used_up', 'label' => '已使用', 'reason' => '优惠券已被核销'];
        }

        if ((int) $userCoupon->status === UserCouponStatus::REVOKED) {
            return ['status' => 'expired', 'label' => '已作废', 'reason' => '管理员已作废'];
        }

        if ((string) ($coupon->distribution_type ?? 'public') === 'private'
            && (string) ($userCoupon->receive_type ?? 'claim') !== 'grant') {
            return ['status' => 'expired', 'label' => '已失效', 'reason' => '当前优惠券已改为私有发放'];
        }

        if ((int) $userCoupon->status !== UserCouponStatus::OWNED || (int) $coupon->status !== CouponStatus::ACTIVE) {
            return ['status' => 'expired', 'label' => '已失效', 'reason' => '当前优惠券已停用或失效'];
        }

        if ($coupon->starts_at && $coupon->starts_at->gt($now)) {
            return ['status' => 'expired', 'label' => '未开始', 'reason' => '未到可使用时间'];
        }

        if ($coupon->expires_at && $coupon->expires_at->lt($now)) {
            return ['status' => 'expired', 'label' => '已过期', 'reason' => '有效期已结束'];
        }

        if ($coupon->first_order_only && $hasPlacedOrder) {
            return ['status' => 'used_up', 'label' => '已用完', 'reason' => '仅限首单用户使用'];
        }

        if ($coupon->per_user_limit && $userUsedCount >= (int) $coupon->per_user_limit) {
            return ['status' => 'used_up', 'label' => '已用完', 'reason' => '已达到该优惠券使用上限'];
        }

        return ['status' => 'available', 'label' => '可使用', 'reason' => '满足条件后可直接抵扣'];
    }

    private function resolvePublicCouponStatus(Coupon $coupon, int $claimedCount): array
    {
        $now = now();

        if ((int) $coupon->status !== CouponStatus::ACTIVE) {
            return ['status' => 'expired', 'label' => '已失效', 'reason' => '当前优惠券已停用'];
        }

        if ($coupon->starts_at && $coupon->starts_at->gt($now)) {
            return ['status' => 'expired', 'label' => '未开始', 'reason' => '未到领取时间'];
        }

        if ($coupon->expires_at && $coupon->expires_at->lt($now)) {
            return ['status' => 'expired', 'label' => '已过期', 'reason' => '已超过领取时间'];
        }

        if ($coupon->total_usage_limit && $claimedCount >= (int) $coupon->total_usage_limit) {
            return ['status' => 'used_up', 'label' => '已领完', 'reason' => '公开优惠券已被领取完'];
        }

        return ['status' => 'available', 'label' => '可领取', 'reason' => '领取后会进入你的优惠券账户'];
    }

    private function normalizeAdminCouponPayload(array $payload, array $context = [], ?Coupon $coupon = null): array
    {
        $lockedFields = $coupon ? $this->resolveCouponLockedFields($coupon) : [];
        $lockedFieldSet = array_flip($lockedFields);

        // For locked fields, fall back to existing coupon values when not provided
        $discountType = trim((string) ($payload['discount_type'] ?? ''));
        if ($discountType === '' && isset($lockedFieldSet['discount_type']) && $coupon) {
            $discountType = (string) ($coupon->discount_type ?? '');
        }

        $distributionType = trim((string) ($payload['distribution_type'] ?? ''));
        if ($distributionType === '' && isset($lockedFieldSet['distribution_type']) && $coupon) {
            $distributionType = (string) ($coupon->distribution_type ?? 'public');
        }
        if ($distributionType === '') {
            $distributionType = 'public';
        }

        $discountScope = trim((string) ($payload['discount_scope'] ?? ''));
        if ($discountScope === '' && isset($lockedFieldSet['discount_scope']) && $coupon) {
            $discountScope = (string) ($coupon->discount_scope ?? 'first_month');
        }
        if ($discountScope === '') {
            $discountScope = 'first_month';
        }

        $discountValue = round((float) ($payload['discount_value'] ?? 0), 2);
        if ($discountValue <= 0 && isset($lockedFieldSet['discount_value']) && $coupon) {
            $discountValue = round((float) ($coupon->discount_value ?? 0), 2);
        }

        $minAmount = round((float) ($payload['min_amount'] ?? 0), 2);
        if ($minAmount <= 0 && isset($lockedFieldSet['min_amount']) && $coupon) {
            $minAmount = round((float) ($coupon->min_amount ?? 0), 2);
        }

        $maxDiscountAmount = $payload['max_discount_amount'] ?? null;
        if (($maxDiscountAmount === null || $maxDiscountAmount === '') && isset($lockedFieldSet['max_discount_amount']) && $coupon) {
            $maxDiscountAmount = $coupon->max_discount_amount;
        }

        $startsAt = $this->normalizeDateTimeValue($payload['starts_at'] ?? null);
        $expiresAt = $this->normalizeDateTimeValue($payload['expires_at'] ?? null);
        $providedCode = trim((string) ($payload['code'] ?? ''));
        $code = $providedCode !== '' ? $providedCode : ($coupon?->code ?: $this->generateInternalCouponCode());

        throw_if($discountType === '', new BusinessException('优惠类型不能为空'));
        throw_if(! in_array($distributionType, ['public', 'private'], true), new BusinessException('发放方式不正确'));
        throw_if(! in_array($discountScope, ['first_month', 'recurring', 'renew'], true), new BusinessException('优惠阶段不正确'));
        throw_if($discountValue <= 0, new BusinessException('优惠值必须大于 0'));

        if ($providedCode !== '') {
            $duplicateCodeQuery = Coupon::query()->where('code', $providedCode);

            if ($coupon?->id) {
                $duplicateCodeQuery->whereKeyNot((int) $coupon->id);
            }

            throw_if($duplicateCodeQuery->exists(), new BusinessException('优惠券编码已存在'));
        }

        if ($discountType === 'percentage') {
            throw_if($discountValue > 100, new BusinessException('折扣百分比不能大于 100'));
        }

        if ($maxDiscountAmount !== null && $maxDiscountAmount !== '') {
            $maxDiscountAmount = round((float) $maxDiscountAmount, 2);
            throw_if($maxDiscountAmount < 0, new BusinessException('最高优惠金额不能小于 0'));
        } else {
            $maxDiscountAmount = null;
        }

        $billingCycles = isset($lockedFieldSet['billing_cycles']) && $coupon && ! isset($payload['billing_cycles'])
            ? $this->normalizeBillingCycles((array) ($coupon->billing_cycles ?? []))
            : $this->normalizeBillingCycles((array) ($payload['billing_cycles'] ?? []));

        $productIds = isset($lockedFieldSet['product_ids']) && $coupon && ! isset($payload['product_ids'])
            ? $this->normalizeProductIds((array) ($coupon->product_ids ?? []))
            : $this->normalizeProductIds((array) ($payload['product_ids'] ?? []));

        $firstOrderOnly = isset($lockedFieldSet['first_order_only']) && $coupon && ! isset($payload['first_order_only'])
            ? (bool) $coupon->first_order_only
            : (bool) ($payload['first_order_only'] ?? false);

        return [
            'coupon_campaign_id' => isset($payload['coupon_campaign_id']) && (int) $payload['coupon_campaign_id'] > 0
                ? (int) $payload['coupon_campaign_id']
                : null,
            'name' => trim((string) ($payload['name'] ?? '')),
            'code' => $code,
            'description' => trim((string) ($payload['description'] ?? '')) ?: null,
            'distribution_type' => $distributionType,
            'discount_scope' => $discountScope,
            'discount_type' => $discountType,
            'discount_value' => $discountValue,
            'min_amount' => $minAmount,
            'max_discount_amount' => $maxDiscountAmount,
            'billing_cycles' => $billingCycles,
            'product_ids' => $productIds,
            'first_order_only' => $firstOrderOnly,
            'total_usage_limit' => $this->normalizePositiveInteger($payload['total_usage_limit'] ?? null),
            'per_user_limit' => $this->normalizePositiveInteger($payload['per_user_limit'] ?? null),
            'status' => (int) ($payload['status'] ?? CouponStatus::ACTIVE),
            'sort_order' => max((int) ($payload['sort_order'] ?? 0), 0),
            'starts_at' => $startsAt,
            'expires_at' => $expiresAt,
            'remark' => trim((string) ($payload['remark'] ?? '')) ?: null,
            'operator' => (string) ($context['operator'] ?? ''),
            'trace_id' => (string) ($context['trace_id'] ?? ''),
        ];
    }

    private function calculateDiscountAmount(Coupon $coupon, float $amount): float
    {
        $discountAmount = 0.0;
        $discountType = trim((string) $coupon->discount_type);
        $discountValue = (float) ($coupon->discount_value ?? 0);

        if ($discountType === 'fixed') {
            $discountAmount = $discountValue;
        }

        if ($discountType === 'percentage') {
            $discountRate = max(min($discountValue, 100), 0);
            $discountAmount = $amount * (100 - $discountRate) / 100;
        }

        if ($coupon->max_discount_amount !== null && $coupon->max_discount_amount > 0) {
            $discountAmount = min($discountAmount, (float) $coupon->max_discount_amount);
        }

        return round(min($discountAmount, max($amount - 0.01, 0)), 2);
    }

    private function hasUserPlacedOrder(int $userId): bool
    {
        return Invoice::query()
            ->where('user_id', $userId)
            ->where('status', '!=', InvoiceStatus::CANCELLED)
            ->exists();
    }

    private function countCouponUsedInvoices(int $couponId): int
    {
        return (int) Invoice::query()
            ->where('coupon_id', $couponId)
            ->whereIn('status', self::USED_INVOICE_STATUSES)
            ->count();
    }

    private function resolveUserCouponLastUsedAt(int $userCouponId): mixed
    {
        $order = Invoice::query()
            ->select(['paid_at', 'updated_at'])
            ->where('user_coupon_id', $userCouponId)
            ->whereIn('status', self::USED_INVOICE_STATUSES)
            ->orderByDesc('paid_at')
            ->orderByDesc('updated_at')
            ->first();

        if (! $order instanceof Invoice) {
            return null;
        }

        return $order->paid_at ?: $order->updated_at;
    }

    private function shouldKeepFirstOrderUserCouponUsed(UserCoupon $userCoupon): bool
    {
        return (bool) ($userCoupon->coupon?->first_order_only ?? false)
            && $userCoupon->used_at !== null;
    }

    private function resolveProductNameMapFromCoupons(Collection $coupons): array
    {
        $productIds = $coupons
            ->filter()
            ->flatMap(fn (Coupon $coupon) => $this->normalizeProductIds((array) ($coupon->product_ids ?? [])))
            ->unique()
            ->values()
            ->all();

        if ($productIds === []) {
            return [];
        }

        return Product::query()
            ->select(['id', 'pricing', 'purchase_requires', 'config_options'])
            ->whereIn('id', $productIds)
            ->get()
            ->mapWithKeys(function (Product $product) {
                $displayName = (string) ($this->resolveProductDisplayNameResolver()->resolveForProduct($product)['product_display_name'] ?? '');

                return [
                    (int) $product->id => $displayName !== '' ? $displayName : '未配置规格 #'.(int) $product->id,
                ];
            })
            ->all();
    }

    /**
     * @param  array<int, int>  $productIds
     * @return array<int, array{id: int, name: string, type_label: string, parent_group_name: string, group_name: string}>
     */
    private function resolveProductHierarchyList(array $productIds): array
    {
        if ($productIds === []) {
            return [];
        }

        $products = Product::query()
            ->select([
                'id',
                'product_group_id',
                'service_type_code',
                'product_type',
                'custom_display_name',
                'pricing',
                'purchase_requires',
                'config_options',
            ])
            ->whereIn('id', $productIds)
            ->with([
                'productGroup.secondProductGroup.firstProductGroup',
            ])
            ->get();

        $specMap = $this->instanceSpecCatalogService
            ? $this->instanceSpecCatalogService->resolveProductSpecMap($productIds)
            : [];

        return $products
            ->map(function (Product $product) use ($specMap) {
                $hierarchy = ProductGroupHierarchyFields::fromProduct($product);
                $typeCode = (string) ($hierarchy['product_type'] ?? ProductType::OTHER);
                $instanceSpecText = (string) (($specMap[(int) $product->id] ?? [])['instance_spec_text'] ?? '');
                $displayNamePayload = $this->resolveProductDisplayNameResolver()->resolveForProduct($product, [
                    'instance_spec_text' => $instanceSpecText,
                ]);
                $combinedName = trim((string) ($displayNamePayload['combined_display_name'] ?? ''));
                $productName = trim((string) ($displayNamePayload['product_display_name'] ?? ''));

                return [
                    'id' => (int) $product->id,
                    'name' => $combinedName !== '' ? $combinedName : ($productName !== '' ? $productName : '未配置规格 #'.(int) $product->id),
                    'product_type' => $typeCode,
                    'product_type_label' => ProductType::businessLabelOf($typeCode),
                    'service_type_code' => $typeCode,
                    'service_type_label' => ProductType::businessLabelOf($typeCode),
                    'first_product_group_id' => $hierarchy['first_product_group_id'] ?? null,
                    'first_product_group_code' => trim((string) ($hierarchy['first_product_group_code'] ?? '')),
                    'first_product_group_name' => trim((string) ($hierarchy['first_product_group_name'] ?? '')),
                    'second_product_group_id' => $hierarchy['second_product_group_id'] ?? null,
                    'second_product_group_name' => trim((string) ($hierarchy['second_product_group_name'] ?? '')),
                    'third_product_group_id' => $hierarchy['third_product_group_id'] ?? null,
                    'third_product_group_name' => trim((string) ($hierarchy['third_product_group_name'] ?? '')),
                    'effective_product_group_id' => $hierarchy['effective_product_group_id'] ?? null,
                    'effective_product_group_level' => $hierarchy['effective_product_group_level'] ?? null,
                    '_sort_type' => $typeCode,
                    '_sort_parent' => trim((string) ($hierarchy['second_product_group_name'] ?? '')),
                    '_sort_group' => trim((string) ($hierarchy['third_product_group_name'] ?? '')),
                ];
            })
            ->sortBy([
                ['_sort_type', 'asc'],
                ['_sort_parent', 'asc'],
                ['_sort_group', 'asc'],
                ['name', 'asc'],
            ])
            ->map(fn (array $item) => array_diff_key($item, ['_sort_type' => 0, '_sort_parent' => 0, '_sort_group' => 0]))
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

    private function normalizeUserIds(array $userIds): array
    {
        return collect($userIds)
            ->map(fn ($id) => (int) $id)
            ->filter(fn (int $id) => $id > 0)
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

    private function buildDiscountLabel(Coupon $coupon): string
    {
        $discountType = trim((string) $coupon->discount_type);
        $discountValue = (float) ($coupon->discount_value ?? 0);

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

    private function resolveDistributionTypeLabel(string $distributionType): string
    {
        return match ($distributionType) {
            'private' => '私有优惠券',
            default => '公开优惠券',
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

    private function resolveReceiveTypeLabel(string $receiveType): string
    {
        return match ($receiveType) {
            'grant' => '系统直发',
            default => '手动领取',
        };
    }

    private function isCouponScopeAllowedForOrderType(string $discountScope, string $orderType): bool
    {
        $normalizedOrderType = trim($orderType) === 'renew' ? 'renew' : 'new';

        return match ($discountScope) {
            'renew' => $normalizedOrderType === 'renew',
            'recurring' => in_array($normalizedOrderType, ['new', 'renew'], true),
            default => $normalizedOrderType === 'new',
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

        $productNames = array_values(array_filter(array_map(
            fn (int $productId) => $productNameMap[$productId] ?? '',
            $productIds
        )));

        if ($productNames === []) {
            return '指定商品可用';
        }

        return implode(' / ', $productNames);
    }

    private function formatValidityText(Coupon $coupon): string
    {
        $start = $coupon->starts_at?->format('Y-m-d H:i');
        $end = $coupon->expires_at?->format('Y-m-d H:i');

        if ($start && $end) {
            return $start.' - '.$end;
        }

        if ($end) {
            return '截止至 '.$end;
        }

        if ($start) {
            return '自 '.$start.' 起';
        }

        return '长期有效';
    }

    private function formatAmount(float $amount): string
    {
        return number_format($amount, 2, '.', '');
    }

    private function normalizePositiveInteger(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        $number = (int) $value;

        return $number > 0 ? $number : null;
    }

    private function normalizeDateTimeValue(mixed $value): ?string
    {
        $text = trim((string) ($value ?? ''));

        return $text !== '' ? $text : null;
    }

    private function generateInternalCouponCode(): string
    {
        do {
            $code = 'CPN'.Str::upper(Str::random(10));
        } while (Coupon::query()->where('code', $code)->exists());

        return $code;
    }

    private function resolveProductDisplayNameResolver(): ProductDisplayNameResolver
    {
        return $this->productDisplayNameResolver ?? new ProductDisplayNameResolver;
    }
}
