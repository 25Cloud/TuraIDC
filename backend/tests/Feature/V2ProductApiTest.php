<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Constants\ProductType;
use App\Constants\ServiceStatus;
use App\Models\AdminUser;
use App\Models\FirstProductGroup;
use App\Models\IntegrationPlugin;
use App\Models\Product;
use App\Models\ProductUpstreamBinding;
use App\Models\Role;
use App\Models\SecondProductGroup;
use App\Models\Service;
use App\Models\Setting;
use App\Models\Supplier;
use App\Models\SupplierPluginBinding;
use App\Models\ThirdProductGroup;
use App\Models\User;
use App\Services\Site\SiteProductQuoteService;
use App\Services\Site\SiteProductReadService;
use App\Support\AdminPermissions;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Cache;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class V2ProductApiTest extends TestCase
{
    use DatabaseTransactions;

    public function test_admin_products_require_login_and_permission(): void
    {
        $this->getJson('/api/v2/admin/products')
            ->assertUnauthorized()
            ->assertJsonPath('code', 40100);

        Sanctum::actingAs($this->createAdmin([]));

        $this->getJson('/api/v2/admin/products')
            ->assertForbidden()
            ->assertJsonPath('code', 40300);
    }

    public function test_admin_product_list_rejects_per_page_and_returns_summary_whitelist(): void
    {
        $suffix = bin2hex(random_bytes(4));
        $firstGroup = $this->createFirstGroup('v2_admin_'.$suffix, '后台商品 '.$suffix, true);
        $secondGroup = $this->createSecondGroup($firstGroup, '后台二级 '.$suffix, 1, true);
        $product = Product::query()->create($this->productPayload($secondGroup, null, '后台商品 '.$suffix, '19.00', 1));

        Sanctum::actingAs($this->createAdmin([AdminPermissions::PRODUCT_LIST]));

        $this->getJson('/api/v2/admin/products?per_page=20')
            ->assertUnprocessable()
            ->assertJsonPath('code', 42200)
            ->assertJsonStructure(['data' => ['errors' => ['per_page']]]);

        $this->getJson('/api/v2/admin/products?pageSize=20')
            ->assertUnprocessable()
            ->assertJsonPath('code', 42200)
            ->assertJsonStructure(['data' => ['errors' => ['pageSize']]]);

        $response = $this->getJson('/api/v2/admin/products?'.http_build_query([
            'keyword' => $suffix,
            'page' => 1,
            'page_size' => 10,
        ]))
            ->assertOk()
            ->assertJsonPath('code', 0)
            ->assertJsonPath('data.page', 1)
            ->assertJsonPath('data.page_size', 10)
            ->assertJsonPath('data.list.0.id', $product->id);

        $this->assertSame($this->adminProductListWhitelist(), array_keys($response->json('data.list.0')));
        $this->assertNoSensitiveKeys($response->json());
        $this->assertLessThan(100 * 1024, strlen((string) $response->getContent()));
    }

    public function test_admin_product_list_counts_services_in_all_statuses(): void
    {
        $suffix = bin2hex(random_bytes(4));
        $firstGroup = $this->createFirstGroup('v2_service_count_'.$suffix, '服务统计 '.$suffix, true);
        $secondGroup = $this->createSecondGroup($firstGroup, '服务统计二级 '.$suffix, 1, true);
        $product = Product::query()->create($this->productPayload($secondGroup, null, '服务统计商品 '.$suffix, '19.00', 1));
        $user = User::query()->create([
            'email' => 'v2-product-service-'.$suffix.'@example.com',
            'password' => 'Temp@123456',
            'phone' => '13'.str_pad((string) random_int(0, 999999999), 9, '0', STR_PAD_LEFT),
            'status' => 1,
            'nickname' => 'V2 商品服务 '.$suffix,
            'real_name' => '',
            'id_card' => '',
            'verification_status' => 0,
            'verification_message' => '',
            'verification_certify_id' => null,
            'member_level_id' => null,
            'total_sales_amount' => '0.00',
            'referrer_user_id' => null,
            'verified_at' => null,
        ]);

        foreach ([ServiceStatus::ACTIVE, ServiceStatus::CANCELLED] as $index => $status) {
            Service::query()->create([
                'user_id' => (int) $user->id,
                'product_id' => (int) $product->id,
                'name' => 'V2 商品服务 '.$suffix.' '.$index,
                'domain' => 'v2-product-service-'.$suffix.'-'.$index.'.example.test',
                'billing_cycle' => 'monthly',
                'amount' => '19.00',
                'locked_pricing' => ['monthly' => '19.00'],
                'status' => $status,
                'provision_data' => [],
                'expires_at' => $index === 0 ? now()->addMonth() : now()->subDay(),
                'auto_renew' => 0,
            ]);
        }

        Sanctum::actingAs($this->createAdmin([AdminPermissions::PRODUCT_LIST]));

        $response = $this->getJson('/api/v2/admin/products?'.http_build_query([
            'keyword' => $suffix,
            'page' => 1,
            'page_size' => 10,
        ]))
            ->assertOk()
            ->assertJsonPath('code', 0);

        $item = collect((array) $response->json('data.list'))->firstWhere('id', (int) $product->id);
        $this->assertIsArray($item);
        $this->assertSame(2, $item['services_count']);
        $this->assertSame(2, $item['total_services_count']);
        $this->assertSame(2, $item['active_services_count']);
    }

    public function test_admin_product_detail_is_modular_and_preserves_provider_key(): void
    {
        $suffix = bin2hex(random_bytes(4));
        $firstGroup = $this->createFirstGroup('v2_detail_'.$suffix, '详情商品 '.$suffix, true);
        $secondGroup = $this->createSecondGroup($firstGroup, '详情二级 '.$suffix, 1, true);
        $product = Product::query()->create($this->productPayload($secondGroup, null, '详情商品 '.$suffix, '29.00', 1, [
            'config_options' => [
                [
                    'id' => 1,
                    'field' => 'cpu',
                    'name' => 'CPU',
                    'api_key' => 'should-not-leak',
                    'sub' => [
                        ['id' => '2', 'label' => '2 核', 'secret' => 'hidden'],
                    ],
                ],
            ],
            'purchase_requires' => [
                'require_phone' => true,
                'password' => 'should-not-leak',
            ],
        ]));
        $this->createProductBinding($product, $suffix);

        Sanctum::actingAs($this->createAdmin([AdminPermissions::PRODUCT_LIST]));

        $response = $this->getJson('/api/v2/admin/products/'.$product->id)
            ->assertOk()
            ->assertJsonPath('code', 0)
            ->assertJsonPath('data.product.id', $product->id)
            ->assertJsonPath('data.product.upstream_binding.provider_key', 'zjmf_finance_api')
            ->assertJsonPath('data.product.upstream_binding.upstream_product_id', 'mf-'.$suffix);

        $this->assertSame($this->adminProductDetailWhitelist(), array_keys($response->json('data.product')));
        $this->assertNoSensitiveKeys($response->json());
        $this->assertLessThan(100 * 1024, strlen((string) $response->getContent()));
    }

    public function test_site_products_are_public_paginated_whitelisted_and_visibility_scoped(): void
    {
        $suffix = bin2hex(random_bytes(4));
        $firstGroup = $this->visibleSiteFirstGroup();
        $secondGroup = $this->createSecondGroup($firstGroup, '站点二级 '.$suffix, 1, true);
        $thirdGroup = $this->createThirdGroup($secondGroup, '站点三级 '.$suffix, 1, true);
        $hiddenSecondGroup = $this->createSecondGroup($firstGroup, '隐藏二级 '.$suffix, 2, false);
        $visibleProduct = Product::query()->create($this->productPayload($secondGroup, $thirdGroup, '站点商品 '.$suffix, '39.00', -1000));
        Product::query()->create($this->productPayload($hiddenSecondGroup, null, '隐藏商品 '.$suffix, '49.00', 2));
        Product::query()->create($this->productPayload($secondGroup, $thirdGroup, '下架商品 '.$suffix, '59.00', 3, ['status' => 0]));
        Setting::setValue('product', 'cpu_model_catalog', json_encode([
            [
                'id' => 'group_intel_'.$suffix,
                'value' => 'intel_'.$suffix,
                'name' => 'Intel',
                'models' => [
                    [
                        'id' => 'model_gold_'.$suffix,
                        'value' => 'intel_gold_'.$suffix,
                        'name' => 'Intel Xeon Gold 6133',
                        'base_frequency' => '2.40GHz',
                        'turbo_frequency' => '3.10GHz',
                        'bindings' => [
                            [
                                'product_id' => (int) $visibleProduct->id,
                                'product_name' => (string) $visibleProduct->name,
                                'category_full_name' => '云服务器 / 站点三级',
                                'status' => 1,
                            ],
                        ],
                    ],
                ],
            ],
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

        $this->getJson('/api/v2/site/products?per_page=20')
            ->assertUnprocessable()
            ->assertJsonPath('code', 42200)
            ->assertJsonStructure(['data' => ['errors' => ['per_page']]]);

        $this->getJson('/api/v2/site/products?pageSize=20')
            ->assertUnprocessable()
            ->assertJsonPath('code', 42200)
            ->assertJsonStructure(['data' => ['errors' => ['pageSize']]]);

        $response = $this->getJson('/api/v2/site/products?'.http_build_query([
            'first_product_group_code' => 'vps',
            'page' => 1,
            'page_size' => 50,
        ]))
            ->assertOk()
            ->assertJsonPath('code', 0);

        $ids = collect($response->json('data.list'))->pluck('id')->map(fn ($id): int => (int) $id)->all();
        $this->assertContains((int) $visibleProduct->id, $ids);
        $listProduct = collect((array) $response->json('data.list'))
            ->firstWhere('id', (int) $visibleProduct->id);
        $this->assertIsArray($listProduct);
        $this->assertSame('Intel Xeon Gold 6133', $listProduct['cpu_model_name'] ?? null);
        $this->assertSame('2.40GHz', $listProduct['cpu_base_frequency'] ?? null);
        $this->assertSame('3.10GHz', $listProduct['cpu_turbo_frequency'] ?? null);
        $this->assertSame($this->siteProductListWhitelist(), array_keys($response->json('data.list.0')));
        $this->assertNoSensitiveKeys($response->json());
        $this->assertLessThan(50 * 1024, strlen((string) $response->getContent()));
    }

    public function test_site_product_detail_hides_invisible_products_and_sensitive_keys(): void
    {
        $suffix = bin2hex(random_bytes(4));
        $firstGroup = $this->visibleSiteFirstGroup();
        $secondGroup = $this->createSecondGroup($firstGroup, '详情站点二级 '.$suffix, 1, true);
        $visibleProduct = Product::query()->create($this->productPayload($secondGroup, null, '详情站点商品 '.$suffix, '69.00', 1, [
            'config_options' => [
                [
                    'id' => 1,
                    'field' => 'memory',
                    'name' => '内存',
                    'secret' => 'should-not-leak',
                    'sub' => [
                        ['id' => '4', 'label' => '4G', 'api_key' => 'hidden'],
                    ],
                ],
            ],
        ]));
        $hiddenProduct = Product::query()->create($this->productPayload($secondGroup, null, '隐藏详情商品 '.$suffix, '79.00', 2, ['status' => 0]));

        $this->getJson('/api/v2/site/products/'.$hiddenProduct->id)
            ->assertNotFound()
            ->assertJsonPath('code', 40400);

        $this->getJson('/api/v2/site/products/'.$visibleProduct->id.'?page=1')
            ->assertUnprocessable()
            ->assertJsonPath('code', 42200)
            ->assertJsonStructure(['data' => ['errors' => ['page']]]);

        $this->getJson('/api/v2/site/products/'.$visibleProduct->id.'?pageSize=20')
            ->assertUnprocessable()
            ->assertJsonPath('code', 42200)
            ->assertJsonStructure(['data' => ['errors' => ['pageSize']]]);

        $response = $this->getJson('/api/v2/site/products/'.$visibleProduct->id)
            ->assertOk()
            ->assertJsonPath('code', 0)
            ->assertJsonPath('data.product.id', $visibleProduct->id)
            ->assertJsonPath('data.product.product_type', 'cloud_server');

        $this->assertSame($this->siteProductDetailWhitelist(), array_keys($response->json('data.product')));
        $this->assertNoSensitiveKeys($response->json());
        $this->assertLessThan(100 * 1024, strlen((string) $response->getContent()));
    }

    public function test_site_purchase_context_stays_small_and_does_not_embed_catalog(): void
    {
        $suffix = bin2hex(random_bytes(4));
        $firstGroup = $this->visibleSiteFirstGroup();
        $otherFirstGroup = $this->visibleSiteFirstGroupByCode('dedicated', '游戏云');

        foreach (range(1, 20) as $index) {
            $this->createSecondGroup($firstGroup, '首屏分组 '.$suffix.' '.$index, $index, true);
        }

        $this->createSecondGroup($otherFirstGroup, '其它首屏分组 '.$suffix, 1, true);
        Cache::flush();

        $this->getJson('/api/v2/site/product-purchase-context?per_page=20')
            ->assertUnprocessable()
            ->assertJsonPath('code', 42200)
            ->assertJsonStructure(['data' => ['errors' => ['per_page']]]);

        $this->getJson('/api/v2/site/product-purchase-context?pageSize=20')
            ->assertUnprocessable()
            ->assertJsonPath('code', 42200)
            ->assertJsonStructure(['data' => ['errors' => ['pageSize']]]);

        $defaultResponse = $this->getJson('/api/v2/site/product-purchase-context?'.http_build_query([
            'root_page_size' => 50,
        ]))
            ->assertOk()
            ->assertJsonPath('code', 0)
            ->assertJsonMissingPath('data.catalog');

        $this->assertSame(
            ['vps'],
            collect($defaultResponse->json('data.root_groups'))
                ->pluck('first_product_group_code')
                ->unique()
                ->values()
                ->all()
        );
        $this->assertNotContains(
            '其它首屏分组 '.$suffix,
            collect($defaultResponse->json('data.root_groups'))->pluck('name')->all()
        );

        $response = $this->getJson('/api/v2/site/product-purchase-context?'.http_build_query([
            'first_product_group_code' => 'vps',
            'root_page_size' => 20,
        ]))
            ->assertOk()
            ->assertJsonPath('code', 0)
            ->assertJsonMissingPath('data.catalog');

        $this->assertSame($this->sitePurchaseContextWhitelist(), array_keys($response->json('data')));
        $this->assertNoSensitiveKeys($response->json());
        $this->assertLessThan(50 * 1024, strlen((string) $response->getContent()));
    }

    public function test_site_product_auxiliary_v2_endpoints_are_whitelisted_and_reject_legacy_pagination(): void
    {
        $readService = $this->createMock(SiteProductReadService::class);
        $readService->expects($this->once())
            ->method('productTypes')
            ->willReturn([
                'list' => [[
                    'id' => 1,
                    'value' => 'vps',
                    'label' => '云服务器',
                    'product_type' => 'cloud_server',
                    'product_type_label' => '云服务器',
                    'product_type_icon' => 'Platform',
                    'product_type_plugin_driven' => false,
                    'first_product_group_id' => 10,
                    'first_product_group_code' => 'vps',
                    'first_product_group_name' => '云服务器',
                    'icon' => 'server',
                    'group_count' => 2,
                    'product_count' => 3,
                    'secret' => 'should-not-leak',
                ]],
            ]);
        $readService->expects($this->once())
            ->method('productStock')
            ->with(501)
            ->willReturn([
                'product_id' => 501,
                'stock' => 7,
                'stock_status' => 'available',
                'updated_at' => '2026-07-05 00:00:00',
                'raw_response' => ['should-not-leak' => true],
            ]);

        $quoteService = $this->createMock(SiteProductQuoteService::class);
        $quoteService->expects($this->once())
            ->method('resolveQuotePayload')
            ->with(
                501,
                $this->callback(fn (array $payload): bool => ($payload['billing_cycle'] ?? null) === 'monthly'
                    && (int) ($payload['quantity'] ?? 0) === 2),
                $this->callback(fn (array $context): bool => (int) ($context['user_id'] ?? -1) === 0)
            )
            ->willReturn([
                'product_id' => 501,
                'billing_cycle' => 'monthly',
                'base_amount' => '100.00',
                'config_amount' => '8.00',
                'setup_fee' => '0.00',
                'subtotal_amount' => '216.00',
                'discount_amount' => '16.00',
                'total_amount' => '200.00',
                'quantity' => 2,
                'coupon' => [
                    'user_coupon_id' => 9,
                    'coupon_id' => 3,
                    'name' => '测试优惠券',
                    'discount_amount' => '16.00',
                    'api_key' => 'should-not-leak',
                ],
                'user_coupon_id' => 9,
                'available_coupons' => [
                    ['id' => 3, 'name' => '测试优惠券', 'discount_amount' => '16.00'],
                ],
                'items' => [
                    ['field' => 'cpu', 'label' => 'CPU', 'amount' => '8.00', 'secret' => 'should-not-leak'],
                ],
                'quote_token' => 'quote-token-v2',
                'quote_expires_at' => '2026-07-05T00:10:00+08:00',
                'raw_response' => 'should-not-leak',
            ]);

        $this->app->instance(SiteProductReadService::class, $readService);
        $this->app->instance(SiteProductQuoteService::class, $quoteService);

        $this->getJson('/api/v2/site/product-types?per_page=20')
            ->assertUnprocessable()
            ->assertJsonPath('code', 42200)
            ->assertJsonStructure(['data' => ['errors' => ['per_page']]]);

        $this->getJson('/api/v2/site/product-types?pageSize=20')
            ->assertUnprocessable()
            ->assertJsonPath('code', 42200)
            ->assertJsonStructure(['data' => ['errors' => ['pageSize']]]);

        $typesResponse = $this->getJson('/api/v2/site/product-types')
            ->assertOk()
            ->assertJsonPath('code', 0)
            ->assertJsonPath('data.list.0.label', '云服务器');

        $this->assertSame([
            'id',
            'value',
            'label',
            'first_product_group_id',
            'first_product_group_code',
            'first_product_group_name',
            'icon',
            'group_count',
            'product_count',
        ], array_keys($typesResponse->json('data.list.0')));

        $this->getJson('/api/v2/site/products/501/stock?per_page=20')
            ->assertUnprocessable()
            ->assertJsonPath('code', 42200)
            ->assertJsonStructure(['data' => ['errors' => ['per_page']]]);

        $this->getJson('/api/v2/site/products/501/stock?page=1')
            ->assertUnprocessable()
            ->assertJsonPath('code', 42200)
            ->assertJsonStructure(['data' => ['errors' => ['page']]]);

        $this->getJson('/api/v2/site/products/501/stock?pageSize=20')
            ->assertUnprocessable()
            ->assertJsonPath('code', 42200)
            ->assertJsonStructure(['data' => ['errors' => ['pageSize']]]);

        $stockResponse = $this->getJson('/api/v2/site/products/501/stock')
            ->assertOk()
            ->assertJsonPath('code', 0)
            ->assertJsonPath('data.product_id', 501)
            ->assertJsonPath('data.stock', 7);

        $this->assertSame(['product_id', 'stock', 'stock_status', 'updated_at'], array_keys($stockResponse->json('data')));

        $this->postJson('/api/v2/site/products/501/quote', [
            'billing_cycle' => 'monthly',
            'quantity' => 2,
            'per_page' => 20,
        ])
            ->assertUnprocessable()
            ->assertJsonPath('code', 42200)
            ->assertJsonStructure(['data' => ['errors' => ['per_page']]]);

        $this->postJson('/api/v2/site/products/501/quote', [
            'billing_cycle' => 'monthly',
            'quantity' => 2,
            'page_size' => 20,
        ])
            ->assertUnprocessable()
            ->assertJsonPath('code', 42200)
            ->assertJsonStructure(['data' => ['errors' => ['page_size']]]);

        $this->postJson('/api/v2/site/products/501/quote', [
            'billing_cycle' => 'monthly',
            'quantity' => 2,
            'pageSize' => 20,
        ])
            ->assertUnprocessable()
            ->assertJsonPath('code', 42200)
            ->assertJsonStructure(['data' => ['errors' => ['pageSize']]]);

        $quoteResponse = $this->postJson('/api/v2/site/products/501/quote', [
            'billing_cycle' => 'monthly',
            'config' => [],
            'quantity' => 2,
            'user_coupon_id' => 9,
        ])
            ->assertOk()
            ->assertJsonPath('code', 0)
            ->assertJsonPath('data.quote_token', 'quote-token-v2')
            ->assertJsonPath('data.total_amount', '200.00')
            ->assertJsonPath('data.items.0.field', 'cpu');

        $this->assertSame([
            'product_id',
            'billing_cycle',
            'base_amount',
            'config_amount',
            'setup_fee',
            'subtotal_amount',
            'discount_amount',
            'total_amount',
            'quantity',
            'coupon',
            'user_coupon_id',
            'available_coupons',
            'quote_token',
            'quote_expires_at',
            'items',
        ], array_keys($quoteResponse->json('data')));
        $this->assertNoSensitiveKeys($typesResponse->json());
        $this->assertNoSensitiveKeys($stockResponse->json());
        $this->assertNoSensitiveKeys($quoteResponse->json());
        $this->assertLessThan(50 * 1024, strlen((string) $typesResponse->getContent()));
        $this->assertLessThan(50 * 1024, strlen((string) $stockResponse->getContent()));
        $this->assertLessThan(50 * 1024, strlen((string) $quoteResponse->getContent()));
    }

    private function createAdmin(array $permissions): AdminUser
    {
        $suffix = bin2hex(random_bytes(4));
        $role = Role::query()->create([
            'name' => 'v2-products-'.$suffix,
            'label' => 'V2 Products',
            'permissions' => $permissions,
        ]);

        return AdminUser::query()->create([
            'username' => 'v2-products-'.$suffix,
            'password' => 'Temp@123456',
            'role_id' => (int) $role->id,
            'nickname' => 'V2 Products',
            'email' => 'v2-products-'.$suffix.'@example.com',
            'status' => 1,
        ]);
    }

    private function createFirstGroup(string $code, string $name, bool $visible): FirstProductGroup
    {
        return FirstProductGroup::query()->create([
            'code' => $code,
            'name' => $name,
            'slug' => 'first-'.$code,
            'description' => $name.' 说明',
            'sort_order' => 1,
            'is_visible' => $visible ? 1 : 0,
            'is_system' => 0,
            'legacy_product_type' => $code,
        ]);
    }

    private function visibleSiteFirstGroup(): FirstProductGroup
    {
        $group = FirstProductGroup::query()->where('code', 'vps')->first();

        if ($group instanceof FirstProductGroup) {
            $group->update([
                'name' => $group->name ?: '云服务器',
                'slug' => $group->slug ?: 'vps',
                'is_visible' => 1,
                'product_type' => ProductType::CLOUD_SERVER,
            ]);

            return $group->refresh();
        }

        return FirstProductGroup::query()->create([
            'code' => 'vps',
            'name' => '云服务器',
            'slug' => 'vps',
            'description' => '云服务器',
            'sort_order' => 1,
            'is_visible' => 1,
            'is_system' => 1,
            'legacy_product_type' => 'vps',
            'product_type' => ProductType::CLOUD_SERVER,
        ]);
    }

    private function visibleSiteFirstGroupByCode(string $code, string $name): FirstProductGroup
    {
        $group = FirstProductGroup::query()->where('code', $code)->first();

        if ($group instanceof FirstProductGroup) {
            $group->update([
                'name' => $group->name ?: $name,
                'slug' => $group->slug ?: $code,
                'is_visible' => 1,
                'product_type' => ProductType::normalizeBusinessValueFromMenuCode($code),
            ]);

            return $group->refresh();
        }

        return FirstProductGroup::query()->create([
            'code' => $code,
            'name' => $name,
            'slug' => $code,
            'description' => $name,
            'sort_order' => 1,
            'is_visible' => 1,
            'is_system' => 1,
            'legacy_product_type' => $code,
            'product_type' => ProductType::normalizeBusinessValueFromMenuCode($code),
        ]);
    }

    private function createSecondGroup(FirstProductGroup $firstGroup, string $name, int $sortOrder, bool $visible): SecondProductGroup
    {
        return SecondProductGroup::query()->create([
            'first_product_group_id' => (int) $firstGroup->id,
            'name' => $name,
            'slug' => 'second-'.$firstGroup->id.'-'.$sortOrder.'-'.bin2hex(random_bytes(3)),
            'description' => $name.' 说明',
            'sort_order' => $sortOrder,
            'is_visible' => $visible ? 1 : 0,
        ]);
    }

    private function createThirdGroup(SecondProductGroup $secondGroup, string $name, int $sortOrder, bool $visible): ThirdProductGroup
    {
        return ThirdProductGroup::query()->create([
            'second_product_group_id' => (int) $secondGroup->id,
            'name' => $name,
            'slug' => 'third-'.$secondGroup->id.'-'.$sortOrder.'-'.bin2hex(random_bytes(3)),
            'description' => $name.' 说明',
            'sort_order' => $sortOrder,
            'is_visible' => $visible ? 1 : 0,
        ]);
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function productPayload(
        SecondProductGroup $secondGroup,
        ?ThirdProductGroup $thirdGroup,
        string $name,
        string $monthlyPrice,
        int $sortOrder,
        array $overrides = [],
    ): array {
        $thirdGroup ??= $this->createThirdGroup($secondGroup, $name.' 三级分组', $sortOrder, true);
        $firstGroup = $secondGroup->firstProductGroup ?: FirstProductGroup::query()->findOrFail((int) $secondGroup->first_product_group_id);
        $code = (string) $firstGroup->code;
        $productType = ProductType::businessValueForFirstGroup($firstGroup, $code);

        return array_replace([
            'product_group_id' => (int) $thirdGroup->id,
            'service_type_code' => $productType,
            'custom_display_name' => $name,
            'product_type' => $productType,
            'pricing' => [
                'monthly' => $monthlyPrice,
                'quarterly' => number_format((float) $monthlyPrice * 3, 2, '.', ''),
                'semiannually' => number_format((float) $monthlyPrice * 6, 2, '.', ''),
                'annually' => number_format((float) $monthlyPrice * 12, 2, '.', ''),
            ],
            'setup_fee' => '0.00',
            'purchase_requires' => [],
            'config_options' => [],
            'stock' => -1,
            'status' => 1,
            'sort_order' => $sortOrder,
            'auto_setup' => 1,
        ], $overrides);
    }

    private function createProductBinding(Product $product, string $suffix): void
    {
        $plugin = IntegrationPlugin::query()->create([
            'domain' => 'servers',
            'slug' => 'zjmf-finance-'.$suffix,
            'plugin_key' => 'servers.zjmf_finance.'.$suffix,
            'name' => 'ZJMF 财务 '.$suffix,
            'version' => '1.0.0',
            'provider_class' => 'Tests\\FakeProvider',
            'entry_class' => 'Tests\\FakePlugin',
            'capabilities_json' => [],
            'config_schema_json' => [],
            'status' => 1,
            'installed_at' => now(),
        ]);
        $supplier = Supplier::query()->create([
            'name' => 'ZJMF供应商 '.$suffix,
            'code' => 'mf-'.$suffix,
            'status' => 1,
            'sort_order' => 1,
        ]);
        $supplierBinding = SupplierPluginBinding::query()->create([
            'supplier_id' => (int) $supplier->id,
            'plugin_id' => (int) $plugin->id,
            'provider_key' => 'zjmf_finance_api',
            'environment' => 'production',
            'status' => 1,
            'priority' => 10,
            'config_json' => [],
            'has_secret_json' => ['api_key' => true],
        ]);

        ProductUpstreamBinding::query()->create([
            'product_id' => (int) $product->id,
            'supplier_plugin_binding_id' => (int) $supplierBinding->id,
            'plugin_id' => (int) $plugin->id,
            'provider_key' => 'zjmf_finance_api',
            'upstream_product_id' => 'mf-'.$suffix,
            'upstream_product_snapshot_json' => ['raw_response' => 'should-not-leak'],
            'option_schema_json' => ['secret' => 'should-not-leak'],
            'provision_policy_json' => ['password' => 'should-not-leak'],
            'auto_setup' => true,
            'status' => 1,
            'last_synced_at' => now(),
        ]);
    }

    /**
     * @return list<string>
     */
    private function adminProductListWhitelist(): array
    {
        return [
            'id',
            'name',
            'display_name',
            'product_spec_display',
            'custom_display_name',
            'product_display_name',
            'cpu_memory_display',
            'combined_display_name',
            'product_type',
            'type',
            'product_type_label',
            'type_label',
            'category_full_name',
            'effective_product_group_full_name',
            'first_product_group_id',
            'first_product_group_code',
            'first_product_group_name',
            'second_product_group_id',
            'second_product_group_name',
            'third_product_group_id',
            'third_product_group_name',
            'effective_product_group_id',
            'effective_product_group_level',
            'primary_price',
            'monthly_price',
            'primary_cycle',
            'stock',
            'status',
            'is_deleted',
            'lifecycle_status',
            'deleted_at',
            'auto_setup',
            'provision_hostname',
            'provision_hostname_mode',
            'provision_hostname_summary',
            'services_count',
            'total_services_count',
            'active_services_count',
            'sort_order',
            'updated_at',
        ];
    }

    /**
     * @return list<string>
     */
    private function adminProductDetailWhitelist(): array
    {
        return [
            'id',
            'display',
            'classification',
            'pricing',
            'configuration',
            'purchase_requirements',
            'provisioning',
            'upstream_binding',
            'statistics',
            'lifecycle',
            'timestamps',
        ];
    }

    /**
     * @return list<string>
     */
    private function siteProductListWhitelist(): array
    {
        return [
            'id',
            'name',
            'display_name',
            'cpu_model_name',
            'cpu_base_frequency',
            'cpu_turbo_frequency',
            'product_type',
            'first_product_group_id',
            'first_product_group_code',
            'first_product_group_name',
            'second_product_group_id',
            'second_product_group_name',
            'second_product_group_description',
            'second_product_group_parent_id',
            'second_product_group_parent_name',
            'third_product_group_id',
            'third_product_group_name',
            'third_product_group_description',
            'effective_product_group_id',
            'effective_product_group_level',
            'product_type_label',
            'service_type_code',
            'primary_cycle',
            'primary_price',
            'setup_fee',
            'stock',
            'auto_setup',
        ];
    }

    /**
     * @return list<string>
     */
    private function siteProductDetailWhitelist(): array
    {
        return [
            'id',
            'name',
            'display_name',
            'product_display_name',
            'product_spec_display',
            'combined_display_name',
            'cpu_memory_display',
            'cpu_display',
            'memory_display',
            'cpu_model_name',
            'cpu_base_frequency',
            'cpu_turbo_frequency',
            'product_type',
            'type',
            'type_label',
            'first_product_group_id',
            'first_product_group_code',
            'first_product_group_name',
            'second_product_group_id',
            'second_product_group_name',
            'second_product_group_description',
            'second_product_group_parent_id',
            'second_product_group_parent_name',
            'third_product_group_id',
            'third_product_group_name',
            'third_product_group_description',
            'effective_product_group_id',
            'effective_product_group_level',
            'product_type_label',
            'service_type_code',
            'pricing',
            'pricing_entries',
            'primary_cycle',
            'primary_price',
            'setup_fee',
            'stock',
            'auto_setup',
            'group',
            'config_options',
            'siblings',
        ];
    }

    /**
     * @return list<string>
     */
    private function sitePurchaseContextWhitelist(): array
    {
        return [
            'types',
            'root_groups',
            'root_groups_total',
            'root_groups_page',
            'root_groups_page_size',
        ];
    }

    private function assertNoSensitiveKeys(mixed $payload): void
    {
        if (! is_array($payload)) {
            return;
        }

        foreach ($payload as $key => $value) {
            if (is_string($key)) {
                foreach (['password', 'secret', 'api_key', 'raw_response', 'third_party_response'] as $needle) {
                    $this->assertStringNotContainsString($needle, strtolower($key));
                }
            }

            $this->assertNoSensitiveKeys($value);
        }
    }
}
