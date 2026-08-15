<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\AdminUser;
use App\Models\FirstProductGroup;
use App\Models\Order;
use App\Models\Product;
use App\Models\Role;
use App\Models\SecondProductGroup;
use App\Models\Setting;
use App\Models\Supplier;
use App\Models\ThirdProductGroup;
use App\Services\Provisioning\ProvisionService;
use App\Services\System\SettingService;
use App\Services\Upstream\Drivers\HostingPanelApi\HostingPanelApiDriver;
use App\Services\Upstream\ProviderKey;
use App\Services\Upstream\ProviderRegistry;
use App\Services\Upstream\ProviderResolver;
use App\Support\AdminPermissions;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ProductSplitRegressionTest extends TestCase
{
    public function test_admin_can_split_upstream_product_by_cpu_and_memory_options(): void
    {
        $suffix = bin2hex(random_bytes(4));
        $role = Role::query()->create([
            'name' => 'product-split-'.$suffix,
            'label' => 'Product Split',
            'permissions' => [AdminPermissions::ALL],
        ]);
        $admin = AdminUser::query()->create([
            'username' => 'product-split-'.$suffix,
            'password' => 'Temp@123456',
            'role_id' => (int) $role->id,
            'nickname' => 'Product Split',
            'email' => 'product-split-'.$suffix.'@example.com',
            'status' => 1,
        ]);
        $supplier = Supplier::query()->create([
            'name' => 'Split Supplier '.$suffix,
            'code' => 'split-supplier-'.$suffix,
            'interface_type' => 'hosting_panel_api',
            'api_url' => 'https://example.com',
            'api_username' => 'tester',
            'api_key' => 'secret',
            'status' => 1,
            'sort_order' => 0,
        ]);
        $secondGroupId = $this->createSecondGroupId('cpumem-'.$suffix);
        $source = Product::query()->create([
            'product_group_id' => $secondGroupId,
            'name' => '美国 2h2g '.$suffix,
            'product_type' => 'vps',
            'pricing' => ['monthly' => '50.00', 'quarterly' => '150.00'],
            'setup_fee' => '0.00',
            'purchase_requires' => [
                'require_phone' => true,
                'upstream_default_config' => [
                    'cpu' => '2',
                    'memory' => '2',
                ],
            ],
            'config_options' => [
                [
                    'id' => 61000,
                    'config_id' => 61000,
                    'field' => 'cpu',
                    'name' => 'CPU',
                    'option_type' => 6,
                    'hidden' => 0,
                    'sub' => [
                        [
                            'id' => 70001,
                            'config_id' => 61000,
                            'option_name_first' => '2',
                            'option_name' => '2H',
                            'hidden' => 0,
                            'pricing' => ['monthly' => '0.00', 'quarterly' => '0.00'],
                        ],
                        [
                            'id' => 70002,
                            'config_id' => 61000,
                            'option_name_first' => '4',
                            'option_name' => '4H',
                            'hidden' => 0,
                            'pricing' => ['monthly' => '30.00', 'quarterly' => '90.00'],
                        ],
                    ],
                ],
                [
                    'id' => 61001,
                    'config_id' => 61001,
                    'field' => 'memory',
                    'name' => '内存',
                    'option_type' => 8,
                    'hidden' => 0,
                    'sub' => [
                        [
                            'id' => 71001,
                            'config_id' => 61001,
                            'option_name_first' => '2',
                            'option_name' => '2G',
                            'hidden' => 0,
                            'pricing' => ['monthly' => '0.00', 'quarterly' => '0.00'],
                        ],
                        [
                            'id' => 71002,
                            'config_id' => 61001,
                            'option_name_first' => '4',
                            'option_name' => '4G',
                            'hidden' => 0,
                            'pricing' => ['monthly' => '20.00', 'quarterly' => '60.00'],
                        ],
                    ],
                ],
            ],
            'stock' => -1,
            'status' => 1,
            'sort_order' => 5,
            'provision_module' => 'hosting_panel_api',
            'auto_setup' => 1,
            'supplier_id' => (int) $supplier->id,
            'supplier_product_id' => 9001,
        ]);

        Sanctum::actingAs($admin);

        $previewResponse = $this->postJson('/api/v2/admin/products/split-previews', [
            'product_ids' => [(int) $source->id],
        ]);
        $previewResponse
            ->assertOk()
            ->assertJsonPath('data.requested_count', 1)
            ->assertJsonPath('data.preview_count', 4)
            ->assertJsonPath('data.skipped_count', 0)
            ->assertJsonPath('data.items.0.source_display_name', '2 vCPU 2G')
            ->assertJsonPath('data.items.0.variants.0.display_name', '2 vCPU 2G')
            ->assertJsonPath('data.items.0.variants.1.display_name', '2 vCPU 4G')
            ->assertJsonPath('data.items.0.variants.2.display_name', '4 vCPU 2G')
            ->assertJsonPath('data.items.0.variants.3.display_name', '4 vCPU 4G')
            ->assertJsonPath('data.items.0.variants.0.action', 'update')
            ->assertJsonPath('data.items.0.variants.1.action', 'create')
            ->assertJsonPath('data.items.0.variants.3.cpu', '4')
            ->assertJsonPath('data.items.0.variants.3.memory', '4');

        $this->postJson('/api/v2/admin/products/splits', [
            'product_ids' => [(int) $source->id],
        ])
            ->assertOk()
            ->assertJsonPath('data.requested_count', 1)
            ->assertJsonPath('data.created_count', 3)
            ->assertJsonPath('data.updated_count', 1)
            ->assertJsonPath('data.skipped_count', 0);

        $baseVariant = Product::query()->findOrFail((int) $source->id);
        $upgradedVariant = Product::query()
            ->get()
            ->first(fn (Product $product) => (int) (($product->purchase_requires['upstream_split'] ?? [])['source_product_id'] ?? 0) === (int) $source->id
                && (string) (($product->purchase_requires['upstream_split'] ?? [])['variant_key'] ?? '') === 'cpu=4;memory=4');

        $this->assertInstanceOf(Product::class, $upgradedVariant);

        $this->assertSame('50.00', $baseVariant->pricing['monthly'] ?? null);
        $this->assertSame('100.00', $upgradedVariant->pricing['monthly'] ?? null);
        $this->assertSame((int) $source->id, (int) $baseVariant->id);
        $this->assertSame('2', (string) (($baseVariant->purchase_requires['upstream_default_config'] ?? [])['cpu'] ?? ''));
        $this->assertSame('2', (string) (($baseVariant->purchase_requires['upstream_default_config'] ?? [])['memory'] ?? ''));
        $this->assertSame('4', (string) (($upgradedVariant->purchase_requires['upstream_default_config'] ?? [])['cpu'] ?? ''));
        $this->assertSame('4', (string) (($upgradedVariant->purchase_requires['upstream_default_config'] ?? [])['memory'] ?? ''));
        $this->assertSame((int) $source->id, (int) (($upgradedVariant->purchase_requires['upstream_split'] ?? [])['source_product_id'] ?? 0));
        $this->assertSame('cpu=4;memory=4', (string) (($upgradedVariant->purchase_requires['upstream_split'] ?? [])['variant_key'] ?? ''));
        $this->assertSame(['cpu', 'memory'], collect($baseVariant->config_options)->pluck('field')->values()->all());
        $this->assertSame(['cpu', 'memory'], collect($upgradedVariant->config_options)->pluck('field')->values()->all());
        $this->assertSame(1, count((array) ($upgradedVariant->config_options[0]['sub'] ?? [])));
        $this->assertSame(1, count((array) ($upgradedVariant->config_options[1]['sub'] ?? [])));
        $this->assertSame('4', (string) (($upgradedVariant->config_options[0]['sub'][0]['option_name_first'] ?? '')));
        $this->assertSame('4', (string) (($upgradedVariant->config_options[1]['sub'][0]['option_name_first'] ?? '')));
        $this->assertSame('0.00', (string) (($upgradedVariant->config_options[0]['sub'][0]['pricing']['monthly'] ?? '')));
        $this->assertSame('0.00', (string) (($upgradedVariant->config_options[1]['sub'][0]['pricing']['monthly'] ?? '')));
        $this->assertSame(1, (int) (($upgradedVariant->config_options[0]['hidden'] ?? 0)));
        $this->assertSame(1, (int) (($upgradedVariant->config_options[1]['hidden'] ?? 0)));
        $this->assertSame(
            4,
            Product::query()->where('product_group_id', $secondGroupId)->count()
        );
    }

    public function test_split_inherits_alias_cpu_memory_fields_and_cpu_model_for_site_display(): void
    {
        $suffix = bin2hex(random_bytes(4));
        $role = Role::query()->create([
            'name' => 'product-split-alias-spec-'.$suffix,
            'label' => 'Product Split Alias Spec',
            'permissions' => [AdminPermissions::ALL],
        ]);
        $admin = AdminUser::query()->create([
            'username' => 'product-split-alias-spec-'.$suffix,
            'password' => 'Temp@123456',
            'role_id' => (int) $role->id,
            'nickname' => 'Product Split Alias Spec',
            'email' => 'product-split-alias-spec-'.$suffix.'@example.com',
            'status' => 1,
        ]);
        $thirdGroupId = $this->createSecondGroupId('alias-'.$suffix);
        $secondGroupId = (int) ThirdProductGroup::query()->findOrFail($thirdGroupId)->second_product_group_id;
        $firstGroupId = (int) SecondProductGroup::query()->findOrFail($secondGroupId)->first_product_group_id;
        $source = Product::query()->create([
            'product_group_id' => $thirdGroupId,
            'service_type_code' => 'vps',
            'name' => 'Alias node 2H2G '.$suffix,
            'product_type' => 'vps',
            'pricing' => ['monthly' => '50.00'],
            'setup_fee' => '0.00',
            'purchase_requires' => [
                'upstream_default_config' => [
                    'cpu_num' => '2',
                    'memory_size' => '2048',
                ],
            ],
            'config_options' => [
                [
                    'id' => 66001,
                    'config_id' => 66001,
                    'field' => 'cpu_num',
                    'name' => 'CPU cores',
                    'option_type' => 6,
                    'hidden' => 0,
                    'sub' => [
                        ['id' => 76001, 'option_name_first' => '2', 'option_name' => '2H', 'hidden' => 0, 'pricing' => ['monthly' => '0.00']],
                        ['id' => 76002, 'option_name_first' => '4', 'option_name' => '4H', 'hidden' => 0, 'pricing' => ['monthly' => '20.00']],
                    ],
                ],
                [
                    'id' => 66002,
                    'config_id' => 66002,
                    'field' => 'memory_size',
                    'name' => 'RAM size',
                    'option_type' => 8,
                    'hidden' => 0,
                    'sub' => [
                        ['id' => 76011, 'option_name_first' => '2048', 'option_name' => '2G', 'hidden' => 0, 'pricing' => ['monthly' => '0.00']],
                        ['id' => 76012, 'option_name_first' => '4096', 'option_name' => '4G', 'hidden' => 0, 'pricing' => ['monthly' => '30.00']],
                    ],
                ],
            ],
            'stock' => -1,
            'status' => 1,
            'sort_order' => 0,
            'provision_module' => 'hosting_panel_api',
            'auto_setup' => 1,
        ]);

        Setting::setValue('product', 'cpu_model_catalog', json_encode([
            [
                'id' => 'group_alias_intel',
                'value' => 'alias_intel',
                'name' => 'Alias Intel',
                'models' => [
                    [
                        'id' => 'model_alias_6338',
                        'value' => 'intel_xeon_gold_6338',
                        'name' => 'Intel Xeon Gold 6338',
                        'base_frequency' => '2.00GHz',
                        'turbo_frequency' => '3.20GHz',
                        'bindings' => [
                            [
                                'product_id' => (int) $source->id,
                                'category_full_name' => 'Cloud / Alias',
                                'primary_price' => ['cycle' => 'monthly', 'amount' => '50.00'],
                                'status' => 1,
                            ],
                        ],
                    ],
                ],
            ],
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

        Sanctum::actingAs($admin);

        $this->postJson('/api/v2/admin/products/split-previews', [
            'product_ids' => [(int) $source->id],
        ])
            ->assertOk()
            ->assertJsonPath('data.preview_count', 4)
            ->assertJsonPath('data.items.0.variants.3.display_name', '4 vCPU 4G')
            ->assertJsonPath('data.items.0.variants.3.cpu', '4')
            ->assertJsonPath('data.items.0.variants.3.memory', '4096');

        $this->postJson('/api/v2/admin/products/splits', [
            'product_ids' => [(int) $source->id],
        ])
            ->assertOk()
            ->assertJsonPath('data.created_count', 3)
            ->assertJsonPath('data.updated_count', 1)
            ->assertJsonPath('data.skipped_count', 0);

        $splitProduct = Product::query()
            ->get()
            ->first(fn (Product $product) => (int) (($product->purchase_requires['upstream_split'] ?? [])['source_product_id'] ?? 0) === (int) $source->id
                && (string) (($product->purchase_requires['upstream_split'] ?? [])['variant_key'] ?? '') === 'cpu=4;memory=4096');

        $this->assertInstanceOf(Product::class, $splitProduct);
        $this->assertSame('4', (string) (($splitProduct->purchase_requires['upstream_default_config'] ?? [])['cpu'] ?? ''));
        $this->assertSame('4096', (string) (($splitProduct->purchase_requires['upstream_default_config'] ?? [])['memory'] ?? ''));
        $this->assertSame(['cpu_num', 'memory_size'], collect($splitProduct->config_options)->pluck('field')->values()->all());
        $this->assertSame(1, count((array) ($splitProduct->config_options[0]['sub'] ?? [])));
        $this->assertSame(1, count((array) ($splitProduct->config_options[1]['sub'] ?? [])));
        $this->assertSame('4', (string) (($splitProduct->config_options[0]['sub'][0]['option_name_first'] ?? '')));
        $this->assertSame('4096', (string) (($splitProduct->config_options[1]['sub'][0]['option_name_first'] ?? '')));

        $this->getJson('/api/v2/site/products/'.$splitProduct->id)
            ->assertOk()
            ->assertJsonPath('data.product.cpu_display', '4 vCPU')
            ->assertJsonPath('data.product.memory_display', '4G')
            ->assertJsonPath('data.product.cpu_memory_display', '4 vCPU 4G')
            ->assertJsonPath('data.product.cpu_model_name', 'Intel Xeon Gold 6338')
            ->assertJsonPath('data.product.cpu_base_frequency', '2.00GHz')
            ->assertJsonPath('data.product.cpu_turbo_frequency', '3.20GHz');
    }

    public function test_split_is_idempotent_for_same_source_product(): void
    {
        $suffix = bin2hex(random_bytes(4));
        $role = Role::query()->create([
            'name' => 'product-split-idempotent-'.$suffix,
            'label' => 'Product Split Idempotent',
            'permissions' => [AdminPermissions::ALL],
        ]);
        $admin = AdminUser::query()->create([
            'username' => 'product-split-idempotent-'.$suffix,
            'password' => 'Temp@123456',
            'role_id' => (int) $role->id,
            'nickname' => 'Product Split Idempotent',
            'email' => 'product-split-idempotent-'.$suffix.'@example.com',
            'status' => 1,
        ]);
        $secondGroupId = $this->createSecondGroupId('idem-'.$suffix);
        $source = Product::query()->create([
            'product_group_id' => $secondGroupId,
            'name' => '美国 2h2g idem '.$suffix,
            'product_type' => 'vps',
            'pricing' => ['monthly' => '50.00'],
            'purchase_requires' => [],
            'config_options' => [
                [
                    'id' => 62001,
                    'field' => 'memory',
                    'name' => '内存',
                    'option_type' => 8,
                    'sub' => [
                        ['id' => 72001, 'option_name_first' => '2', 'option_name' => '2G'],
                        ['id' => 72002, 'option_name_first' => '4', 'option_name' => '4G'],
                    ],
                ],
            ],
            'stock' => -1,
            'status' => 1,
            'sort_order' => 0,
            'auto_setup' => 0,
        ]);

        Sanctum::actingAs($admin);

        $this->postJson('/api/v2/admin/products/splits', ['product_ids' => [(int) $source->id]])->assertOk();
        $this->postJson('/api/v2/admin/products/splits', ['product_ids' => [(int) $source->id]])
            ->assertOk()
            ->assertJsonPath('data.created_count', 0)
            ->assertJsonPath('data.updated_count', 0)
            ->assertJsonPath('data.skipped_count', 1);

        $this->assertSame(
            2,
            Product::query()
                ->where('product_group_id', $secondGroupId)
                ->get()
                ->filter(fn (Product $product) => (int) (($product->purchase_requires['upstream_split'] ?? [])['source_product_id'] ?? 0) === (int) $source->id)
                ->count()
        );
    }

    public function test_split_default_config_is_sent_to_upstream_cart_payload(): void
    {
        $suffix = bin2hex(random_bytes(4));
        $pluginId = $this->ensureHostingPanelIntegrationPlugin();
        $supplier = Supplier::query()->create([
            'name' => 'Cart Payload Supplier '.$suffix,
            'code' => 'cart-payload-'.$suffix,
            'interface_type' => ProviderKey::HOSTING_PANEL_API,
            'api_url' => 'https://example.com',
            'api_username' => 'tester',
            'api_key' => 'secret',
            'status' => 1,
            'sort_order' => 0,
        ]);
        $product = Product::query()->create([
            'name' => '美国 2H4G',
            'product_type' => 'vps',
            'pricing' => ['monthly' => '50.00'],
            'setup_fee' => '0.00',
            'supplier_product_id' => 9001,
            'supplier_id' => (int) $supplier->id,
            'provision_module' => ProviderKey::HOSTING_PANEL_API,
            'auto_setup' => 0,
            'purchase_requires' => [
                'upstream_default_config' => [
                    'memory' => '4',
                ],
            ],
            'config_options' => [
                [
                    'id' => 61001,
                    'config_id' => 61001,
                    'field' => 'memory',
                    'option_type' => 8,
                    'hidden' => 1,
                    'sub' => [
                        ['id' => 71001, 'option_name_first' => '2', 'option_name' => '2G'],
                        ['id' => 71002, 'option_name_first' => '4', 'option_name' => '4G'],
                    ],
                ],
            ],
            'stock' => -1,
            'status' => 1,
            'sort_order' => 0,
        ]);
        $this->createProductUpstreamBinding($supplier, $product, $pluginId, 9001);

        $order = new class extends Order
        {
            public function save(array $options = []): bool
            {
                $this->syncOriginal();

                return true;
            }
        };
        $order->forceFill([
            'id' => 9901,
            'order_no' => 'ORD-SPLIT-DEFAULT-CONFIG',
            'billing_cycle' => 'monthly',
            'config_snapshot' => [
                'hostname' => 'split-host',
                'password' => 'TestPass123',
            ],
        ]);
        $order->setRelation('product', $product);

        $service = new ProvisionService(
            new ProviderResolver(new ProviderRegistry([])),
            new class extends SettingService
            {
                public function getProvisionHostnameConfig(): array
                {
                    return [
                        'enforce' => false,
                        'prefix' => 'srv',
                        'length' => 12,
                        'pool' => '0123456789',
                    ];
                }
            }
        );

        $method = new \ReflectionMethod($service, 'buildUpstreamCartPayload');
        $method->setAccessible(true);
        $payload = (array) $method->invoke($service, $order);

        $this->assertSame(9001, $payload['product_id'] ?? null);
        $this->assertSame(71002, ($payload['configoption'] ?? [])[61001] ?? null);
    }

    public function test_memory_values_in_mb_are_displayed_as_gb_in_split_product_names(): void
    {
        $suffix = bin2hex(random_bytes(4));
        $role = Role::query()->create([
            'name' => 'product-split-mb-memory-'.$suffix,
            'label' => 'Product Split MB Memory',
            'permissions' => [AdminPermissions::ALL],
        ]);
        $admin = AdminUser::query()->create([
            'username' => 'product-split-mb-memory-'.$suffix,
            'password' => 'Temp@123456',
            'role_id' => (int) $role->id,
            'nickname' => 'Product Split MB Memory',
            'email' => 'product-split-mb-memory-'.$suffix.'@example.com',
            'status' => 1,
        ]);
        $secondGroupId = $this->createSecondGroupId('mb-'.$suffix);
        $source = Product::query()->create([
            'product_group_id' => $secondGroupId,
            'name' => '襄阳高防大带宽 2H2G '.$suffix,
            'product_type' => 'vps',
            'pricing' => ['monthly' => '50.00'],
            'purchase_requires' => [],
            'config_options' => [
                [
                    'id' => 63001,
                    'field' => 'memory',
                    'name' => '内存',
                    'option_type' => 8,
                    'sub' => [
                        ['id' => 73001, 'option_name_first' => '1024', 'option_name' => '1024'],
                        ['id' => 73002, 'option_name_first' => '3072', 'option_name' => '3072'],
                        ['id' => 73003, 'option_name_first' => '5120', 'option_name' => '5120'],
                    ],
                ],
            ],
            'stock' => -1,
            'status' => 1,
            'sort_order' => 0,
            'auto_setup' => 0,
        ]);

        Sanctum::actingAs($admin);

        $this->postJson('/api/v2/admin/products/split-previews', [
            'product_ids' => [(int) $source->id],
        ])
            ->assertOk()
            ->assertJsonPath('data.preview_count', 3)
            ->assertJsonPath('data.items.0.variants.0.display_name', '1G')
            ->assertJsonPath('data.items.0.variants.1.display_name', '3G')
            ->assertJsonPath('data.items.0.variants.2.display_name', '5G')
            ->assertJsonPath('data.items.0.variants.0.memory', '1024');
    }

    public function test_split_keeps_source_product_as_base_variant_when_name_has_no_spec_pattern(): void
    {
        $suffix = bin2hex(random_bytes(4));
        $role = Role::query()->create([
            'name' => 'product-split-generic-name-'.$suffix,
            'label' => 'Product Split Generic Name',
            'permissions' => [AdminPermissions::ALL],
        ]);
        $admin = AdminUser::query()->create([
            'username' => 'product-split-generic-name-'.$suffix,
            'password' => 'Temp@123456',
            'role_id' => (int) $role->id,
            'nickname' => 'Product Split Generic Name',
            'email' => 'product-split-generic-name-'.$suffix.'@example.com',
            'status' => 1,
        ]);
        $secondGroupId = $this->createSecondGroupId('generic-'.$suffix);
        $source = Product::query()->create([
            'product_group_id' => $secondGroupId,
            'name' => '旗舰云主机 '.$suffix,
            'product_type' => 'vps',
            'pricing' => ['monthly' => '50.00'],
            'purchase_requires' => [],
            'config_options' => [
                [
                    'id' => 64001,
                    'field' => 'memory',
                    'name' => '内存',
                    'option_type' => 8,
                    'sub' => [
                        ['id' => 74001, 'option_name_first' => '2', 'option_name' => '2G'],
                        ['id' => 74002, 'option_name_first' => '4', 'option_name' => '4G'],
                    ],
                ],
            ],
            'stock' => -1,
            'status' => 1,
            'sort_order' => 0,
            'auto_setup' => 0,
        ]);

        Sanctum::actingAs($admin);

        $this->postJson('/api/v2/admin/products/split-previews', [
            'product_ids' => [(int) $source->id],
        ])
            ->assertOk()
            ->assertJsonPath('data.preview_count', 2)
            ->assertJsonPath('data.items.0.variants.0.product_id', (int) $source->id)
            ->assertJsonPath('data.items.0.variants.0.action', 'update')
            ->assertJsonPath('data.items.0.variants.1.action', 'create');

        $this->postJson('/api/v2/admin/products/splits', [
            'product_ids' => [(int) $source->id],
        ])
            ->assertOk()
            ->assertJsonPath('data.created_count', 1)
            ->assertJsonPath('data.updated_count', 1)
            ->assertJsonPath('data.skipped_count', 0);

        $baseVariant = Product::query()->findOrFail((int) $source->id);

        $this->assertSame(['memory' => '2'], $baseVariant->purchase_requires['upstream_default_config'] ?? []);
        $this->assertSame(['memory'], collect($baseVariant->config_options)->pluck('field')->values()->all());
        $this->assertSame(1, count((array) ($baseVariant->config_options[0]['sub'] ?? [])));
        $this->assertSame('2', (string) (($baseVariant->config_options[0]['sub'][0]['option_name_first'] ?? '')));
        $this->assertSame(1, (int) (($baseVariant->config_options[0]['hidden'] ?? 0)));
        $this->assertSame(
            2,
            Product::query()->where('product_group_id', $secondGroupId)->count()
        );
    }

    public function test_option_type_8_flow_limit_is_not_treated_as_memory_for_split(): void
    {
        $suffix = bin2hex(random_bytes(4));
        $role = Role::query()->create([
            'name' => 'product-split-flow-limit-'.$suffix,
            'label' => 'Product Split Flow Limit',
            'permissions' => [AdminPermissions::ALL],
        ]);
        $admin = AdminUser::query()->create([
            'username' => 'product-split-flow-limit-'.$suffix,
            'password' => 'Temp@123456',
            'role_id' => (int) $role->id,
            'nickname' => 'Product Split Flow Limit',
            'email' => 'product-split-flow-limit-'.$suffix.'@example.com',
            'status' => 1,
        ]);
        $secondGroupId = $this->createSecondGroupId('flow-'.$suffix);
        $source = Product::query()->create([
            'product_group_id' => $secondGroupId,
            'name' => 'Xiangyang high bandwidth 2H2G '.$suffix,
            'product_type' => 'vps',
            'pricing' => ['monthly' => '50.00'],
            'purchase_requires' => [
                'upstream_default_config' => [
                    'cpu' => '2',
                    'memory' => '2048',
                ],
            ],
            'config_options' => [
                [
                    'id' => 63001,
                    'field' => 'memory',
                    'name' => 'memory',
                    'option_name' => 'memory|memory',
                    'option_type' => 8,
                    'sub' => [
                        ['id' => 73001, 'option_name_first' => '2048', 'option_name' => '2G'],
                        ['id' => 73002, 'option_name_first' => '4096', 'option_name' => '4G'],
                    ],
                ],
                [
                    'id' => 63002,
                    'field' => 'flow_limit',
                    'name' => 'flow',
                    'option_name' => 'flow_limit|traffic',
                    'option_type' => 8,
                    'sub' => [
                        ['id' => 73003, 'option_name_first' => '1024', 'option_name' => '1024GB'],
                        ['id' => 73004, 'option_name_first' => '3072', 'option_name' => '3072GB'],
                        ['id' => 73005, 'option_name_first' => '5120', 'option_name' => '5120GB'],
                    ],
                ],
            ],
            'stock' => -1,
            'status' => 1,
            'sort_order' => 0,
            'auto_setup' => 0,
        ]);

        Sanctum::actingAs($admin);

        $previewResponse = $this->postJson('/api/v2/admin/products/split-previews', [
            'product_ids' => [(int) $source->id],
        ]);
        $previewResponse
            ->assertOk()
            ->assertJsonPath('data.preview_count', 2)
            ->assertJsonPath('data.items.0.variants.0.display_name', '2 vCPU 2G')
            ->assertJsonPath('data.items.0.variants.1.display_name', '2 vCPU 4G')
            ->assertJsonPath('data.items.0.variants.0.memory', '2048')
            ->assertJsonPath('data.items.0.variants.1.memory', '4096');

        $this->postJson('/api/v2/admin/products/splits', [
            'product_ids' => [(int) $source->id],
        ])->assertOk();

        $splitProduct = Product::query()
            ->get()
            ->first(fn (Product $product) => (int) (($product->purchase_requires['upstream_split'] ?? [])['source_product_id'] ?? 0) === (int) $source->id
                && (string) (($product->purchase_requires['upstream_split'] ?? [])['variant_key'] ?? '') === 'memory=4096');

        $this->assertInstanceOf(Product::class, $splitProduct);

        $this->assertSame(['memory' => '4096'], $splitProduct->purchase_requires['upstream_default_config'] ?? []);
        $this->assertSame(['memory', 'flow_limit'], collect($splitProduct->config_options)->pluck('field')->values()->all());
        $this->assertSame(1, count((array) ($splitProduct->config_options[0]['sub'] ?? [])));
        $this->assertSame('4096', (string) (($splitProduct->config_options[0]['sub'][0]['option_name_first'] ?? '')));
    }

    private function ensureHostingPanelIntegrationPlugin(): int
    {
        DB::table('integration_plugins')->updateOrInsert([
            'domain' => 'upstream',
            'plugin_key' => ProviderKey::HOSTING_PANEL_API,
        ], [
            'slug' => ProviderKey::HOSTING_PANEL_API,
            'name' => 'Hosting Panel API',
            'version' => '1.0.0',
            'provider_class' => null,
            'entry_class' => HostingPanelApiDriver::class,
            'capabilities_json' => json_encode([]),
            'config_schema_json' => json_encode([]),
            'status' => 1,
            'installed_at' => now(),
            'updated_at' => now(),
            'created_at' => now(),
        ]);

        return (int) DB::table('integration_plugins')
            ->where('domain', 'upstream')
            ->where('plugin_key', ProviderKey::HOSTING_PANEL_API)
            ->value('id');
    }

    private function createProductUpstreamBinding(
        Supplier $supplier,
        Product $product,
        int $pluginId,
        int $upstreamProductId
    ): void {
        $supplierBindingId = DB::table('supplier_plugin_bindings')->insertGetId([
            'supplier_id' => (int) $supplier->id,
            'plugin_id' => $pluginId,
            'provider_key' => ProviderKey::HOSTING_PANEL_API,
            'environment' => 'production',
            'status' => 1,
            'priority' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('product_upstream_bindings')->insert([
            'product_id' => (int) $product->id,
            'supplier_plugin_binding_id' => $supplierBindingId,
            'plugin_id' => $pluginId,
            'provider_key' => ProviderKey::HOSTING_PANEL_API,
            'upstream_product_id' => (string) $upstreamProductId,
            'auto_setup' => 0,
            'status' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /**
     * 创建一个可见的二级商品分组（隶属于可见的 vps 一级分组），返回其 ID。
     * 创建一条三级叶分组，并返回其 ID，供 products.product_group_id 使用。
     */
    private function createSecondGroupId(string $slugSeed): int
    {
        $first = FirstProductGroup::query()->firstOrCreate(
            ['code' => 'vps'],
            [
                'name' => 'VPS',
                'slug' => 'split-first-vps',
                'sort_order' => 0,
                'is_visible' => 1,
                'is_system' => 0,
                'product_type' => 'cloud_server',
            ]
        );

        if ((int) $first->is_visible !== 1) {
            $first->update(['is_visible' => 1]);
        }

        $second = SecondProductGroup::query()->create([
            'first_product_group_id' => (int) $first->id,
            'name' => 'Split second '.$slugSeed,
            'slug' => 'split-second-'.$slugSeed,
            'description' => '',
            'sort_order' => 0,
            'is_visible' => 1,
        ]);

        return (int) ThirdProductGroup::query()->create([
            'second_product_group_id' => (int) $second->id,
            'name' => 'Split third '.$slugSeed,
            'slug' => 'split-third-'.$slugSeed,
            'description' => '',
            'sort_order' => 0,
            'is_visible' => 1,
        ])->id;
    }
}
