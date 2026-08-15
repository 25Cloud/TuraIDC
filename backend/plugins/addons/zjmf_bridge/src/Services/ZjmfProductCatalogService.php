<?php

declare(strict_types=1);

namespace TuraIDC\Plugins\Addons\ZjmfBridge\Services;

use App\Constants\ProductType;
use App\Exceptions\BusinessException;
use App\Models\FirstProductGroup;
use App\Models\Product;
use App\Models\SecondProductGroup;
use App\Models\ThirdProductGroup;
use App\Services\Order\Concerns\HandlesOrderCalculation;
use App\Support\ProductGroupHierarchyFields;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Schema;

class ZjmfProductCatalogService
{
    use HandlesOrderCalculation {
        quote as private calculateProductQuote;
    }

    /**
     * @return array<string, mixed>
     */
    public function cartAll(): array
    {
        $groups = [];
        $skipped = [];

        foreach ($this->saleProductQuery([])->orderBy('sort_order')->orderBy('id')->get() as $product) {
            if (! $product instanceof Product) {
                continue;
            }

            $legacyType = $this->legacyType($product);
            if ($legacyType === null) {
                $skipped[] = [
                    'id' => (int) $product->id,
                    'name' => (string) $product->name,
                    'product_type' => (string) $product->product_type,
                    'reason' => '当前产品类型没有可安全导入的魔方产品类型映射',
                ];

                continue;
            }

            $sourceGroup = $this->legacySourceGroup($product);
            $groupId = (string) $sourceGroup['id'];

            if (! isset($groups[$groupId])) {
                $groups[$groupId] = [
                    'id' => $groupId,
                    'name' => $sourceGroup['name'],
                    'source_group_path' => $sourceGroup['path'],
                    'products' => [],
                ];
            }

            $groups[$groupId]['products'][] = $this->legacyListProduct($product, $legacyType);
        }

        return [
            'products' => array_values($groups),
            'count' => array_sum(array_map(
                static fn (array $group): int => count($group['products']),
                $groups,
            )),
            'currency' => $this->legacyCurrency(),
            'skipped' => $skipped,
        ];
    }

    /**
     * @param  int[]  $productIds
     * @return array<string, mixed>
     */
    public function proInfo(array $productIds): array
    {
        return [
            'info' => $this->legacyProducts($productIds)
                ->map(fn (Product $product): array => [
                    'id' => (int) $product->id,
                    'name' => (string) $product->name,
                    'location_version' => $this->legacyRevision($product),
                    'stock_control' => (int) ((int) ($product->stock ?? -1) >= 0),
                    'qty' => max((int) ($product->stock ?? -1), 0),
                ])
                ->values()
                ->all(),
            'currency' => $this->legacyCurrency(),
        ];
    }

    /**
     * @param  int[]  $productIds
     * @return array<string, mixed>
     */
    public function proDetail(array $productIds): array
    {
        $products = $this->legacyProducts($productIds);
        if ($products->count() > 50) {
            throw new BusinessException('单次最多获取50个商品详情', 42200, 422);
        }

        return [
            'detail' => $products
                ->mapWithKeys(fn (Product $product): array => [
                    (string) $product->id => $this->legacyProductDetail($product),
                ])
                ->all(),
        ];
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    public function legacyProductConfig(array $input): array
    {
        $product = $this->findLegacyProduct($this->productId($input));
        $detail = $this->legacyProductDetail($product);

        return [
            'flag' => [],
            'products' => $detail,
            'customfields' => [],
            'product_pricings' => $detail['product_pricings'],
            'config_groups' => $detail['config_groups'],
            'config_links' => $detail['config_links'],
            'advanced' => [],
        ];
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>
     */
    public function products(array $filters): array
    {
        $page = $this->page($filters);
        $pageSize = $this->pageSize($filters, 20, 50);
        $paginator = $this->saleProductQuery($filters)
            ->orderBy('sort_order')
            ->orderBy('id')
            ->paginate($pageSize, ['*'], 'page', $page);

        return [
            'list' => collect($paginator->items())
                ->filter(fn (mixed $product): bool => $product instanceof Product)
                ->map(fn (Product $product): array => $this->productPayload($product))
                ->values()
                ->all(),
            'total' => $paginator->total(),
            'page' => $paginator->currentPage(),
            'page_size' => $paginator->perPage(),
        ];
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    public function productConfig(array $input): array
    {
        $product = $this->findSaleProduct($this->productId($input));

        return [
            'product' => $this->productPayload($product, includeConfig: true),
            'config_options' => $this->cleanConfigOptions($product->config_options),
            'pricing' => $this->pricing($product),
        ];
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    public function quote(array $input): array
    {
        $product = $this->findSaleProduct($this->productId($input));
        $billingCycle = $this->billingCycle($input, $product);
        $quantity = max((int) ($input['quantity'] ?? $input['num'] ?? 1), 1);
        $config = is_array($input['config'] ?? null) ? (array) $input['config'] : [];
        $quote = $this->calculateProductQuote($product, $billingCycle, $config, $quantity);

        return [
            'product_id' => (int) $product->id,
            'billing_cycle' => $billingCycle,
            'quantity' => $quantity,
            'base_amount' => $quote['base_amount'],
            'config_amount' => $quote['config_amount'],
            'setup_fee' => $quote['setup_fee'],
            'total_amount' => $quote['total_amount'],
            'items' => $quote['items'],
        ];
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>
     */
    public function categories(array $filters): array
    {
        $productType = $this->productTypeFilter($filters);
        $firstGroupId = $this->positiveInt($filters['first_product_group_id'] ?? $filters['first_group_id'] ?? null);
        $firstGroups = FirstProductGroup::query()
            ->where('is_visible', 1)
            ->when($firstGroupId !== null, fn (Builder $query) => $query->whereKey($firstGroupId))
            ->when($this->firstGroupHasProductType(), function (Builder $query) use ($productType): void {
                $query
                    ->whereIn('product_type', ProductType::businessAllowedValues())
                    ->when($productType !== null, fn (Builder $typeQuery) => $typeQuery->where('product_type', $productType));
            })
            ->with([
                'secondProductGroups' => fn ($query) => $query
                    ->where('is_visible', 1)
                    ->with([
                        'thirdProductGroups' => fn ($thirdQuery) => $thirdQuery
                            ->where('is_visible', 1)
                            ->orderBy('sort_order')
                            ->orderBy('id'),
                    ])
                    ->orderBy('sort_order')
                    ->orderBy('id'),
            ])
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get()
            ->filter(fn (FirstProductGroup $group): bool => $this->businessProductTypeMatches($group, $productType));

        $list = $firstGroups
            ->map(fn (FirstProductGroup $group): array => $this->firstGroupPayload($group))
            ->values()
            ->all();

        return [
            'list' => $list,
            'cate' => $list,
        ];
    }

    /**
     * @param  int[]  $productIds
     * @return \Illuminate\Support\Collection<int, Product>
     */
    private function legacyProducts(array $productIds)
    {
        $products = $this->saleProductQuery([])
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get()
            ->filter(fn (mixed $product): bool => $product instanceof Product)
            ->filter(fn (Product $product): bool => $this->legacyType($product) !== null);

        if ($productIds === []) {
            return $products;
        }

        return $products
            ->filter(fn (Product $product): bool => in_array((int) $product->id, $productIds, true))
            ->values();
    }

    private function findLegacyProduct(int $productId): Product
    {
        $product = $this->findSaleProduct($productId);
        if ($this->legacyType($product) === null) {
            throw new BusinessException('该商品类型暂不支持导入魔方财务', 42200, 422);
        }

        return $product;
    }

    /**
     * @return array{id:string,name:string,path:list<string>}
     */
    private function legacySourceGroup(Product $product): array
    {
        $hierarchy = ProductGroupHierarchyFields::fromProduct($product);
        $level = (int) ($hierarchy['effective_product_group_level'] ?? 0);
        $effectiveId = (int) ($hierarchy['effective_product_group_id'] ?? 0);
        $path = array_values(array_filter([
            trim((string) ($hierarchy['first_product_group_name'] ?? '')),
            trim((string) ($hierarchy['second_product_group_name'] ?? '')),
            trim((string) ($hierarchy['third_product_group_name'] ?? '')),
        ], static fn (string $name): bool => $name !== ''));

        if ($path === []) {
            $path = [ProductType::businessLabelOf((string) $product->product_type)];
        }

        if ($level <= 0 || $effectiveId <= 0) {
            $level = 0;
            $effectiveId = (int) $product->id;
        }

        return [
            'id' => sprintf('turaidc:%d:%d', $level, $effectiveId),
            'name' => implode(' / ', $path),
            'path' => $path,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function legacyListProduct(Product $product, string $legacyType): array
    {
        return [
            'id' => (int) $product->id,
            'type' => $legacyType,
            'name' => (string) $product->name,
            'description' => (string) ($product->remark ?? ''),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function legacyProductDetail(Product $product): array
    {
        $legacyType = $this->legacyType($product);
        if ($legacyType === null) {
            throw new BusinessException('该商品类型暂不支持导入魔方财务', 42200, 422);
        }

        $sourceGroup = $this->legacySourceGroup($product);
        $pricing = $this->legacyPricingRow((array) $product->pricing, (string) ($product->setup_fee ?? '0.00'), 'product', (int) $product->id);
        [$configGroups, $configLinks] = $this->legacyConfigGroups($product);
        $stock = (int) ($product->stock ?? -1);

        return [
            'id' => (int) $product->id,
            'name' => (string) $product->name,
            'type' => $legacyType,
            'description' => (string) ($product->remark ?? ''),
            'groupid' => null,
            'source_group_id' => $sourceGroup['id'],
            'source_group_path' => $sourceGroup['path'],
            'location_version' => $this->legacyRevision($product),
            'password' => json_encode(['show' => 0, 'rule' => ['upper' => 1, 'lower' => 1, 'num' => 1, 'special' => 0]], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'host' => json_encode(['show' => 0], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'pay_type' => json_encode(['pay_type' => $this->legacyPayType($product), 'pay_ontrial_status' => 0], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'pay_method' => 'prepayment',
            'auto_setup' => (int) ((bool) ($product->auto_setup ?? false)),
            'auto_terminate_days' => 0,
            'config_options_upgrade' => 0,
            'down_configoption_refund' => 0,
            'retired' => 0,
            'is_featured' => 0,
            'hidden' => 0,
            'allow_qty' => 0,
            'is_truename' => 0,
            'is_bind_phone' => 0,
            'cancel_control' => 0,
            'api_type' => 'zjmf_api',
            'upstream_pid' => 0,
            'upstream_price_type' => 'percent',
            'upstream_price_value' => 100,
            'stock_control' => (int) ($stock >= 0),
            'upstream_stock_control' => (int) ($stock >= 0),
            'qty' => max($stock, 0),
            'upstream_qty' => max($stock, 0),
            'product_shopping_url' => '',
            'product_pricings' => [$pricing],
            'config_groups' => $configGroups,
            'config_links' => $configLinks,
            'customfields' => [],
            'advanced' => [],
        ];
    }

    /**
     * @return array{0:list<array<string,mixed>>,1:list<int>}
     */
    private function legacyConfigGroups(Product $product): array
    {
        $options = $this->cleanConfigOptions($product->config_options);
        if ($options === []) {
            return [[], []];
        }

        $base = $this->legacyConfigBase((int) $product->id);
        $groupId = $base + 1;
        $group = [
            'id' => $groupId,
            'name' => '配置选项',
            'description' => '',
            'options' => [],
        ];
        $subIndex = 0;

        foreach ($options as $optionIndex => $option) {
            $optionId = $base + 100 + $optionIndex;
            $optionName = trim((string) ($option['name'] ?? $option['label'] ?? $option['field'] ?? '配置项'));
            $legacyOption = [
                'id' => $optionId,
                'gid' => $groupId,
                'option_name' => $optionName,
                'option_type' => (int) ($option['option_type'] ?? 5),
                'hidden' => 0,
                'required' => (int) ((bool) ($option['required'] ?? $option['is_required'] ?? false)),
                'upgrade' => 0,
                'sub' => [],
            ];

            foreach ((array) ($option['sub'] ?? $option['options'] ?? []) as $sub) {
                if (! is_array($sub)) {
                    continue;
                }

                $subIndex++;
                $subId = $base + 1000 + $subIndex;
                $legacyOption['sub'][] = [
                    'id' => $subId,
                    'config_id' => $optionId,
                    'option_name' => (string) ($sub['option_name'] ?? $sub['name'] ?? $sub['label'] ?? $sub['id'] ?? ''),
                    'hidden' => 0,
                    'qty' => 0,
                    'pricings' => [$this->legacyPricingRow((array) ($sub['pricing'] ?? $sub['pricings'] ?? []), '0.00', 'configoptions', $subId)],
                ];
            }

            $group['options'][] = $legacyOption;
        }

        return [[$group], [$groupId]];
    }

    /**
     * @param  array<string, mixed>  $pricing
     * @return array<string, mixed>
     */
    private function legacyPricingRow(array $pricing, string $setupFee, string $type, int $relationId): array
    {
        $row = [
            'id' => 0,
            'type' => $type,
            'relid' => $relationId,
            'currency' => 0,
            'code' => $this->legacyCurrency(),
            'onetime' => -1,
            'osetupfee' => '0.00',
            'hour' => -1,
            'hsetupfee' => '0.00',
            'day' => -1,
            'dsetupfee' => '0.00',
            'ontrial' => -1,
            'ontrialfee' => '0.00',
        ];
        $cycles = [
            'monthly' => 'msetupfee',
            'quarterly' => 'qsetupfee',
            'semiannually' => 'ssetupfee',
            'annually' => 'asetupfee',
            'biennially' => 'bsetupfee',
            'triennially' => 'tsetupfee',
            'fourly' => 'foursetupfee',
            'fively' => 'fivesetupfee',
            'sixly' => 'sixsetupfee',
            'sevenly' => 'sevensetupfee',
            'eightly' => 'eightsetupfee',
            'ninely' => 'ninesetupfee',
            'tenly' => 'tensetupfee',
        ];

        foreach ($cycles as $cycle => $setupField) {
            $amount = $pricing[$cycle] ?? null;
            $row[$cycle] = is_numeric($amount) ? $this->money($amount) : -1;
            $row[$setupField] = is_numeric($amount) ? $this->money($setupFee) : '0.00';
        }

        return $row;
    }

    private function legacyConfigBase(int $productId): int
    {
        if ($productId <= 0 || $productId > 2_000_000_000) {
            throw new BusinessException('商品ID不支持生成魔方配置项映射', 42200, 422);
        }

        return $productId * 1_000_000;
    }

    private function legacyCurrency(): string
    {
        return trim((string) config('zjmf_bridge.catalog_currency', 'CNY')) ?: 'CNY';
    }

    private function legacyRevision(Product $product): int
    {
        $timestamp = $product->updated_at?->getTimestamp() ?? $product->created_at?->getTimestamp() ?? 0;

        return max($timestamp, 1);
    }

    private function legacyPayType(Product $product): string
    {
        foreach ((array) $product->pricing as $amount) {
            if (is_numeric($amount)) {
                return 'recurring';
            }
        }

        return 'free';
    }

    private function legacyType(Product $product): ?string
    {
        return match (ProductType::normalizeBusinessValue($product->product_type)) {
            ProductType::CLOUD_SERVER,
            ProductType::GAME_CLOUD,
            ProductType::CLOUD_DESKTOP => 'dcimcloud',
            ProductType::BARE_METAL,
            ProductType::PHYSICAL_MACHINE => 'dcim',
            ProductType::WEB_HOSTING => 'hostingaccount',
            default => null,
        };
    }

    /**
     * @param  array<string, mixed>  $filters
     */
    private function saleProductQuery(array $filters): Builder
    {
        $productType = $this->productTypeFilter($filters);
        $firstGroupId = $this->positiveInt($filters['first_product_group_id'] ?? $filters['first_group_id'] ?? null);
        $secondGroupId = $this->positiveInt($filters['second_product_group_id'] ?? null);
        $thirdGroupId = $this->positiveInt($filters['third_product_group_id'] ?? null);
        $effectiveGroupId = $this->positiveInt($filters['effective_product_group_id'] ?? $filters['group_id'] ?? null);

        return Product::query()
            ->onSale()
            ->whereNotNull('product_group_id')
            ->with([
                'productGroup.parent.parent',
            ])
            ->withVisibleProductGroupPath(ProductType::visibleValues())
            ->when($productType !== null, fn (Builder $query) => $query->underRootProductGroup(null, $productType))
            ->when($firstGroupId !== null, fn (Builder $query) => $query->inProductGroupTree($firstGroupId))
            ->when($secondGroupId !== null, fn (Builder $query) => $query->inProductGroupTree($secondGroupId))
            ->when($thirdGroupId !== null, fn (Builder $query) => $query->inCurrentProductGroup($thirdGroupId))
            ->when($effectiveGroupId !== null, function (Builder $query) use ($effectiveGroupId): void {
                $query->inCurrentProductGroup($effectiveGroupId);
            });
    }

    private function findSaleProduct(int $productId): Product
    {
        if ($productId <= 0) {
            throw new BusinessException('商品ID不能为空', 42200, 422);
        }

        $product = $this->saleProductQuery([])
            ->whereKey($productId)
            ->first();

        if (! $product instanceof Product) {
            throw new BusinessException('商品不存在', 40400, 404);
        }

        return $product;
    }

    /**
     * @return array<string, mixed>
     */
    private function productPayload(Product $product, bool $includeConfig = false): array
    {
        $hierarchy = ProductGroupHierarchyFields::fromProduct($product);
        $productType = (string) ($hierarchy['product_type'] ?? ProductType::OTHER);
        $pricing = $this->pricing($product);
        $primaryCycle = $this->primaryCycle($pricing);
        $firstGroupId = $hierarchy['first_product_group_id'] ?? null;
        $secondGroupId = $hierarchy['second_product_group_id'] ?? null;
        $thirdGroupId = $hierarchy['third_product_group_id'] ?? null;
        $effectiveGroupId = $hierarchy['effective_product_group_id'] ?? null;
        $effectiveGroupLevel = $hierarchy['effective_product_group_level'] ?? null;

        $payload = [
            'id' => (int) $product->id,
            'name' => (string) $product->name,
            'display_name' => (string) $product->name,
            'product_type' => $productType,
            'type' => $productType,
            'product_type_label' => ProductType::businessLabelOf($productType),
            'menu_code' => (string) ($hierarchy['first_product_group_code'] ?? ''),
            'first_product_group_id' => $firstGroupId,
            'first_product_group_code' => (string) ($hierarchy['first_product_group_code'] ?? ''),
            'first_product_group_name' => $hierarchy['first_product_group_name'] ?? null,
            'second_product_group_id' => $secondGroupId,
            'second_product_group_name' => $hierarchy['second_product_group_name'] ?? null,
            'third_product_group_id' => $thirdGroupId,
            'third_product_group_name' => $hierarchy['third_product_group_name'] ?? null,
            'effective_product_group_id' => $effectiveGroupId,
            'effective_product_group_level' => $effectiveGroupLevel,
            'primary_cycle' => $primaryCycle,
            'primary_price' => $primaryCycle !== '' ? $pricing[$primaryCycle] : '0.00',
            'setup_fee' => $this->money($product->setup_fee ?? 0),
            'stock' => (int) ($product->stock ?? -1),
            'auto_setup' => (int) ($product->auto_setup ?? 0),
            'pricing' => $pricing,
            'first_group' => [
                'id' => $firstGroupId,
                'name' => $hierarchy['first_product_group_name'] ?? null,
                'code' => (string) ($hierarchy['first_product_group_code'] ?? ''),
                'product_type' => $productType,
                'product_type_label' => ProductType::businessLabelOf($productType),
            ],
            'group' => [
                'id' => $effectiveGroupId,
                'level' => $effectiveGroupLevel,
                'name' => $hierarchy['third_product_group_name'] ?? $hierarchy['second_product_group_name'] ?? null,
                'parent_id' => $thirdGroupId ? (int) ($secondGroupId ?? 0) : (int) ($firstGroupId ?? 0),
            ],
        ];

        if ($includeConfig) {
            $payload['config_options'] = $this->cleanConfigOptions($product->config_options);
            $payload['purchase_requires'] = $this->removeSensitiveKeys($product->purchase_requires);
        }

        return $payload;
    }

    /**
     * @return array<string, mixed>
     */
    private function firstGroupPayload(FirstProductGroup $group): array
    {
        $productType = $this->businessProductType($group);

        return [
            'id' => (int) $group->id,
            'name' => (string) $group->name,
            'code' => (string) $group->code,
            'menu_code' => (string) $group->code,
            'product_type' => $productType,
            'product_type_label' => ProductType::businessLabelOf($productType),
            'level' => 1,
            'children' => $group->secondProductGroups
                ->map(fn (SecondProductGroup $child): array => $this->secondGroupPayload($child, $group))
                ->values()
                ->all(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function secondGroupPayload(SecondProductGroup $group, FirstProductGroup $firstGroup): array
    {
        $productType = $this->businessProductType($firstGroup);

        return [
            'id' => (int) $group->id,
            'parent_id' => (int) $firstGroup->id,
            'name' => (string) $group->name,
            'product_type' => $productType,
            'product_type_label' => ProductType::businessLabelOf($productType),
            'first_product_group_id' => (int) $firstGroup->id,
            'first_product_group_code' => (string) $firstGroup->code,
            'effective_product_group_id' => (int) $group->id,
            'effective_product_group_level' => 2,
            'level' => 2,
            'children' => $group->thirdProductGroups
                ->map(fn (ThirdProductGroup $child): array => $this->thirdGroupPayload($child, $group, $firstGroup))
                ->values()
                ->all(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function thirdGroupPayload(ThirdProductGroup $group, SecondProductGroup $secondGroup, FirstProductGroup $firstGroup): array
    {
        $productType = $this->businessProductType($firstGroup);

        return [
            'id' => (int) $group->id,
            'parent_id' => (int) $secondGroup->id,
            'name' => (string) $group->name,
            'product_type' => $productType,
            'product_type_label' => ProductType::businessLabelOf($productType),
            'first_product_group_id' => (int) $firstGroup->id,
            'first_product_group_code' => (string) $firstGroup->code,
            'second_product_group_id' => (int) $secondGroup->id,
            'effective_product_group_id' => (int) $group->id,
            'effective_product_group_level' => 3,
            'level' => 3,
            'children' => [],
        ];
    }

    private function businessProductType(?FirstProductGroup $firstGroup, ?Product $product = null): string
    {
        $candidate = trim((string) ($firstGroup?->product_type ?? ''));
        if ($candidate !== '') {
            return ProductType::normalizeBusinessValue($candidate);
        }

        $candidate = trim((string) ($product?->product_type ?? ''));
        if ($candidate !== '') {
            return ProductType::normalizeBusinessValue($candidate);
        }

        $code = trim((string) ($firstGroup?->legacy_product_type ?? $firstGroup?->code ?? $product?->service_type_code ?? ''));

        return ProductType::normalizeBusinessValueFromMenuCode($code);
    }

    private function businessProductTypeMatches(FirstProductGroup $group, ?string $productType): bool
    {
        $resolved = $this->businessProductType($group);

        return in_array($resolved, ProductType::businessAllowedValues(), true)
            && ($productType === null || $resolved === $productType);
    }

    private function firstGroupHasProductType(): bool
    {
        static $hasColumn = null;

        if ($hasColumn === null) {
            $hasColumn = Schema::hasColumn('first_product_groups', 'product_type');
        }

        return $hasColumn;
    }

    /**
     * @param  array<string, mixed>  $filters
     */
    private function productTypeFilter(array $filters): ?string
    {
        $raw = trim((string) ($filters['product_type'] ?? $filters['type'] ?? ''));

        return $raw !== '' ? ProductType::normalizeBusinessValue($raw) : null;
    }

    /**
     * @param  array<string, mixed>  $input
     */
    private function productId(array $input): int
    {
        return $this->positiveInt($input['product_id'] ?? $input['pid'] ?? $input['id'] ?? null) ?? 0;
    }

    /**
     * @param  array<string, mixed>  $input
     */
    private function billingCycle(array $input, Product $product): string
    {
        $billingCycle = trim((string) ($input['billing_cycle'] ?? $input['cycle'] ?? ''));
        $pricing = $this->pricing($product);

        if ($billingCycle === '') {
            return $this->primaryCycle($pricing);
        }

        if (! array_key_exists($billingCycle, $pricing)) {
            throw new BusinessException('无效的计费周期', 42200, 422);
        }

        return $billingCycle;
    }

    /**
     * @return array<string, string>
     */
    private function pricing(Product $product): array
    {
        $pricing = [];

        foreach ((array) ($product->pricing ?? []) as $cycle => $amount) {
            $cycle = (string) $cycle;
            if (! in_array($cycle, ['monthly', 'quarterly', 'semiannually', 'annually'], true)) {
                continue;
            }

            $pricing[$cycle] = $this->money($amount);
        }

        return $pricing;
    }

    /**
     * @param  array<string, string>  $pricing
     */
    private function primaryCycle(array $pricing): string
    {
        foreach (['monthly', 'quarterly', 'semiannually', 'annually'] as $cycle) {
            if (isset($pricing[$cycle]) && (float) $pricing[$cycle] > 0) {
                return $cycle;
            }
        }

        return (string) (array_key_first($pricing) ?? '');
    }

    private function cleanConfigOptions(mixed $configOptions): array
    {
        if (! is_array($configOptions)) {
            return [];
        }

        return collect($configOptions)
            ->filter(fn (mixed $item): bool => is_array($item))
            ->map(fn (array $item): array => $this->removeSensitiveKeys($item))
            ->values()
            ->all();
    }

    private function removeSensitiveKeys(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        $clean = [];
        foreach ($value as $key => $item) {
            if (is_string($key) && preg_match('/password|secret|api_key|raw_response|third_party_response/i', $key) === 1) {
                continue;
            }

            $clean[$key] = $this->removeSensitiveKeys($item);
        }

        return $clean;
    }

    /**
     * @param  array<string, mixed>  $filters
     */
    private function page(array $filters): int
    {
        return max((int) ($filters['page'] ?? 1), 1);
    }

    /**
     * @param  array<string, mixed>  $filters
     */
    private function pageSize(array $filters, int $default, int $max): int
    {
        $value = (int) ($filters['page_size'] ?? $filters['limit'] ?? $default);

        return min(max($value, 1), $max);
    }

    private function positiveInt(mixed $value): ?int
    {
        $integer = (int) $value;

        return $integer > 0 ? $integer : null;
    }

    private function money(mixed $value): string
    {
        return is_numeric($value) ? number_format((float) $value, 2, '.', '') : '0.00';
    }
}
