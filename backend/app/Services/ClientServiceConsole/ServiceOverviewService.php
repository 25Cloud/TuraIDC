<?php

declare(strict_types=1);

namespace App\Services\ClientServiceConsole;

use App\Constants\ProductType;
use App\Constants\ServiceStatus;
use App\Models\FirstProductGroup;
use App\Models\SecondProductGroup;
use App\Models\Service;
use App\Models\ThirdProductGroup;
use App\Models\User;
use Carbon\Carbon;
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
                'product:id,product_type,service_type_code,product_group_id,config_options,purchase_requires',
                'product.productGroup.secondProductGroup.firstProductGroup',
                'order:id,order_no,status,paid_at',
                'invoice:id,invoice_no',
            ])
            ->where('user_id', $user->id);

        $keyword = trim((string) ($filters['keyword'] ?? ''));
        if ($keyword !== '') {
            $query->where(function ($builder) use ($keyword) {
                $builder->where('name', 'like', '%'.$keyword.'%')
                    ->orWhere('domain', 'like', '%'.$keyword.'%')
                    ->orWhereHas('invoice', fn ($q) => $q->where('invoice_no', 'like', '%'.$keyword.'%'));

                if ($this->hasConnectionSnapshotTable()) {
                    $likeKeyword = '%'.$keyword.'%';
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
                    $builder->orWhere('provision_data->custom_hostname', 'like', '%'.$keyword.'%');
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
                'product:id,product_type,service_type_code,product_group_id,config_options,purchase_requires',
                'product.productGroup.secondProductGroup.firstProductGroup',
            ])
            ->where('user_id', $user->id)
            ->orderByDesc('id')
            ->get();

        $expiringDeadline = now()->addDays(7);
        // 用户端“已开通产品”只展示前台可见目录中的前 5 个种类，
        // 第 6 个固定保留给“其他服务”。
        $typeItems = collect(ProductType::visibleItems())->values();
        $typeOrderMap = $typeItems->values()
            ->mapWithKeys(fn (array $item, int $index) => [(string) ($item['product_type'] ?? $item['value'] ?? '') => $index])
            ->all();
        $knownTypeValues = $typeItems
            ->map(fn (array $item) => trim((string) ($item['product_type'] ?? $item['value'] ?? '')))
            ->filter(fn (string $value) => $value !== '')
            ->values()->all();
        $primaryTypeItems = $typeItems
            ->take(5)->values();
        $primaryTypeValues = $primaryTypeItems
            ->map(fn (array $item) => trim((string) ($item['product_type'] ?? $item['value'] ?? '')))
            ->filter(fn (string $value) => $value !== '')
            ->values()->all();
        $otherTypeValues = collect($knownTypeValues)
            ->reject(fn (string $value) => in_array($value, $primaryTypeValues, true))
            ->values()->all();

        $list = $primaryTypeItems->map(fn (array $typeItem) => $this->buildGroupedOverviewTypeItem(
            $typeItem,
            $services->filter(
                fn (Service $service) => $this->resolverService->resolveGroupedOverviewTypeValue($service) === trim((string) ($typeItem['product_type'] ?? $typeItem['value'] ?? ''))
            )->values(),
            $expiringDeadline,
            (int) ($typeOrderMap[(string) ($typeItem['product_type'] ?? $typeItem['value'] ?? '')] ?? 9999)
        ))->values();

        $otherServices = $services->filter(function (Service $service) use ($otherTypeValues, $knownTypeValues) {
            $resolvedType = $this->resolverService->resolveGroupedOverviewTypeValue($service);

            return in_array($resolvedType, $otherTypeValues, true)
                || ! in_array($resolvedType, $knownTypeValues, true);
        })->values();

        $list->push($this->buildGroupedOverviewTypeItem(
            ['value' => 'other_services', 'label' => '其他服务'],
            $otherServices,
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
            })->all();

        $primaryTypeValueMap = $primaryTypeItems
            ->mapWithKeys(fn (array $item) => [trim((string) ($item['product_type'] ?? $item['value'] ?? '')) => true])
            ->all();

        $catalogTypes = $typeItems->map(function (array $item) use ($services, $knownTypeValues, $primaryTypeValueMap) {
            $typeValue = trim((string) ($item['product_type'] ?? $item['value'] ?? ''));
            $databaseTypeValue = trim((string) ($item['value'] ?? $typeValue));
            $count = $services->filter(function (Service $service) use ($typeValue, $knownTypeValues, $primaryTypeValueMap) {
                $resolvedType = $this->resolverService->resolveGroupedOverviewTypeValue($service);

                if ($typeValue === ProductType::OTHER && ! isset($primaryTypeValueMap[$typeValue])) {
                    return ! in_array($resolvedType, $knownTypeValues, true) || $resolvedType === ProductType::OTHER;
                }

                return $resolvedType === $typeValue;
            })->count();

            return [
                'label' => (string) ($item['label'] ?? $typeValue ?: '其他'),
                'value' => $databaseTypeValue,
                'icon' => (string) ($item['icon'] ?? ProductType::iconOf($typeValue)),
                'count' => $count,
            ];
        })->filter(fn (array $item) => $item['value'] !== '')->values()->all();

        return [
            'total' => $services->count(),
            'category_total' => count($list),
            'list' => $list,
            'catalog_types' => $catalogTypes,
        ];
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

        return [
            'key' => $databaseTypeValue !== '' ? $databaseTypeValue : 'other_services',
            'id' => null,
            'product_type' => $typeValue,
            'product_type_label' => $typeLabel,
            'icon' => (string) ($typeItem['icon'] ?? ProductType::iconOf($typeValue)),
            'name' => $typeLabel,
            'title' => $typeLabel,
            'description' => $isSyntheticOther
                ? '用于承载未归入前 5 个一级菜单的服务，或暂未归类的业务实例。'
                : ('后台一级菜单：'.$typeLabel.'，当前已开通 '.$count.' 个服务，覆盖 '.$groupCount.' 个产品分组。'),
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

                return [
                    'id' => (int) $service->id,
                    'name' => $serviceName !== '' ? $serviceName : (trim((string) ($service->product?->name ?? '')) ?: ('服务 #'.$service->id)),
                    'product_name' => (string) ($service->product?->name ?? ''),
                    'group_name' => (string) ($group?->name ?? ''),
                    'root_group_name' => (string) ($rootGroup?->name ?? ''),
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
            'key' => $leafGroup?->slug ? (string) $leafGroup->slug : $fallbackKey,
            'id' => $leafGroup?->id ? (int) $leafGroup->id : null,
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
        return $group?->id ? ('effective_product_group_'.$group->id) : $fallback;
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
