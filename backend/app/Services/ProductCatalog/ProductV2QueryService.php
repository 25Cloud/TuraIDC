<?php

declare(strict_types=1);

namespace App\Services\ProductCatalog;

use App\Constants\ProductType;
use App\Exceptions\BusinessException;
use App\Models\Product;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;

class ProductV2QueryService
{
    public function __construct(
        private readonly ProductCatalogService $catalog,
        private readonly ProductGroupV2QueryService $productGroups,
        private readonly ProductSiteService $siteProducts,
    ) {}

    /**
     * @param  array<string, mixed>  $filters
     */
    public function paginateAdminProducts(array $filters): LengthAwarePaginator
    {
        return $this->catalog->adminProductList($filters, $this->perPage($filters, 20, 100));
    }

    /**
     * @return array<string, mixed>
     */
    public function adminSummary(): array
    {
        return $this->catalog->adminSummary();
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>
     */
    public function adminProductOwners(Product $product, array $filters, int $pageSize): array
    {
        return $this->catalog->adminProductOwners($product, $filters, $pageSize);
    }

    public function findAdminProduct(int $productId): Product
    {
        $product = Product::query()
            ->withTrashed()
            ->select($this->adminProductDetailColumns())
            ->with([
                'productGroup.secondProductGroup.firstProductGroup',
                'upstreamBindings.supplierPluginBinding.supplier',
                'productDiscountGroup',
            ])
            ->withCount([
                'orders',
                'services as total_services_count',
                'services as active_services_count' => fn (Builder $query) => $query->where('status', 1),
            ])
            ->find($productId);

        if (! $product instanceof Product) {
            throw new BusinessException('商品不存在', 40400, 404);
        }

        return $product;
    }

    /**
     * @param  array<string, mixed>  $filters
     */
    public function paginateSiteProducts(array $filters): LengthAwarePaginator
    {
        $paginator = $this->siteProductQuery($filters)
            ->select($this->siteProductListColumns())
            ->with([
                'productGroup.secondProductGroup.firstProductGroup',
            ])
            ->orderBy('sort_order')
            ->orderBy('id')
            ->paginate($this->perPage($filters, 20, 50), ['*'], 'page', $this->page($filters));

        return $this->attachCpuModelPayloads($paginator);
    }

    public function findSiteProduct(int $productId): Product
    {
        $product = $this->siteProductQuery([])
            ->select($this->siteProductDetailColumns())
            ->with([
                'productGroup.secondProductGroup.firstProductGroup',
            ])
            ->whereKey($productId)
            ->first();

        if (! $product instanceof Product) {
            throw new BusinessException('商品不存在', 40400, 404);
        }

        return $product;
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>
     */
    public function purchaseContext(array $filters): array
    {
        $types = $this->catalog->siteProductTypes();
        $firstGroupCode = trim((string) ($filters['first_product_group_code'] ?? ''));
        $productType = trim((string) ($filters['product_type'] ?? ''));

        if ($firstGroupCode === '' && $types !== []) {
            $firstGroupCode = (string) ($types[0]['first_product_group_code'] ?? $types[0]['value'] ?? '');
        }

        $rootGroups = $this->productGroups->paginateSiteRootGroups([
            'first_product_group_code' => $firstGroupCode !== '' ? $firstGroupCode : null,
            'product_type' => $productType !== '' ? $productType : null,
            'page' => 1,
            'page_size' => $filters['root_page_size'] ?? 50,
        ]);

        return [
            'types' => $types,
            'root_groups' => $rootGroups,
        ];
    }

    /**
     * @param  array<string, mixed>  $filters
     */
    private function siteProductQuery(array $filters): Builder
    {
        $visibleProductTypes = ProductType::visibleValues();

        if ($visibleProductTypes === []) {
            return Product::query()->whereRaw('1 = 0');
        }

        $firstGroupCode = trim((string) ($filters['first_product_group_code'] ?? ''));
        $productType = trim((string) ($filters['product_type'] ?? $filters['type'] ?? ''));

        return Product::query()
            ->onSale()
            ->whereNotNull('product_group_id')
            ->withVisibleProductGroupPath($visibleProductTypes)
            ->underRootProductGroup($firstGroupCode !== '' ? $firstGroupCode : null, $productType !== '' ? $productType : null)
            ->when(isset($filters['first_product_group_id']), fn (Builder $query) => $query->inFirstProductGroup((int) $filters['first_product_group_id']))
            ->when(isset($filters['second_product_group_id']), fn (Builder $query) => $query->inSecondProductGroup((int) $filters['second_product_group_id']))
            ->when(isset($filters['third_product_group_id']), fn (Builder $query) => $query->inCurrentProductGroup((int) $filters['third_product_group_id']))
            ->when(isset($filters['effective_product_group_id']), function (Builder $query) use ($filters): void {
                $query->inCurrentProductGroup((int) $filters['effective_product_group_id']);
            });
    }

    /**
     * @return list<string>
     */
    private function adminProductDetailColumns(): array
    {
        return [
            'id',
            'product_type',
            'remark',
            'pricing',
            'setup_fee',
            'config_options',
            'purchase_requires',
            'stock',
            'status',
            'sort_order',
            'auto_setup',
            'deleted_at',
            ...Product::optionalSelectColumns([
                'custom_display_name',
                'product_group_id',
                'service_type_code',
                'console_template',
                'cpu_model',
                'cpu_turbo',
                'product_discount_group_id',
            ]),
            'created_at',
            'updated_at',
        ];
    }

    /**
     * @return list<string>
     */
    private function siteProductListColumns(): array
    {
        return [
            'id',
            'product_type',
            ...Product::optionalSelectColumns([
                'custom_display_name',
                'product_group_id',
                'service_type_code',
                'cpu_model',
                'cpu_turbo',
            ]),
            'pricing',
            'setup_fee',
            'config_options',
            'purchase_requires',
            'stock',
            'auto_setup',
            'sort_order',
        ];
    }

    /**
     * @return list<string>
     */
    private function siteProductDetailColumns(): array
    {
        return [
            ...$this->siteProductListColumns(),
            'status',
        ];
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
    private function perPage(array $filters, int $default, int $max): int
    {
        $pageSize = (int) ($filters['page_size'] ?? $default);

        return min(max($pageSize, 1), $max);
    }

    private function attachCpuModelPayloads(LengthAwarePaginator $paginator): LengthAwarePaginator
    {
        foreach ($paginator->items() as $item) {
            if (! $item instanceof Product) {
                continue;
            }

            foreach ($this->siteProducts->cpuModelPayloadForProduct($item) as $key => $value) {
                $item->setAttribute($key, $value);
            }
        }

        return $paginator;
    }
}
