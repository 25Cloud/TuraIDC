<?php

declare(strict_types=1);

namespace App\Services\ClientServiceConsole;

use App\Support\SqlLike;
use App\Constants\ProductType;
use App\Constants\ServiceStatus;
use App\Models\FirstProductGroup;
use App\Models\SecondProductGroup;
use App\Models\Service;
use App\Models\ThirdProductGroup;
use App\Models\User;
use App\Support\ProductGroupHierarchyFields;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;

/**
 * 服务概览/列表子服务
 * 负责：paginateForUser、groupedOverviewForUser、summaryForUser 及其内部辅助方法
 */
class ServiceOverviewService
{
    private const GROUPED_OVERVIEW_CACHE_TTL_SECONDS = 120; // 2分钟：服务概览需要一定实时性

    private const SUMMARY_CACHE_TTL_SECONDS = 120; // 2分钟：与概览保持一致

    /** 未能归入任何可见一级菜单的服务的兜底分组值。 */
    private const OTHER_MENU_CODE = 'other_services';

    private const STATUS_SCOPE_ACTIVE_PENDING = 'active_pending';

    private const QUICK_FILTER_EXPIRING_7D = 'expiring_7d';

    private const QUICK_FILTER_AUTO_RENEW_ENABLED = 'auto_renew_enabled';

    private const QUICK_FILTER_AUTO_RENEW_DISABLED = 'auto_renew_disabled';

    private const QUICK_FILTER_AUTO_RENEW_7D = 'auto_renew_7d';

    public function __construct(
        private readonly ServiceTransformService $transformService,
        private readonly ServiceResolverService $resolverService,
    ) {}

    public function paginateForUser(User $user, array $filters = []): array
    {
        $pageSize = max((int) ($filters['page_size'] ?? 12), 1);
        $query = Service::query()
            ->select([
                'id', 'user_id', 'product_id', 'order_id', 'invoice_id', 'name', 'domain',
                'billing_cycle', 'amount', 'status', 'provision_data', 'expires_at',
                'created_at', 'auto_renew',
            ])
            ->with([
                'product:id,product_type,service_type_code,product_group_id,console_template,config_options,purchase_requires',
                'product.productGroup.secondProductGroup.firstProductGroup',
                'order:id,order_no,status,paid_at',
                'invoice:id,invoice_no',
            ])
            ->where('user_id', $user->id);

        $keyword = trim((string) ($filters['keyword'] ?? ''));
        if ($keyword !== '') {
            $query->where(function ($builder) use ($keyword) {
                $builder->where('name', 'like', SqlLike::contains($keyword))
                    ->orWhere('domain', 'like', SqlLike::contains($keyword))
                    ->orWhereHas('invoice', fn ($q) => $q->where('invoice_no', 'like', SqlLike::contains($keyword)));

                if ($this->hasConnectionSnapshotTable()) {
                    $likeKeyword = SqlLike::contains($keyword);
                    $builder->orWhereExists(function ($subQuery) use ($likeKeyword): void {
                        $subQuery
                            ->selectRaw('1')
                            ->from('service_connection_snapshots as scs')
                            ->whereColumn('scs.service_id', 'services.id')
                            ->where(function ($connectionQuery) use ($likeKeyword): void {
                                $connectionQuery
                                    ->where('scs.hostname', 'like', $likeKeyword)
                                    ->orWhere('scs.ip_address', 'like', $likeKeyword)
                                    ->orWhere('scs.connection_json->custom_hostname', 'like', $likeKeyword)
                                    ->orWhere('scs.connection_json->default_service_name', 'like', $likeKeyword)
                                    ->orWhere('scs.connection_json->username', 'like', $likeKeyword)
                                    ->orWhere('scs.connection_json->internal_ip', 'like', $likeKeyword);
                            });
                    });
                } else {
                    $builder->orWhere('provision_data->custom_hostname', 'like', SqlLike::contains($keyword));
                }
            });
        }

        $statusScope = trim((string) ($filters['status_scope'] ?? ''));
        if ($statusScope === self::STATUS_SCOPE_ACTIVE_PENDING) {
            $query->whereIn('status', [ServiceStatus::ACTIVE, ServiceStatus::PENDING]);
        } elseif (($filters['status'] ?? '') !== '') {
            $query->where('status', (int) $filters['status']);
        }

        $catalogType = trim((string) ($filters['catalog_type'] ?? ''));
        if ($catalogType !== '') {
            $query->where(function ($builder) use ($catalogType) {
                $builder->whereHas('product.productGroup.secondProductGroup.firstProductGroup', fn ($q) => $q->where('code', $catalogType))
                    ->orWhereHas('product', fn ($q) => $q->where('service_type_code', $catalogType))
                    ->orWhereHas('product', fn ($q) => $q->where('product_type', $catalogType));
            });
        }

        $quickFilter = trim((string) ($filters['quick_filter'] ?? ''));
        if ($quickFilter === self::QUICK_FILTER_EXPIRING_7D) {
            $query->where('status', ServiceStatus::ACTIVE)
                ->whereNotNull('expires_at')
                ->whereBetween('expires_at', [now(), now()->addDays(7)]);
        }

        if ($quickFilter === self::QUICK_FILTER_AUTO_RENEW_ENABLED) {
            $query->where('status', ServiceStatus::ACTIVE)
                ->where('auto_renew', 1);
        }

        if ($quickFilter === self::QUICK_FILTER_AUTO_RENEW_DISABLED) {
            $query->where('status', ServiceStatus::ACTIVE)
                ->where(function ($q) {
                    $q->where('auto_renew', 0)->orWhereNull('auto_renew');
                });
        }

        if ($quickFilter === self::QUICK_FILTER_AUTO_RENEW_7D) {
            $query->where('status', ServiceStatus::ACTIVE)
                ->where('auto_renew', 1)
                ->whereNotNull('expires_at')
                ->whereBetween('expires_at', [now(), now()->addDays(7)]);
        }

        $paginator = $query->orderByDesc('id')->paginate($pageSize);

        return [
            'list' => collect($paginator->items())
                ->map(fn (Service $service) => $this->transformService->transformListItem($service))
                ->values()->all(),
            'total' => $paginator->total(),
            'page' => $paginator->currentPage(),
            'page_size' => $paginator->perPage(),
        ];
    }

    public function groupedOverviewForUser(User $user): array
    {
        return Cache::remember(
            $this->buildGroupedOverviewCacheKey((int) $user->id),
            now()->addSeconds(self::GROUPED_OVERVIEW_CACHE_TTL_SECONDS),
            fn () => $this->buildGroupedOverview($user)
        );
    }

    public function summaryForUser(User $user): array
    {
        return Cache::remember(
            $this->buildSummaryCacheKey((int) $user->id),
            now()->addSeconds(self::SUMMARY_CACHE_TTL_SECONDS),
            fn () => $this->buildSummary($user)
        );
    }

    private function hasConnectionSnapshotTable(): bool
    {
        try {
            return Schema::hasTable('service_connection_snapshots');
        } catch (\Throwable) {
            return false;
        }
    }

    public function forgetGroupedOverviewCache(int $userId): void
    {
        Cache::forget($this->buildGroupedOverviewCacheKey($userId));
    }

    public function forgetSummaryCache(int $userId): void
    {
        Cache::forget($this->buildSummaryCacheKey($userId));
    }

    // ── Private build methods ───────────────────────────────────────────────

    private function buildGroupedOverview(User $user): array
    {
        $services = Service::query()
            ->with([
                'product:id,product_type,service_type_code,product_group_id,console_template,config_options,purchase_requires',
                'product.productGroup.secondProductGroup.firstProductGroup',
            ])
            ->where('user_id', $user->id)
            ->orderByDesc('id')
            ->get();

        $expiringDeadline = now()->addDays(7);
        // 概览按「一级菜单」分组，而不是按商品类型（product_type）分组：
        // 多个一级菜单可以共用同一个商品类型（例如大陆云服与轻量服务器都是 cloud_server），
        // 按类型分组会把它们并成一张卡，用户再也分不清自己的机器挂在哪条产品线下。
        $menuGroups = $this->visibleFirstProductGroups();
        $menuCodes = $menuGroups
            ->map(fn (FirstProductGroup $group): string => (string) $group->code)
            ->all();
        $codeByBusinessType = $menuGroups
            ->groupBy(fn (FirstProductGroup $group): string => ProductType::businessValueForFirstGroup($group, $group->code))
            ->map(fn ($group): string => (string) ($group->first()?->code ?? ''));

        $groupedByMenu = $services->groupBy(
            fn (Service $service): string => $this->resolveGroupedOverviewMenuCode($service, $menuCodes, $codeByBusinessType)
        );

        $list = $menuGroups
            ->map(function (FirstProductGroup $group, int $index) use ($groupedByMenu, $expiringDeadline): ?array {
                $menuServices = $groupedByMenu->get($group->code, collect());

                if ($menuServices->isEmpty()) {
                    return null;
                }

                $businessType = ProductType::businessValueForFirstGroup($group, $group->code);

                return $this->buildGroupedOverviewTypeItem([
                    'value' => (string) $group->code,
                    'label' => (string) $group->name,
                    'icon' => ProductType::iconOf($businessType),
                    'product_type' => $businessType,
                    'first_product_group_id' => (int) $group->id,
                ], $menuServices->values(), $expiringDeadline, $index);
            })
            ->filter()
            ->values();

        $otherServices = $groupedByMenu->get(self::OTHER_MENU_CODE, collect());
        $list->push($this->buildGroupedOverviewTypeItem(
            ['value' => self::OTHER_MENU_CODE, 'label' => '其他服务'],
            $otherServices->values(),
            $expiringDeadline,
            999999,
            true
        ));

        $list = $list
            ->sortBy(fn (array $item) => (int) ($item['sort_order'] ?? 9999))
            ->values()
            ->map(function (array $item) {
                unset($item['sort_order']);

                return $item;
            })->values()->all();

        $catalogTypes = $menuGroups
            ->map(fn (FirstProductGroup $group): array => [
                'label' => (string) $group->name,
                'value' => (string) $group->code,
                'icon' => ProductType::iconOf(ProductType::businessValueForFirstGroup($group, $group->code)),
                'count' => $groupedByMenu->get($group->code, collect())->count(),
            ])
            ->push([
                'label' => '其他服务',
                'value' => self::OTHER_MENU_CODE,
                'icon' => ProductType::iconOf(ProductType::OTHER),
                'count' => $otherServices->count(),
            ])
            ->filter(fn (array $item): bool => (int) ($item['count'] ?? 0) > 0)
            ->values()
            ->all();

        return [
            'total' => $services->count(),
            'category_total' => count($list),
            'list' => $list,
            'catalog_types' => $catalogTypes,
        ];
    }

    /**
     * 前台可见的一级菜单，按后台排序输出。
     *
     * @return \Illuminate\Support\Collection<int, FirstProductGroup>
     */
    private function visibleFirstProductGroups(): Collection
    {
        $visibleValues = ProductType::visibleValues();

        if ($visibleValues === []) {
            return collect();
        }

        return FirstProductGroup::query()
            ->where('is_visible', 1)
            ->whereIn('code', $visibleValues)
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get();
    }

    /**
     * @param  array<int, string>  $menuCodes
     * @param  Collection<string, string>  $codeByBusinessType
     */
    private function resolveGroupedOverviewMenuCode(Service $service, array $menuCodes, Collection $codeByBusinessType): string
    {
        $firstCode = trim((string) ($this->resolverService->resolveServiceRootGroup($service)?->code ?? ''));

        if ($firstCode !== '' && in_array($firstCode, $menuCodes, true)) {
            return $firstCode;
        }

        $businessType = $this->resolverService->resolveGroupedOverviewTypeValue($service);

        return $codeByBusinessType->get($businessType) ?: self::OTHER_MENU_CODE;
    }

    private function buildSummary(User $user): array
    {
        $counts = Service::where('user_id', $user->id)
            ->selectRaw('status, COUNT(*) as cnt')
            ->groupBy('status')
            ->pluck('cnt', 'status')
            ->all();

        $total = array_sum($counts);
        $active = (int) ($counts[ServiceStatus::ACTIVE] ?? 0);
        $pending = (int) ($counts[ServiceStatus::PENDING] ?? 0) + (int) ($counts[ServiceStatus::SUSPENDED] ?? 0);

        return [
            'total' => $total,
            'active' => $active,
            'pending' => $pending,
            'other' => $total - $active - $pending,
        ];
    }

    private function buildGroupedOverviewTypeItem(
        array $typeItem,
        $services,
        Carbon $expiringDeadline,
        int $sortOrder,
        bool $isSyntheticOther = false
    ): array {
        $groupServices = collect($services)->values();
        /** @var Service|null $firstService */
        $firstService = $groupServices->first();
        $typeValue = trim((string) ($typeItem['product_type'] ?? $typeItem['value'] ?? ''));
        $databaseTypeValue = trim((string) ($typeItem['value'] ?? $typeValue));
        $typeLabel = trim((string) ($typeItem['label'] ?? '')) ?: '其他服务';
        $children = $groupServices
            ->groupBy(fn (Service $service) => $this->resolveGroupedOverviewGroupKey($this->resolveServiceLeafGroup($service)))
            ->map(function ($childItems, string $childKey) use ($expiringDeadline) {
                $childServices = collect($childItems)->values();
                /** @var Service|null $childFirstService */
                $childFirstService = $childServices->first();
                $childRootGroup = $childFirstService ? $this->resolverService->resolveServiceRootGroup($childFirstService) : null;

                return $this->buildGroupedOverviewCategoryCard(
                    $childServices,
                    $childRootGroup,
                    $childKey,
                    $expiringDeadline
                );
            })
            ->sortByDesc(fn (array $item) => ((int) $item['count'] * 1000) + (int) $item['active_count'])
            ->values()->all();

        $groupCount = count($children);
        $count = $groupServices->count();
        $activeCount = $groupServices->where('status', ServiceStatus::ACTIVE)->count();
        $pendingCount = $groupServices->filter(
            fn (Service $service) => in_array((int) $service->status, [ServiceStatus::PENDING, ServiceStatus::SUSPENDED], true)
        )->count();
        $expiringCount = $groupServices->filter(
            fn (Service $service) => (int) $service->status === ServiceStatus::ACTIVE
                && $service->expires_at?->lte($expiringDeadline)
        )->count();
        $consoleMode = $firstService ? $this->resolverService->resolveConsoleMode($firstService) : 'default';
        $menuCode = trim((string) ($typeItem['value'] ?? ''));
        $menuLabel = $typeLabel;

        return [
            'key' => $databaseTypeValue !== '' ? $databaseTypeValue : self::OTHER_MENU_CODE,
            'id' => isset($typeItem['first_product_group_id'])
                ? (int) $typeItem['first_product_group_id']
                : null,
            'group_level' => 1,
            'group_level_label' => ProductGroupHierarchyFields::levelLabel(1),
            'first_product_group_code' => $menuCode,
            'first_product_group_name' => $menuLabel,
            'product_type' => $typeValue,
            'product_type_label' => ProductType::businessLabelOf($typeValue),
            'icon' => (string) ($typeItem['icon'] ?? ProductType::iconOf($typeValue)),
            'name' => $typeLabel,
            'title' => $typeLabel,
            'description' => $isSyntheticOther
                ? '用于承载未归入任何一级菜单的服务，或暂未归类的业务实例。'
                : ('一级分类「'.$menuLabel.'」，当前已开通 '.$count.' 个服务，覆盖 '.$groupCount.' 个下级分类。'),
            'count' => $count,
            'active_count' => $activeCount,
            'pending_count' => $pendingCount,
            'expiring_count' => $expiringCount,
            'children' => $children,
            'primary_service_id' => (int) ($children[0]['primary_service_id'] ?? ($firstService?->id ?? 0)),
            'console_mode' => $consoleMode,
            'is_nat_console' => $consoleMode === 'nat',
            'sort_order' => $sortOrder,
            'items' => $groupServices->take(6)->map(function (Service $service) {
                $group = $this->resolveServiceLeafGroup($service);
                $rootGroup = $this->resolverService->resolveServiceRootGroup($service);
                $serviceName = trim((string) $service->name);
                $consoleMode = $this->resolverService->resolveConsoleMode($service);
                $secondGroup = $group?->secondProductGroup;
                $groupPath = ProductGroupHierarchyFields::path($rootGroup, $secondGroup, $group);

                return [
                    'id' => (int) $service->id,
                    'name' => $serviceName !== '' ? $serviceName : (trim((string) ($service->product?->name ?? '')) ?: ('服务 #'.$service->id)),
                    'product_name' => (string) ($service->product?->name ?? ''),
                    'group_name' => (string) ($group?->name ?? ''),
                    'root_group_name' => (string) ($rootGroup?->name ?? ''),
                    'group_path' => $groupPath,
                    'group_path_text' => ProductGroupHierarchyFields::pathText($groupPath),
                    'status' => (int) $service->status,
                    'status_label' => ServiceStatus::$labels[$service->status] ?? (string) $service->status,
                    'status_tone' => $this->transformService->resolveServiceTone((int) $service->status),
                    'billing_cycle_label' => $this->transformService->resolveBillingCycleLabel((string) $service->billing_cycle),
                    'expires_at' => $service->expires_at?->format('Y-m-d H:i:s'),
                    'amount' => number_format((float) $service->amount, 2, '.', ''),
                    'console_mode' => $consoleMode,
                    'is_nat_console' => $consoleMode === 'nat',
                ];
            })->values()->all(),
        ];
    }

    private function buildGroupedOverviewCategoryCard($services, ?FirstProductGroup $rootGroup, string $fallbackKey, Carbon $expiringDeadline): array
    {
        /** @var Service|null $firstService */
        $firstService = $services->first();
        $leafGroup = $firstService ? $this->resolveServiceLeafGroup($firstService) : null;
        $secondGroup = $leafGroup?->secondProductGroup;
        $leafLevel = $leafGroup instanceof ThirdProductGroup ? 3 : ($leafGroup instanceof SecondProductGroup ? 2 : 0);
        $groupPath = ProductGroupHierarchyFields::path($rootGroup, $secondGroup, $leafGroup);
        $groupName = trim((string) ($leafGroup?->name ?? '')) ?: (trim((string) ($rootGroup?->name ?? '')) ?: '未分类');
        $groupTitle = $groupName;
        $groupDescription = trim((string) ($leafGroup?->description ?? $rootGroup?->description ?? ''));
        $activeCount = $services->where('status', ServiceStatus::ACTIVE)->count();
        $pendingCount = $services->filter(
            fn (Service $service) => in_array((int) $service->status, [ServiceStatus::PENDING, ServiceStatus::SUSPENDED], true)
        )->count();
        $expiringCount = $services->filter(
            fn (Service $service) => (int) $service->status === ServiceStatus::ACTIVE
                && $service->expires_at?->lte($expiringDeadline)
        )->count();
        $previewNames = $services->map(function (Service $service) {
            $serviceName = trim((string) $service->name);

            return $serviceName !== '' ? $serviceName : (trim((string) ($service->product?->name ?? '')) ?: ('服务 #'.$service->id));
        })->filter(fn (?string $name) => $name !== null && $name !== '')->take(2)->values()->all();

        $consoleMode = $firstService ? $this->resolverService->resolveConsoleMode($firstService) : 'default';

        return [
            // key 用「层级:ID」而不是 slug：二级、三级的 slug 只在各自父节点下唯一，
            // 不同父下的同名 slug 会撞成同一个 key，界面上就并成了同一张卡。
            'key' => $leafGroup?->id ? ($leafLevel.':'.(int) $leafGroup->id) : $fallbackKey,
            'id' => $leafGroup?->id ? (int) $leafGroup->id : null,
            'group_level' => $leafLevel,
            'group_level_label' => ProductGroupHierarchyFields::levelLabel($leafLevel),
            'group_path' => $groupPath,
            'group_path_text' => ProductGroupHierarchyFields::pathText($groupPath),
            'name' => $groupName,
            'title' => $groupTitle,
            'description' => $groupDescription !== ''
                ? $groupDescription
                : ('当前分类已开通 '.$services->count().' 个服务，可快速进入控制台处理业务。'),
            'count' => $services->count(),
            'active_count' => $activeCount,
            'pending_count' => $pendingCount,
            'expiring_count' => $expiringCount,
            'status_label' => $activeCount > 0 ? '已开通' : '未开通',
            'status_tone' => $activeCount > 0 ? 'success' : 'muted',
            'primary_service_id' => (int) ($firstService?->id ?? 0),
            'preview_names' => $previewNames,
            'console_mode' => $consoleMode,
            'is_nat_console' => $consoleMode === 'nat',
        ];
    }

    private function resolveServiceLeafGroup(Service $service): SecondProductGroup|ThirdProductGroup|null
    {
        $service->loadMissing([
            'product.productGroup.secondProductGroup.firstProductGroup',
        ]);

        return $service->product?->productGroup;
    }

    private function resolveGroupedOverviewGroupKey(SecondProductGroup|ThirdProductGroup|null $group, string $fallback = 'ungrouped'): string
    {
        if (! $group?->id) {
            return $fallback;
        }

        $level = $group instanceof ThirdProductGroup ? 3 : 2;

        return 'group_'.$level.':'.(int) $group->id;
    }

    private function buildGroupedOverviewCacheKey(int $userId): string
    {
        return 'client_service_console:grouped_overview:'.$userId;
    }

    private function buildSummaryCacheKey(int $userId): string
    {
        return 'client_service_console:summary:'.$userId;
    }
}
