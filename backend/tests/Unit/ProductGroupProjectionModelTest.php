<?php

namespace Tests\Unit;

use App\Models\FirstProductGroup;
use App\Models\SecondProductGroup;
use App\Models\ThirdProductGroup;
use Illuminate\Database\Eloquent\Model;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

class ProductGroupProjectionModelTest extends TestCase
{
    #[Test]
    public function product_group_models_use_their_physical_tables_without_inheritance(): void
    {
        $this->assertSame('first_product_groups', (new FirstProductGroup)->getTable());
        $this->assertSame('second_product_groups', (new SecondProductGroup)->getTable());
        $this->assertSame('third_product_groups', (new ThirdProductGroup)->getTable());
        $this->assertSame(Model::class, get_parent_class(FirstProductGroup::class));
        $this->assertSame(Model::class, get_parent_class(SecondProductGroup::class));
        $this->assertSame(Model::class, get_parent_class(ThirdProductGroup::class));

        $this->assertTrue(method_exists(new FirstProductGroup, 'secondProductGroups'));
        $this->assertTrue(method_exists(new SecondProductGroup, 'thirdProductGroups'));
        $this->assertFalse(method_exists(new FirstProductGroup, 'products'));
        $this->assertFalse(method_exists(new SecondProductGroup, 'products'));
        $this->assertNotContains('legacy_product_type', (new FirstProductGroup)->getFillable());
        $this->assertNotContains('legacy_product_group_id', (new SecondProductGroup)->getFillable());
        $this->assertNotContains('legacy_product_group_id', (new ThirdProductGroup)->getFillable());
    }

    #[Test]
    public function admin_product_group_queries_use_the_first_group_physical_table(): void
    {
        $source = file_get_contents(__DIR__.'/../../app/Services/ProductCatalog/ProductGroupV2QueryService.php');

        $this->assertIsString($source);
        $this->assertStringContainsString("->select('first_product_groups.*')", $source);
        $this->assertStringContainsString("productTreeCountSubquery('first_product_groups.id', 1)", $source);
        $this->assertStringContainsString("directProductCountSubquery('first_product_groups.id', 1)", $source);
        $this->assertStringNotContainsString("->select('product_groups.*')", $source);
    }

    #[Test]
    public function coupon_first_group_query_uses_the_first_group_physical_table(): void
    {
        $source = file_get_contents(__DIR__.'/../../app/Services/ProductCatalog/CouponProductGroupQueryService.php');

        $this->assertIsString($source);
        $this->assertStringContainsString("productTreeCountSubquery('first_product_groups.id', 1)", $source);
        $this->assertStringContainsString("directProductCountSubquery('first_product_groups.id', 1)", $source);
        $this->assertStringNotContainsString("productTreeCountSubquery('product_groups.id', 1)", $source);
    }

    #[Test]
    public function product_tree_count_subquery_does_not_use_the_deleted_self_referencing_model(): void
    {
        $source = file_get_contents(__DIR__.'/../../app/Services/ProductCatalog/ProductGroupV2QueryService.php');

        $this->assertIsString($source);
        $this->assertStringContainsString("->join('third_product_groups as group_tree'", $source);
        $this->assertStringContainsString("->join('second_product_groups as group_tree_parent'", $source);
        $this->assertStringNotContainsString('use App\\Models\\ProductGroup;', $source);
        $this->assertStringNotContainsString("->from('product_groups as group_tree')", $source);
    }

    #[Test]
    public function physical_group_runtime_does_not_keep_legacy_table_or_backfill_paths(): void
    {
        $typeService = file_get_contents(__DIR__.'/../../app/Services/ProductCatalog/ProductTypeService.php');
        $hierarchyService = file_get_contents(__DIR__.'/../../app/Services/ProductCatalog/ProductGroupHierarchyService.php');
        $siteService = file_get_contents(__DIR__.'/../../app/Services/ProductCatalog/ProductSiteService.php');

        $this->assertIsString($typeService);
        $this->assertIsString($hierarchyService);
        $this->assertIsString($siteService);
        $this->assertStringNotContainsString("'product_groups'", $typeService);
        $this->assertStringNotContainsString('ProductCategory', $hierarchyService);
        $this->assertStringNotContainsString('syncAllFromLegacy', $hierarchyService);
        $this->assertStringNotContainsString('legacy_product_group_id', $hierarchyService);
        $this->assertStringNotContainsString("->join('product_groups as leaf'", $siteService);
    }
}
