<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\AdminUser;
use App\Models\Role;
use App\Models\Setting;
use App\Support\AdminPermissions;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class V2AdminSpecCatalogApiTest extends TestCase
{
    private bool $hadInstanceSpecCatalog = false;

    private bool $hadCpuModelCatalog = false;

    private ?string $originalInstanceSpecCatalog = null;

    private ?string $originalCpuModelCatalog = null;

    protected function setUp(): void
    {
        parent::setUp();

        $this->originalInstanceSpecCatalog = $this->storedSetting('instance_spec_catalog');
        $this->originalCpuModelCatalog = $this->storedSetting('cpu_model_catalog');
        $this->hadInstanceSpecCatalog = $this->originalInstanceSpecCatalog !== null;
        $this->hadCpuModelCatalog = $this->originalCpuModelCatalog !== null;
    }

    protected function tearDown(): void
    {
        $this->restoreSetting('instance_spec_catalog', $this->hadInstanceSpecCatalog, $this->originalInstanceSpecCatalog);
        $this->restoreSetting('cpu_model_catalog', $this->hadCpuModelCatalog, $this->originalCpuModelCatalog);
        Cache::forget('settings:group:product');

        parent::tearDown();
    }

    public function test_instance_spec_catalog_uses_v2_contract_and_projection(): void
    {
        Setting::setValue('product', 'instance_spec_catalog', json_encode([
            [
                'id' => 'spec_v2_2c2g',
                'value' => 'v2_2c2g',
                'text' => 'V2 2C2G',
                'alias' => '2C2G',
                'note' => 'visible',
                'status' => '展示中',
                'password' => 'must-not-leak',
                'bindings' => [
                    [
                        'product_id' => 101,
                        'category_full_name' => 'V2 Catalog',
                        'primary_price' => ['cycle' => 'monthly', 'amount' => '29.00'],
                        'status' => 1,
                        'secret' => 'must-not-leak',
                    ],
                ],
            ],
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

        $this->getJson('/api/v2/admin/instance-spec-catalog')
            ->assertUnauthorized()
            ->assertJsonPath('code', 40100);

        Sanctum::actingAs($this->createAdmin([]));

        $this->getJson('/api/v2/admin/instance-spec-catalog')
            ->assertForbidden()
            ->assertJsonPath('code', 40300);

        Sanctum::actingAs($this->createAdmin([AdminPermissions::PRODUCT_LIST]));

        $this->getJson('/api/v2/admin/instance-spec-catalog?per_page=20&pageSize=20')
            ->assertUnprocessable()
            ->assertJsonPath('code', 42200)
            ->assertJsonStructure(['data' => ['errors' => ['per_page', 'pageSize']]]);

        $response = $this->getJson('/api/v2/admin/instance-spec-catalog?keyword=V2')
            ->assertOk()
            ->assertJsonPath('code', 0)
            ->assertJsonPath('data.total', 1)
            ->assertJsonPath('data.page', 1)
            ->assertJsonPath('data.page_size', 1)
            ->assertJsonPath('data.list.0.text', 'V2 2C2G')
            ->assertJsonMissingPath('data.list.0.password')
            ->assertJsonMissingPath('data.list.0.bindings.0.secret');

        $this->assertSame(['list', 'total', 'page', 'page_size'], array_keys($response->json('data')));
        $this->assertSame($this->instanceSpecWhitelist(), array_keys($response->json('data.list.0')));
        $this->assertSame($this->bindingWhitelist(), array_keys($response->json('data.list.0.bindings.0')));
        $this->assertNoSensitiveKeys($response->json());
        $this->assertLessThan(100 * 1024, strlen((string) $response->getContent()));

        $this->postJson('/api/v2/admin/instance-spec-catalog', ['list' => []])
            ->assertForbidden()
            ->assertJsonPath('code', 40300);

        Sanctum::actingAs($this->createAdmin([AdminPermissions::PRODUCT_MANAGE]));

        $this->postJson('/api/v2/admin/instance-spec-catalog?pageSize=20', ['list' => []])
            ->assertUnprocessable()
            ->assertJsonStructure(['data' => ['errors' => ['pageSize']]]);

        $saveResponse = $this->postJson('/api/v2/admin/instance-spec-catalog', [
            'list' => [
                [
                    'id' => 'spec_v2_4c8g',
                    'value' => 'v2_4c8g',
                    'text' => 'V2 4C8G',
                    'alias' => '4C8G',
                    'note' => 'save',
                    'status' => '展示中',
                    'bindings' => [
                        [
                            'product_id' => 102,
                            'category_full_name' => 'V2 Save',
                            'primary_price' => ['cycle' => 'monthly', 'amount' => '59'],
                            'status' => 1,
                        ],
                    ],
                ],
            ],
        ])
            ->assertOk()
            ->assertJsonPath('code', 0)
            ->assertJsonPath('data.list.0.text', 'V2 4C8G')
            ->assertJsonPath('data.list.0.bindings.0.primary_price.amount', '59.00');

        $this->assertSame($this->instanceSpecWhitelist(), array_keys($saveResponse->json('data.list.0')));
        $this->assertNoSensitiveKeys($saveResponse->json());
        $this->assertLessThan(100 * 1024, strlen((string) $saveResponse->getContent()));
    }

    public function test_cpu_model_catalog_uses_v2_contract_and_projection(): void
    {
        Setting::setValue('product', 'cpu_model_catalog', json_encode([
            [
                'id' => 'group_v2_intel',
                'value' => 'v2_intel',
                'name' => 'V2 Intel',
                'secret' => 'must-not-leak',
                'models' => [
                    [
                        'id' => 'model_v2_6133',
                        'value' => 'v2_6133',
                        'name' => 'V2 Xeon 6133',
                        'base_frequency' => '2.50GHz',
                        'turbo_frequency' => '3.20GHz',
                        'bindings' => [
                            [
                                'product_id' => 103,
                                'category_full_name' => 'V2 CPU',
                                'primary_price' => ['cycle' => 'monthly', 'amount' => '39.00'],
                                'status' => 1,
                                'raw_response' => 'must-not-leak',
                            ],
                        ],
                    ],
                ],
            ],
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

        Sanctum::actingAs($this->createAdmin([AdminPermissions::PRODUCT_LIST]));

        $this->getJson('/api/v2/admin/cpu-model-catalog?per_page=20&pageSize=20')
            ->assertUnprocessable()
            ->assertJsonPath('code', 42200)
            ->assertJsonStructure(['data' => ['errors' => ['per_page', 'pageSize']]]);

        $response = $this->getJson('/api/v2/admin/cpu-model-catalog')
            ->assertOk()
            ->assertJsonPath('code', 0)
            ->assertJsonPath('data.total', 1)
            ->assertJsonPath('data.page_size', 1)
            ->assertJsonPath('data.list.0.name', 'V2 Intel')
            ->assertJsonPath('data.list.0.models.0.name', 'V2 Xeon 6133')
            ->assertJsonMissingPath('data.list.0.secret')
            ->assertJsonMissingPath('data.list.0.models.0.bindings.0.raw_response');

        $this->assertSame(['list', 'total', 'page', 'page_size'], array_keys($response->json('data')));
        $this->assertSame($this->cpuGroupWhitelist(), array_keys($response->json('data.list.0')));
        $this->assertSame($this->cpuModelWhitelist(), array_keys($response->json('data.list.0.models.0')));
        $this->assertSame($this->bindingWhitelist(), array_keys($response->json('data.list.0.models.0.bindings.0')));
        $this->assertNoSensitiveKeys($response->json());
        $this->assertLessThan(100 * 1024, strlen((string) $response->getContent()));

        $this->postJson('/api/v2/admin/cpu-model-catalog', ['list' => []])
            ->assertForbidden()
            ->assertJsonPath('code', 40300);

        Sanctum::actingAs($this->createAdmin([AdminPermissions::PRODUCT_MANAGE]));

        $saveResponse = $this->postJson('/api/v2/admin/cpu-model-catalog', [
            'list' => [
                [
                    'id' => 'group_v2_amd',
                    'value' => 'v2_amd',
                    'name' => 'V2 AMD',
                    'models' => [
                        [
                            'id' => 'model_v2_7k62',
                            'value' => 'v2_7k62',
                            'name' => 'V2 EPYC 7K62',
                            'base_frequency' => '2.60GHz',
                            'turbo_frequency' => '3.30GHz',
                            'bindings' => [
                                [
                                    'product_id' => 104,
                                    'category_full_name' => 'V2 CPU Save',
                                    'primary_price' => ['cycle' => 'monthly', 'amount' => '69'],
                                    'status' => 0,
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ])
            ->assertOk()
            ->assertJsonPath('code', 0)
            ->assertJsonPath('data.list.0.name', 'V2 AMD')
            ->assertJsonPath('data.list.0.models.0.name', 'V2 EPYC 7K62')
            ->assertJsonPath('data.list.0.models.0.bindings.0.primary_price.amount', '69.00');

        $this->assertSame($this->cpuGroupWhitelist(), array_keys($saveResponse->json('data.list.0')));
        $this->assertNoSensitiveKeys($saveResponse->json());
        $this->assertLessThan(100 * 1024, strlen((string) $saveResponse->getContent()));
    }

    private function storedSetting(string $key): ?string
    {
        $value = DB::table('settings')
            ->where('group_key', 'product')
            ->where('item_key', $key)
            ->value('item_value');

        return $value === null ? null : (string) $value;
    }

    private function restoreSetting(string $key, bool $hadValue, ?string $value): void
    {
        if ($hadValue) {
            DB::table('settings')->updateOrInsert(
                ['group_key' => 'product', 'item_key' => $key],
                ['item_value' => $value ?? '[]']
            );

            return;
        }

        DB::table('settings')
            ->where('group_key', 'product')
            ->where('item_key', $key)
            ->delete();
    }

    /**
     * @param  list<string>  $permissions
     */
    private function createAdmin(array $permissions): AdminUser
    {
        $suffix = bin2hex(random_bytes(4));
        $role = Role::query()->create([
            'name' => 'v2-spec-catalog-'.$suffix,
            'label' => 'V2 Spec Catalog',
            'permissions' => $permissions,
        ]);

        return AdminUser::query()->create([
            'username' => 'v2-spec-catalog-'.$suffix,
            'password' => 'Temp@123456',
            'role_id' => (int) $role->id,
            'nickname' => 'V2 Spec Catalog',
            'email' => 'v2-spec-catalog-'.$suffix.'@example.com',
            'status' => 1,
        ]);
    }

    /**
     * @return list<string>
     */
    private function instanceSpecWhitelist(): array
    {
        return [
            'id',
            'value',
            'text',
            'alias',
            'note',
            'status',
            'sort_order',
            'bindings',
        ];
    }

    /**
     * @return list<string>
     */
    private function cpuGroupWhitelist(): array
    {
        return [
            'id',
            'value',
            'name',
            'sort_order',
            'model_count',
            'models',
        ];
    }

    /**
     * @return list<string>
     */
    private function cpuModelWhitelist(): array
    {
        return [
            'id',
            'value',
            'name',
            'base_frequency',
            'turbo_frequency',
            'sort_order',
            'bindings',
        ];
    }

    /**
     * @return list<string>
     */
    private function bindingWhitelist(): array
    {
        return [
            'product_id',
            'display_name',
            'custom_display_name',
            'cpu_memory_display',
            'product_spec_display',
            'combined_display_name',
            'category_full_name',
            'primary_price',
            'status',
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
