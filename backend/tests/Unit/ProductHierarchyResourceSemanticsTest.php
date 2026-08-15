<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Models\FirstProductGroup;
use App\Models\Product;
use App\Models\SecondProductGroup;
use App\Models\ThirdProductGroup;
use App\Support\ProductGroupHierarchyFields;
use Tests\TestCase;

class ProductHierarchyResourceSemanticsTest extends TestCase
{
    public function test_product_hierarchy_fields_are_resolved_from_product_relations(): void
    {
        $product = new Product([
            'id' => 1001,
            'product_type' => 'cloud_server',
            'product_group_id' => 33,
            'service_type_code' => 'cloud_server',
            'pricing' => ['monthly' => '10.00'],
            'setup_fee' => '0.00',
            'config_options' => [],
            'purchase_requires' => [],
            'stock' => 1,
            'status' => 1,
            'sort_order' => 1,
        ]);
        $product->exists = true;
        $firstGroup = tap(new FirstProductGroup, function (FirstProductGroup $group): void {
            $group->setRawAttributes([
                'id' => 11,
                'code' => 'vps',
                'name' => '云服务器',
                'product_type' => 'cloud_server',
            ], true);
        });
        $secondGroup = tap(new SecondProductGroup, function (SecondProductGroup $group) use ($firstGroup): void {
            $group->setRawAttributes([
                'id' => 22,
                'first_product_group_id' => 11,
                'name' => '香港',
            ], true);
            $group->setRelation('firstProductGroup', $firstGroup);
        });
        $product->setRelation('productGroup', tap(new ThirdProductGroup, function (ThirdProductGroup $group) use ($secondGroup): void {
            $group->setRawAttributes([
                'id' => 33,
                'second_product_group_id' => 22,
                'name' => '精品线路',
            ], true);
            $group->setRelation('secondProductGroup', $secondGroup);
        }));

        $payload = ProductGroupHierarchyFields::fromProduct($product);

        $this->assertSame(11, $payload['first_product_group_id']);
        $this->assertSame(22, $payload['second_product_group_id']);
        $this->assertSame(33, $payload['third_product_group_id']);
        $this->assertSame(33, $payload['effective_product_group_id']);
        $this->assertSame(3, $payload['effective_product_group_level']);
        $this->assertSame('云服务器', $payload['first_product_group_name']);
        $this->assertSame('vps', $payload['first_product_group_code']);
        $this->assertSame('cloud_server', $payload['product_type']);
        $this->assertSame('cloud_server', $payload['service_type_code']);
        $this->assertSame('香港', $payload['second_product_group_name']);
        $this->assertSame('精品线路', $payload['third_product_group_name']);
    }

    public function test_product_resources_do_not_emit_legacy_group_aliases(): void
    {
        foreach ([
            app_path('Http/Resources/Product/ProductResource.php'),
            app_path('Http/Resources/Admin/V2/AdminProductSummaryResource.php'),
            app_path('Http/Resources/Product/ProductCategoryResource.php'),
        ] as $resourcePath) {
            $source = (string) file_get_contents($resourcePath);

            $this->assertStringNotContainsString("'category_id' =>", $source);
            $this->assertStringNotContainsString("'product_group_id' =>", $source);
            $this->assertStringNotContainsString("'group_id' =>", $source);
        }
    }

    public function test_product_model_does_not_expose_legacy_group_aliases(): void
    {
        $source = (string) file_get_contents(app_path('Models/Product.php'));

        $this->assertStringNotContainsString("'category_id'", $source);
        $this->assertStringNotContainsString('function categoryMapping', $source);
        $this->assertStringNotContainsString('function getCategoryIdAttribute', $source);
        $this->assertStringNotContainsString('function setCategoryIdAttribute', $source);
        $this->assertStringNotContainsString('function getGroupIdAttribute', $source);
        $this->assertStringNotContainsString('function setGroupIdAttribute', $source);
        $this->assertStringNotContainsString("setIfColumnExists('category_id'", $source);
        $this->assertStringNotContainsString("setIfColumnExists('first_product_group_id'", $source);
        $this->assertStringNotContainsString("setIfColumnExists('second_product_group_id'", $source);
        $this->assertStringNotContainsString("setIfColumnExists('third_product_group_id'", $source);
    }

    public function test_product_runtime_paths_do_not_use_legacy_group_relations(): void
    {
        foreach ([
            app_path('Models/Invoice.php'),
            app_path('Services/Finance/AdminFinanceQueryService.php'),
            app_path('Services/Finance/AdminOrderNotificationService.php'),
            app_path('Services/Finance/CheckoutService.php'),
            app_path('Services/Finance/CouponService.php'),
            app_path('Services/Finance/InvoiceService.php'),
            app_path('Services/Automation/BillingAutomationService.php'),
            app_path('Services/Order/OrderService.php'),
            app_path('Services/Provisioning/AdminServiceHostnameService.php'),
            app_path('Services/Provisioning/AdminServiceListService.php'),
            app_path('Services/Provisioning/ServiceRenewService.php'),
            app_path('Services/ProductCatalog/ProductAdminService.php'),
            app_path('Services/ProductCatalog/ProductCategoryService.php'),
            app_path('Services/ProductCatalog/ProductSyncService.php'),
            app_path('Services/ProductCatalog/ProductTypeService.php'),
            app_path('Services/Referral/ReferralService.php'),
            app_path('Services/System/OperationLogService.php'),
            app_path('Services/Ticket/TicketService.php'),
            app_path('Services/User/UserService.php'),
            app_path('Console/Commands/SyncUpstreamServicesCommand.php'),
            app_path('Http/Controllers/Admin/V2/ProductGroupController.php'),
            app_path('Http/Controllers/Admin/V2/SupplierController.php'),
            app_path('Services/Site/SiteProductQuoteService.php'),
        ] as $sourcePath) {
            $source = (string) file_get_contents($sourcePath);

            $this->assertStringNotContainsString('product:id,product_type,first_product_group_id', $source);
            $this->assertStringNotContainsString('order.product:id,product_type,first_product_group_id', $source);
            $this->assertStringNotContainsString('categoryMapping', $source);
            $this->assertStringNotContainsString('ProductCategory::query()', $source);
            $this->assertStringNotContainsString("'target_category_id' =>", $source);
            $this->assertStringNotContainsString("'target_group_id' =>", $source);
            $this->assertStringNotContainsString('hasPhysicalColumn(', $source);
            $this->assertStringNotContainsString('root_group_id', $source);
            $this->assertStringNotContainsString('root_category_id', $source);
            $this->assertStringNotContainsString('child_group_id', $source);
            $this->assertStringNotContainsString('child_category_id', $source);
            $this->assertStringNotContainsString('withProductHierarchyPayload', $source);
        }
    }

    public function test_client_service_console_paths_use_product_group_hierarchy(): void
    {
        $sourcePaths = array_merge(
            glob(app_path('Services/ClientServiceConsole/*.php')) ?: [],
            glob(app_path('Services/ClientServiceConsole/Concerns/*.php')) ?: [],
        );

        $this->assertNotEmpty($sourcePaths);

        foreach ($sourcePaths as $sourcePath) {
            $source = (string) file_get_contents($sourcePath);

            $this->assertStringNotContainsString('product:id,product_type,first_product_group_id', $source, $sourcePath);
            $this->assertStringNotContainsString('->category_id', $source, $sourcePath);
            $this->assertStringNotContainsString('->group_id', $source, $sourcePath);
            $this->assertStringNotContainsString('categoryMapping', $source, $sourcePath);
            $this->assertStringNotContainsString('ProductCategory', $source, $sourcePath);
        }
    }
}
