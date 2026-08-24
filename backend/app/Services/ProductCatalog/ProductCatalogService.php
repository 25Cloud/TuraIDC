<?php

declare(strict_types=1);

namespace App\Services\ProductCatalog;

use App\Models\Product;
use App\Models\Supplier;
use Illuminate\Database\Eloquent\Collection;

/**
 * ProductCatalogService - 外观类，委托给各专责子服务。
 *
 * 子服务：
 *   - ProductCategoryService 分类管理（三层商品分组）
 *   - ProductAdminService  商品后台管理（列表、CRUD、排序、移动、开关）
 *   - ProductSyncService   上游同步（批量同步、固化绑定、库存）
 *   - ProductSiteService   前台目录（商品类型、分组树、商品卡片、全量 Catalog）
 */
class ProductCatalogService
{
    public function __construct(
        private readonly ProductCategoryService $categoryService,
        private readonly ProductAdminService $adminService,
        private readonly ProductSyncService $syncService,
        private readonly ProductSiteService $siteService,
    ) {}

    // -------------------------------------------------------------------------
    // 分组管理
    // -------------------------------------------------------------------------

    public function adminSummary(): array
    {
        return $this->categoryService->adminSummary();
    }

    public function adminGroupTree(?string $productType = null): array
    {
        return $this->categoryService->adminCategoryTree($productType);
    }

    public function adminCategoryTree(?string $productType = null): array
    {
        return $this->categoryService->adminCategoryTree($productType);
    }

    public function groupOptions(?string $productType = null): array
    {
        return $this->categoryService->categoryOptions($productType);
    }

    public function categoryOptions(?string $productType = null): array
    {
        return $this->categoryService->categoryOptions($productType);
    }

    public function createGroup(array $data): array
    {
        return $this->categoryService->createCategory($data);
    }

    public function createCategory(array $data): array
    {
        return $this->categoryService->createCategory($data);
    }

    public function updateGroup(int $groupId, array $data): array
    {
        return $this->categoryService->updateCategory($groupId, $data);
    }

    public function updateCategory(int $groupId, array $data): array
    {
        return $this->categoryService->updateCategory($groupId, $data);
    }

    public function deleteGroup(int $groupId, int $level): void
    {
        $this->categoryService->deleteCategory($groupId, $level);
    }

    public function deleteCategory(int $groupId, int $level): void
    {
        $this->categoryService->deleteCategory($groupId, $level);
    }

    public function forceDeleteCategory(int $groupId, int $level): array
    {
        return $this->categoryService->forceDeleteCategory($groupId, $level);
    }

    public function batchUpdateGroupProducts(int $groupId, int $level, array $data): array
    {
        return $this->categoryService->batchUpdateGroupProducts($groupId, $level, $data);
    }

    public function reorderAdminGroups(int $level, ?int $parentId, array $groupIds): array
    {
        return $this->categoryService->reorderAdminCategories($level, $parentId, $groupIds);
    }

    public function reorderAdminCategories(int $level, ?int $parentId, array $groupIds): array
    {
        return $this->categoryService->reorderAdminCategories($level, $parentId, $groupIds);
    }

    public function moveAdminGroup(
        int $level,
        int $groupId,
        ?int $targetParentId,
        ?int $referenceGroupId,
        string $position = 'append',
    ): array {
        return $this->categoryService->moveAdminCategory($level, $groupId, $targetParentId, $referenceGroupId, $position);
    }

    public function moveAdminCategory(
        int $level,
        int $groupId,
        ?int $targetParentId,
        ?int $referenceCategoryId,
        string $position = 'append',
    ): array {
        return $this->categoryService->moveAdminCategory($level, $groupId, $targetParentId, $referenceCategoryId, $position);
    }

    // -------------------------------------------------------------------------
    // 商品后台管理
    // -------------------------------------------------------------------------

    public function adminProductList(array $filters, int $perPage = 20)
    {
        return $this->adminService->adminProductList($filters, $perPage);
    }

    public function reorderAdminProducts(array $filters, int $page, int $pageSize, array $productIds): array
    {
        return $this->adminService->reorderAdminProducts($filters, $page, $pageSize, $productIds);
    }

    public function moveAdminProduct(
        Product $product,
        array $targetHierarchy,
        ?int $referenceProductId,
        string $position = 'append',
    ): array {
        return $this->adminService->moveAdminProduct($product, $targetHierarchy, $referenceProductId, $position);
    }

    public function adminProductOwners(Product $product, array $filters, int $perPage = 20): array
    {
        return $this->adminService->adminProductOwners($product, $filters, $perPage);
    }

    public function createProduct(array $data): Product
    {
        return $this->adminService->createProduct($data);
    }

    public function updateProduct(Product $product, array $data): Product
    {
        return $this->adminService->updateProduct($product, $data);
    }

    public function batchUpdateProvisionHostname(array $data, array $context = []): array
    {
        return $this->adminService->batchUpdateProvisionHostname($data, $context);
    }

    public function batchUpdateCategory(array $data): array
    {
        return $this->adminService->batchUpdateCategory($data);
    }

    public function batchUpdateProducts(array $data): array
    {
        return $this->adminService->batchUpdateProducts($data);
    }

    public function batchDeleteProducts(array $data): array
    {
        return $this->adminService->batchDeleteProducts($data);
    }

    public function batchForceDeleteProducts(array $data): array
    {
        return $this->adminService->batchForceDeleteProducts($data);
    }

    public function splitProducts(array $data): array
    {
        return $this->adminService->splitProducts($data);
    }

    public function previewSplitProducts(array $data): array
    {
        return $this->adminService->previewSplitProducts($data);
    }

    public function updateProductSortOrder(Product $product, int $sortOrder): Product
    {
        return $this->adminService->updateProductSortOrder($product, $sortOrder);
    }

    public function deleteProduct(Product $product): void
    {
        $this->adminService->deleteProduct($product);
    }

    public function restoreProduct(Product $product): Product
    {
        return $this->adminService->restoreProduct($product);
    }

    public function forceDeleteProduct(Product $product): void
    {
        $this->adminService->forceDeleteProduct($product);
    }

    public function toggleProductStatus(Product $product): Product
    {
        return $this->adminService->toggleProductStatus($product);
    }

    // -------------------------------------------------------------------------
    // 上游同步 / 库存
    // -------------------------------------------------------------------------

    public function batchSyncProducts(array $data): array
    {
        return $this->syncService->batchSyncProducts($data);
    }

    public function finalizeUpstreamBindings(array $data = []): array
    {
        return $this->syncService->finalizeUpstreamBindings($data);
    }

    public function syncUpstreamProductConfigOptions(): array
    {
        return $this->syncService->syncUpstreamProductConfigOptions();
    }

    public function syncUpstreamProductStocks(?string $providerKey = null): array
    {
        return $this->syncService->syncUpstreamProductStocks($providerKey);
    }

    public function applyLiveStockToProduct(Product $product, bool $strict = false): Product
    {
        return $this->syncService->applyLiveStockToProduct($product, $strict);
    }

    public function applyLiveStockToProducts(Collection $products, bool $strict = false): Collection
    {
        return $this->syncService->applyLiveStockToProducts($products, $strict);
    }

    public function siteProductStock(int $productId): ?array
    {
        return $this->syncService->siteProductStock($productId);
    }

    public function assertProductCanBeProvisioned(Product $product, int $requiredQuantity = 1): void
    {
        $this->syncService->assertProductCanBeProvisioned($product, $requiredQuantity);
    }

    public function bulkConnectSupplierProducts(Supplier $supplier, array $data): array
    {
        return $this->syncService->bulkConnectSupplierProducts($supplier, $data);
    }

    // -------------------------------------------------------------------------
    // 前台商品目录
    // -------------------------------------------------------------------------

    public function siteProductTypes(): array
    {
        return $this->siteService->siteProductTypes();
    }

    public function siteRootGroups(?string $productType = null): array
    {
        return $this->siteService->siteRootGroups($productType);
    }

    public function siteRootCategories(?string $productType = null): array
    {
        return $this->siteService->siteRootGroups($productType);
    }

    public function siteChildGroups(int $groupId): array
    {
        return $this->siteService->siteChildGroups($groupId);
    }

    public function siteChildCategories(int $groupId): array
    {
        return $this->siteService->siteChildGroups($groupId);
    }

    public function siteGroupCatalog(int $groupId): array
    {
        return $this->siteService->siteGroupCatalog($groupId);
    }

    public function siteCategoryCatalog(int $groupId): array
    {
        return $this->siteService->siteGroupCatalog($groupId);
    }

    public function siteProductsByGroupIds(array $groupIds): array
    {
        return $this->siteService->siteProductsByGroupIds($groupIds);
    }

    public function siteProductDetail(int $productId): ?array
    {
        return $this->siteService->siteProductDetail($productId);
    }

    public function siteCatalog(): Collection
    {
        return $this->siteService->siteCatalog();
    }
}
