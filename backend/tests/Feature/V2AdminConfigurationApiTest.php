<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Http\Controllers\Admin\V2\IntegrationPluginController as V2IntegrationPluginController;
use App\Models\AdminUser;
use App\Models\IntegrationPlugin;
use App\Models\Role;
use App\Models\Setting;
use App\Models\Supplier;
use App\Services\Admin\V2\AdminConfigurationV2QueryService;
use App\Services\Integrations\Plugins\IntegrationPluginService;
use App\Services\Integrations\Plugins\PluginConfigRepository;
use App\Services\Integrations\Plugins\PluginFileLoader;
use App\Services\Integrations\Plugins\PluginRuntimeRegistry;
use App\Services\Integrations\Plugins\PluginScanner;
use App\Services\System\OperationLogService;
use App\Services\System\SettingService;
use App\Support\AdminPermissions;
use Illuminate\Contracts\Container\Container;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Mockery\MockInterface;
use PHPUnit\Framework\Assert;
use Tests\TestCase;

class V2AdminConfigurationApiTest extends TestCase
{
    public function test_admin_integration_plugins_are_paginated_and_schema_is_split_from_list_detail(): void
    {
        $plugin = $this->createConfiguredPlugin();

        $this->getJson('/api/v2/admin/integration-plugins')
            ->assertUnauthorized()
            ->assertJsonPath('code', 40100);

        Sanctum::actingAs($this->createAdmin([]));

        $this->getJson('/api/v2/admin/integration-plugins')
            ->assertForbidden()
            ->assertJsonPath('code', 40300);

        Sanctum::actingAs($this->createAdmin([AdminPermissions::INTEGRATION_PLUGIN_VIEW]));

        $this->getJson('/api/v2/admin/integration-plugins?per_page=50')
            ->assertUnprocessable()
            ->assertJsonPath('code', 42200)
            ->assertJsonStructure(['data' => ['errors' => ['per_page']]]);

        $listResponse = $this->getJson('/api/v2/admin/integration-plugins?'.http_build_query([
            'domain' => 'captcha',
            'page' => 1,
            'page_size' => 50,
        ]))
            ->assertOk()
            ->assertJsonPath('code', 0)
            ->assertJsonFragment(['slug' => 'geetest'])
            ->assertJsonMissingPath('data.list.0.config_schema')
            ->assertJsonMissingPath('data.list.0.base_path')
            ->assertJsonMissingPath('data.list.0.latest_runtime_log.error_message');

        $this->assertSame($this->pluginPageWhitelist(), array_keys($listResponse->json('data')));
        $this->assertSame($this->pluginSummaryWhitelist(), array_keys($listResponse->json('data.list.0')));
        $this->assertLessThan(100 * 1024, strlen((string) $listResponse->getContent()));

        $detailResponse = $this->getJson('/api/v2/admin/integration-plugins/'.$plugin->id)
            ->assertOk()
            ->assertJsonPath('code', 0)
            ->assertJsonPath('data.plugin.id', $plugin->id)
            ->assertJsonPath('data.plugin.config.captcha_id', 'v2-captcha-id')
            ->assertJsonPath('data.plugin.configured_credentials.captcha_key', true)
            ->assertJsonMissingPath('data.plugin.config.captcha_key')
            ->assertJsonMissingPath('data.plugin.config_schema')
            ->assertJsonMissingPath('data.plugin.base_path')
            ->assertJsonMissingPath('data.plugin.has_secret_values')
            ->assertJsonMissingPath('data.plugin.secret_previews');

        $this->assertSame($this->pluginDetailWhitelist(), array_keys($detailResponse->json('data.plugin')));
        $this->assertLessThan(100 * 1024, strlen((string) $detailResponse->getContent()));

        $schemaResponse = $this->getJson('/api/v2/admin/integration-plugins/'.$plugin->id.'/schema')
            ->assertOk()
            ->assertJsonPath('code', 0)
            ->assertJsonPath('data.plugin_id', $plugin->id)
            ->assertJsonFragment(['key' => 'captcha_key'])
            ->assertJsonFragment(['sensitive' => true])
            ->assertJsonMissingPath('data.schema.0.secret');

        $this->assertSame($this->pluginSchemaPageWhitelist(), array_keys($schemaResponse->json('data')));
        $this->assertLessThan(100 * 1024, strlen((string) $schemaResponse->getContent()));
    }

    public function test_admin_plugin_secret_reveal_requires_dedicated_permission(): void
    {
        $plugin = $this->createConfiguredPlugin();

        Sanctum::actingAs($this->createAdmin([AdminPermissions::INTEGRATION_PLUGIN_VIEW]));

        $this->getJson('/api/v2/admin/integration-plugins/'.$plugin->id.'/secrets/captcha_key')
            ->assertForbidden()
            ->assertJsonPath('code', 40300);

        Sanctum::actingAs($this->createAdmin([AdminPermissions::INTEGRATION_PLUGIN_SECRET_REVEAL]));

        $this->getJson('/api/v2/admin/integration-plugins/'.$plugin->id.'/secrets/captcha_key')
            ->assertOk()
            ->assertJsonPath('code', 0)
            ->assertJsonPath('data.key', 'captcha_key')
            ->assertJsonPath('data.value', 'v2-captcha-secret');
    }

    public function test_admin_plugin_scan_action_uses_small_task_result_and_manage_permission(): void
    {
        $this->postJson('/api/v2/admin/integration-plugin-scans', ['domain' => 'captcha'])
            ->assertUnauthorized()
            ->assertJsonPath('code', 40100);

        Sanctum::actingAs($this->createAdmin([AdminPermissions::INTEGRATION_PLUGIN_VIEW]));

        $this->postJson('/api/v2/admin/integration-plugin-scans', ['domain' => 'captcha'])
            ->assertForbidden()
            ->assertJsonPath('code', 40300);

        Sanctum::actingAs($this->createAdmin([AdminPermissions::INTEGRATION_PLUGIN_MANAGE]));

        $this->postJson('/api/v2/admin/integration-plugin-scans?per_page=20', ['domain' => 'captcha'])
            ->assertUnprocessable()
            ->assertJsonPath('code', 42200)
            ->assertJsonStructure(['data' => ['errors' => ['per_page']]]);

        $response = $this->postJson('/api/v2/admin/integration-plugin-scans', ['domain' => 'captcha'])
            ->assertOk()
            ->assertJsonPath('code', 0)
            ->assertJsonPath('data.id', 'integration-plugin-scan')
            ->assertJsonPath('data.status', 'completed')
            ->assertJsonPath('data.detail.domain', 'captcha')
            ->assertJsonMissingPath('data.detail.list')
            ->assertJsonMissingPath('data.detail.base_path');

        $this->assertSame($this->actionResultWhitelist(), array_keys($response->json('data')));
        $this->assertNoSensitiveKeys($response->json());
        $this->assertLessThan(100 * 1024, strlen((string) $response->getContent()));
    }

    public function test_admin_plugin_write_actions_use_v2_detail_and_action_contracts(): void
    {
        Sanctum::actingAs($this->createAdmin([AdminPermissions::INTEGRATION_PLUGIN_VIEW]));

        $this->postJson('/api/v2/admin/integration-plugins', [
            'domain' => 'captcha',
            'slug' => 'geetest',
        ])
            ->assertForbidden()
            ->assertJsonPath('code', 40300);

        Sanctum::actingAs($this->createAdmin([AdminPermissions::INTEGRATION_PLUGIN_MANAGE]));

        $this->postJson('/api/v2/admin/integration-plugins?per_page=20', [
            'domain' => 'captcha',
            'slug' => 'geetest',
        ])
            ->assertUnprocessable()
            ->assertJsonPath('code', 42200)
            ->assertJsonStructure(['data' => ['errors' => ['per_page']]]);

        $installResponse = $this->postJson('/api/v2/admin/integration-plugins', [
            'domain' => 'captcha',
            'slug' => 'geetest',
        ])
            ->assertOk()
            ->assertJsonPath('code', 0)
            ->assertJsonPath('data.plugin.domain', 'captcha')
            ->assertJsonPath('data.plugin.slug', 'geetest')
            ->assertJsonMissingPath('data.plugin.config_schema')
            ->assertJsonMissingPath('data.plugin.base_path')
            ->assertJsonMissingPath('data.plugin.has_secret_values')
            ->assertJsonMissingPath('data.plugin.secret_previews');

        $pluginId = (int) $installResponse->json('data.plugin.id');
        $this->assertGreaterThan(0, $pluginId);
        $this->assertSame($this->pluginDetailWhitelist(), array_keys($installResponse->json('data.plugin')));
        $this->assertNoSensitiveKeys($installResponse->json());
        $this->assertLessThan(100 * 1024, strlen((string) $installResponse->getContent()));

        $this->putJson('/api/v2/admin/integration-plugins/'.$pluginId.'/config?pageSize=20', [
            'config' => [
                'captcha_id' => 'v2-updated-captcha-id',
                'captcha_key' => 'v2-updated-captcha-secret',
            ],
        ])
            ->assertUnprocessable()
            ->assertJsonPath('code', 42200)
            ->assertJsonStructure(['data' => ['errors' => ['pageSize']]]);

        $updateResponse = $this->putJson('/api/v2/admin/integration-plugins/'.$pluginId.'/config', [
            'config' => [
                'captcha_id' => 'v2-updated-captcha-id',
                'captcha_key' => 'v2-updated-captcha-secret',
            ],
        ])
            ->assertOk()
            ->assertJsonPath('code', 0)
            ->assertJsonPath('data.plugin.id', $pluginId)
            ->assertJsonPath('data.plugin.config.captcha_id', 'v2-updated-captcha-id')
            ->assertJsonPath('data.plugin.configured_credentials.captcha_key', true)
            ->assertJsonMissingPath('data.plugin.config.captcha_key')
            ->assertJsonMissingPath('data.plugin.config_schema')
            ->assertJsonMissingPath('data.plugin.base_path')
            ->assertJsonMissingPath('data.plugin.has_secret_values')
            ->assertJsonMissingPath('data.plugin.secret_previews');

        $this->assertSame($this->pluginDetailWhitelist(), array_keys($updateResponse->json('data.plugin')));
        $this->assertNoSensitiveKeys($updateResponse->json());
        $this->assertLessThan(100 * 1024, strlen((string) $updateResponse->getContent()));

        $this->deleteJson('/api/v2/admin/integration-plugins/'.$pluginId.'?per_page=20')
            ->assertUnprocessable()
            ->assertJsonPath('code', 42200)
            ->assertJsonStructure(['data' => ['errors' => ['per_page']]]);

        $deleteResponse = $this->deleteJson('/api/v2/admin/integration-plugins/'.$pluginId)
            ->assertOk()
            ->assertJsonPath('code', 0)
            ->assertJsonPath('data.id', $pluginId)
            ->assertJsonPath('data.status', 'deleted')
            ->assertJsonPath('data.detail.deleted', true)
            ->assertJsonMissingPath('data.detail.plugin')
            ->assertJsonMissingPath('data.detail.config');

        $this->assertSame($this->actionResultWhitelist(), array_keys($deleteResponse->json('data')));
        $this->assertNoSensitiveKeys($deleteResponse->json());
        $this->assertLessThan(100 * 1024, strlen((string) $deleteResponse->getContent()));
        $this->assertDatabaseMissing('integration_plugins', ['id' => $pluginId]);
    }

    public function test_admin_plugin_status_action_updates_status_with_summary_projection(): void
    {
        $plugin = $this->createConfiguredPlugin();

        Sanctum::actingAs($this->createAdmin([AdminPermissions::INTEGRATION_PLUGIN_VIEW]));

        $this->patchJson('/api/v2/admin/integration-plugins/'.$plugin->id.'/status', ['enabled' => false])
            ->assertForbidden()
            ->assertJsonPath('code', 40300);

        Sanctum::actingAs($this->createAdmin([AdminPermissions::INTEGRATION_PLUGIN_MANAGE]));

        $this->patchJson('/api/v2/admin/integration-plugins/'.$plugin->id.'/status?per_page=20', ['enabled' => false])
            ->assertUnprocessable()
            ->assertJsonPath('code', 42200)
            ->assertJsonStructure(['data' => ['errors' => ['per_page']]]);

        $response = $this->patchJson('/api/v2/admin/integration-plugins/'.$plugin->id.'/status', ['enabled' => false])
            ->assertOk()
            ->assertJsonPath('code', 0)
            ->assertJsonPath('data.id', $plugin->id)
            ->assertJsonPath('data.status', 'disabled')
            ->assertJsonPath('data.detail.plugin.id', $plugin->id)
            ->assertJsonPath('data.detail.plugin.is_enabled', false)
            ->assertJsonMissingPath('data.detail.plugin.config_schema')
            ->assertJsonMissingPath('data.detail.plugin.config')
            ->assertJsonMissingPath('data.detail.plugin.base_path');

        $this->assertSame(IntegrationPlugin::STATUS_DISABLED, (int) $plugin->fresh()->status);
        $this->assertSame($this->actionResultWhitelist(), array_keys($response->json('data')));
        $this->assertSame($this->pluginSummaryWhitelist(), array_keys($response->json('data.detail.plugin')));
        $this->assertNoSensitiveKeys($response->json());
    }

    public function test_admin_plugin_task_action_validates_payload_and_compacts_runtime_result(): void
    {
        $plugin = $this->createConfiguredPlugin();

        $this->app->forgetInstance(IntegrationPluginService::class);
        $this->mock(IntegrationPluginService::class, function (MockInterface $mock) use ($plugin): void {
            $mock->shouldReceive('healthCheck')
                ->once()
                ->withArgs(fn (IntegrationPlugin $actual): bool => (int) $actual->id === (int) $plugin->id)
                ->andReturn([
                    'healthy' => true,
                    'message' => '插件加载正常',
                    'entry_class' => 'TuraIDC\\Plugins\\Captcha\\Geetest\\GeetestPlugin',
                    'details' => [
                        'raw_response' => 'must-not-leak',
                        'api_key' => 'must-not-leak',
                    ],
                    'secret' => 'must-not-leak',
                ]);
        });
        $this->app->forgetInstance(AdminConfigurationV2QueryService::class);
        $this->app->bind(
            AdminConfigurationV2QueryService::class,
            fn ($app): AdminConfigurationV2QueryService => new AdminConfigurationV2QueryService(
                $app->make(IntegrationPluginService::class),
                $app->make(SettingService::class)
            )
        );
        $this->app->forgetInstance(V2IntegrationPluginController::class);
        $this->app->bind(
            V2IntegrationPluginController::class,
            fn ($app): V2IntegrationPluginController => new V2IntegrationPluginController(
                $app->make(AdminConfigurationV2QueryService::class),
                $app->make(OperationLogService::class)
            )
        );

        Sanctum::actingAs($this->createAdmin([AdminPermissions::INTEGRATION_PLUGIN_VIEW]));

        $this->postJson('/api/v2/admin/integration-plugins/'.$plugin->id.'/tasks', ['type' => 'health_check'])
            ->assertForbidden()
            ->assertJsonPath('code', 40300);

        Sanctum::actingAs($this->createAdmin([AdminPermissions::INTEGRATION_PLUGIN_TEST]));

        $this->postJson('/api/v2/admin/integration-plugins/'.$plugin->id.'/tasks?per_page=20', ['type' => 'health_check'])
            ->assertUnprocessable()
            ->assertJsonPath('code', 42200)
            ->assertJsonStructure(['data' => ['errors' => ['per_page']]]);

        $this->postJson('/api/v2/admin/integration-plugins/'.$plugin->id.'/tasks', ['type' => 'test_email'])
            ->assertUnprocessable()
            ->assertJsonPath('code', 42200)
            ->assertJsonStructure(['data' => ['errors' => [
                'payload.account_index',
                'payload.to',
            ]]]);

        $response = $this->postJson('/api/v2/admin/integration-plugins/'.$plugin->id.'/tasks', [
            'type' => 'health_check',
        ])
            ->assertOk()
            ->assertJsonPath('code', 0)
            ->assertJsonPath('data.id', $plugin->id)
            ->assertJsonPath('data.status', 'completed')
            ->assertJsonPath('data.detail.type', 'health_check')
            ->assertJsonPath('data.detail.result.healthy', true)
            ->assertJsonMissingPath('data.detail.result.details')
            ->assertJsonMissingPath('data.detail.result.secret')
            ->assertJsonMissingPath('data.detail.result.raw_response');

        $this->assertSame($this->actionResultWhitelist(), array_keys($response->json('data')));
        $this->assertNoSensitiveKeys($response->json());
        $this->assertLessThan(100 * 1024, strlen((string) $response->getContent()));
    }

    public function test_admin_suppliers_are_projected_without_raw_error_or_credential_values(): void
    {
        $supplier = $this->createSupplierFixture();

        $this->getJson('/api/v2/admin/suppliers')
            ->assertUnauthorized()
            ->assertJsonPath('code', 40100);

        Sanctum::actingAs($this->createAdmin([]));

        $this->getJson('/api/v2/admin/suppliers')
            ->assertForbidden()
            ->assertJsonPath('code', 40300);

        Sanctum::actingAs($this->createAdmin([AdminPermissions::SUPPLIER_LIST, AdminPermissions::SUPPLIER_DETAIL]));

        $this->getJson('/api/v2/admin/suppliers?per_page=20')
            ->assertUnprocessable()
            ->assertJsonPath('code', 42200)
            ->assertJsonStructure(['data' => ['errors' => ['per_page']]]);

        $listResponse = $this->getJson('/api/v2/admin/suppliers?'.http_build_query([
            'keyword' => $supplier->name,
            'page' => 1,
            'page_size' => 20,
        ]))
            ->assertOk()
            ->assertJsonPath('code', 0)
            ->assertJsonPath('data.list.0.id', $supplier->id)
            ->assertJsonPath('data.list.0.provider_key', 'zjmf_finance_api')
            ->assertJsonPath('data.list.0.connection.base_url', 'https://provider.example.test')
            ->assertJsonPath('data.list.0.upstream_binding.base_url', 'https://provider.example.test')
            ->assertJsonPath('data.list.0.credentials.api_credential_configured', true)
            ->assertJsonMissingPath('data.list.0.has_api_key')
            ->assertJsonMissingPath('data.list.0.has_provider_secret_values')
            ->assertJsonMissingPath('data.list.0.upstream_binding.last_check_error')
            ->assertJsonMissing(['value' => 'supplier-api-secret']);

        $this->assertSame($this->supplierPageWhitelist(), array_keys($listResponse->json('data')));
        $this->assertSame($this->supplierWhitelist(), array_keys($listResponse->json('data.list.0')));
        $this->assertSame($this->supplierBindingWhitelist(), array_keys($listResponse->json('data.list.0.upstream_binding')));
        $this->assertLessThan(100 * 1024, strlen((string) $listResponse->getContent()));

        $detailResponse = $this->getJson('/api/v2/admin/suppliers/'.$supplier->id)
            ->assertOk()
            ->assertJsonPath('code', 0)
            ->assertJsonPath('data.supplier.id', $supplier->id)
            ->assertJsonPath('data.supplier.provider_key', 'zjmf_finance_api')
            ->assertJsonPath('data.supplier.connection.base_url', 'https://provider.example.test')
            ->assertJsonPath('data.supplier.upstream_binding.base_url', 'https://provider.example.test')
            ->assertJsonMissingPath('data.supplier.upstream_binding.last_check_error');

        $this->assertSame($this->supplierWhitelist(), array_keys($detailResponse->json('data.supplier')));
        $this->assertLessThan(100 * 1024, strlen((string) $detailResponse->getContent()));
    }

    public function test_admin_supplier_secret_reveal_uses_plural_v2_secret_route(): void
    {
        $supplier = $this->createSupplierFixture();

        Sanctum::actingAs($this->createAdmin([AdminPermissions::SUPPLIER_DETAIL]));

        $this->getJson('/api/v2/admin/suppliers/'.$supplier->id.'/secrets/api_key')
            ->assertForbidden()
            ->assertJsonPath('code', 40300);

        Sanctum::actingAs($this->createAdmin([AdminPermissions::SUPPLIER_SECRET_REVEAL]));

        $this->getJson('/api/v2/admin/suppliers/'.$supplier->id.'/secrets/api_key')
            ->assertOk()
            ->assertJsonPath('code', 0)
            ->assertJsonPath('data.key', 'api_key')
            ->assertJsonPath('data.value', 'supplier-api-secret');
    }

    public function test_admin_supplier_status_action_updates_explicit_status_with_projection(): void
    {
        $supplier = $this->createSupplierFixture();

        Sanctum::actingAs($this->createAdmin([AdminPermissions::SUPPLIER_DETAIL]));

        $this->patchJson('/api/v2/admin/suppliers/'.$supplier->id.'/status', ['enabled' => false])
            ->assertForbidden()
            ->assertJsonPath('code', 40300);

        Sanctum::actingAs($this->createAdmin([AdminPermissions::SUPPLIER_MANAGE]));

        $this->patchJson('/api/v2/admin/suppliers/'.$supplier->id.'/status?per_page=20', ['enabled' => false])
            ->assertUnprocessable()
            ->assertJsonPath('code', 42200)
            ->assertJsonStructure(['data' => ['errors' => ['per_page']]]);

        $response = $this->patchJson('/api/v2/admin/suppliers/'.$supplier->id.'/status', ['enabled' => false])
            ->assertOk()
            ->assertJsonPath('code', 0)
            ->assertJsonPath('data.id', $supplier->id)
            ->assertJsonPath('data.status', 'disabled')
            ->assertJsonPath('data.detail.supplier.id', $supplier->id)
            ->assertJsonPath('data.detail.supplier.status', 0)
            ->assertJsonMissingPath('data.detail.supplier.has_api_key')
            ->assertJsonMissingPath('data.detail.supplier.upstream_binding.last_check_error');

        $this->assertSame(0, (int) $supplier->fresh()->status);
        $this->assertSame($this->actionResultWhitelist(), array_keys($response->json('data')));
        $this->assertSame($this->supplierWhitelist(), array_keys($response->json('data.detail.supplier')));
        $this->assertNoSensitiveKeys($response->json());
    }

    public function test_admin_supplier_task_action_runs_plugin_action_with_compacted_result(): void
    {
        $supplier = $this->createSupplierFixture();

        $registry = new class(app(Container::class), app(PluginScanner::class), app(PluginFileLoader::class), app(PluginConfigRepository::class), (int) $supplier->id) extends PluginRuntimeRegistry
        {
            public int $executeCalls = 0;

            public function __construct(
                Container $container,
                PluginScanner $scanner,
                PluginFileLoader $fileLoader,
                PluginConfigRepository $configRepository,
                private readonly int $expectedSupplierId,
            ) {
                parent::__construct($container, $scanner, $fileLoader, $configRepository);
            }

            public function execute(string $domain, string $slugOrKey, string $action, array $payload = [], array $context = []): array
            {
                if ($action === 'server.supplier_form_schema') {
                    return [
                        'success' => true,
                        'data' => [
                            'fields' => [],
                        ],
                    ];
                }

                Assert::assertSame('upstream', $domain);
                Assert::assertSame('zjmf_finance_api', $slugOrKey);
                Assert::assertSame('server.supplier.refresh_card', $action);
                Assert::assertSame('test', $payload['source'] ?? null);
                Assert::assertSame($this->expectedSupplierId, (int) ($context['supplier_id'] ?? 0));
                $this->executeCalls++;

                return [
                    'success' => true,
                    'message' => '连接检测完成',
                    'data' => [
                        'remote' => [
                            'connection_status' => 'success',
                            'api_key' => 'must-not-leak',
                        ],
                        'card' => [
                            'provided' => true,
                            'title' => '供应商连接',
                            'actions' => [],
                        ],
                        'created_count' => 1,
                        'raw_response' => 'must-not-leak',
                    ],
                ];
            }
        };
        $this->app->instance(PluginRuntimeRegistry::class, $registry);

        Sanctum::actingAs($this->createAdmin([AdminPermissions::SUPPLIER_DETAIL]));

        $this->postJson('/api/v2/admin/suppliers/'.$supplier->id.'/tasks', [
            'type' => 'server.supplier.refresh_card',
            'payload' => ['source' => 'test'],
        ])
            ->assertForbidden()
            ->assertJsonPath('code', 40300);

        Sanctum::actingAs($this->createAdmin([AdminPermissions::SUPPLIER_SYNC]));

        $this->postJson('/api/v2/admin/suppliers/'.$supplier->id.'/tasks', [
            'type' => 'server.supplier.bulk_connect',
            'payload' => ['product_ids' => [1], 'first_product_group_code' => 'cloud_server'],
        ])
            ->assertForbidden()
            ->assertJsonPath('code', 40300);

        $this->postJson('/api/v2/admin/suppliers/'.$supplier->id.'/tasks?per_page=20', [
            'type' => 'server.supplier.refresh_card',
        ])
            ->assertUnprocessable()
            ->assertJsonPath('code', 42200)
            ->assertJsonStructure(['data' => ['errors' => ['per_page']]]);

        $this->postJson('/api/v2/admin/suppliers/'.$supplier->id.'/tasks', [
            'type' => 'server/supplier/refresh',
        ])
            ->assertUnprocessable()
            ->assertJsonPath('code', 42200)
            ->assertJsonStructure(['data' => ['errors' => ['type']]]);

        $response = $this->postJson('/api/v2/admin/suppliers/'.$supplier->id.'/tasks', [
            'type' => 'server.supplier.refresh_card',
            'payload' => ['source' => 'test'],
        ])
            ->assertOk()
            ->assertJsonPath('code', 0)
            ->assertJsonPath('data.id', $supplier->id)
            ->assertJsonPath('data.status', 'completed')
            ->assertJsonPath('data.detail.type', 'server.supplier.refresh_card')
            ->assertJsonPath('data.detail.result.card.title', '供应商连接')
            ->assertJsonPath('data.detail.result.created_count', 1)
            ->assertJsonMissingPath('data.detail.result.remote')
            ->assertJsonMissingPath('data.detail.result.raw_response');

        $this->assertSame($this->actionResultWhitelist(), array_keys($response->json('data')));
        $this->assertNoSensitiveKeys($response->json());
        $this->assertLessThan(100 * 1024, strlen((string) $response->getContent()));
        $this->assertDatabaseHas('supplier_plugin_bindings', [
            'supplier_id' => (int) $supplier->id,
            'last_check_status' => 'success',
        ]);
        $this->assertSame(1, $registry->executeCalls);
    }

    public function test_admin_settings_use_paginated_projection_and_secret_reveal_is_separate(): void
    {
        Setting::setValue('system', 'provision_hostname_prefix', 'v2srv');
        Setting::setValue('notification', 'email_password', 'mail-secret');

        $this->getJson('/api/v2/admin/settings')
            ->assertUnauthorized()
            ->assertJsonPath('code', 40100);

        Sanctum::actingAs($this->createAdmin([]));

        $this->getJson('/api/v2/admin/settings')
            ->assertForbidden()
            ->assertJsonPath('code', 40300);

        Sanctum::actingAs($this->createAdmin([AdminPermissions::SETTINGS_VIEW]));

        $this->getJson('/api/v2/admin/settings?per_page=100')
            ->assertUnprocessable()
            ->assertJsonPath('code', 42200)
            ->assertJsonStructure(['data' => ['errors' => ['per_page']]]);

        $listResponse = $this->getJson('/api/v2/admin/settings?'.http_build_query([
            'group' => 'system',
            'page' => 1,
            'page_size' => 100,
        ]))
            ->assertOk()
            ->assertJsonPath('code', 0)
            ->assertJsonFragment(['key' => 'provision_hostname_prefix'])
            ->assertJsonFragment(['value' => 'v2srv'])
            ->assertJsonFragment(['sensitive' => false])
            ->assertJsonFragment(['configured' => true])
            ->assertJsonMissingPath('data.list.0.is_secret')
            ->assertJsonMissingPath('data.list.0.has_value')
            ->assertJsonMissingPath('data.list.0.masked_value');

        $this->assertSame($this->settingPageWhitelist(), array_keys($listResponse->json('data')));
        $this->assertSame($this->settingWhitelist(), array_keys($listResponse->json('data.list.0')));
        $this->assertLessThan(100 * 1024, strlen((string) $listResponse->getContent()));

        Sanctum::actingAs($this->createAdmin([AdminPermissions::SETTINGS_SECRET_REVEAL]));

        $this->getJson('/api/v2/admin/settings/notification/secrets/email_password')
            ->assertUnprocessable()
            ->assertJsonPath('code', 42200);
    }

    private function createConfiguredPlugin(): IntegrationPlugin
    {
        $plugin = IntegrationPlugin::query()->updateOrCreate(
            ['domain' => 'captcha', 'slug' => 'geetest'],
            [
                'plugin_key' => 'geetest',
                'name' => 'GeeTest Test',
                'version' => '1.0.0',
                'entry_class' => 'TuraIDC\\Plugins\\Captcha\\Geetest\\GeetestPlugin',
                'provider_class' => null,
                'capabilities_json' => ['config', 'verify', 'script'],
                'status' => IntegrationPlugin::STATUS_ENABLED,
                'installed_at' => now(),
            ]
        );

        $manifest = app(PluginScanner::class)->requireManifest('captcha', 'geetest');
        app(PluginConfigRepository::class)->save($plugin, $manifest, [
            'captcha_id' => 'v2-captcha-id',
            'captcha_key' => 'v2-captcha-secret',
        ], null);

        return $plugin->refresh();
    }

    private function createSupplierFixture(): Supplier
    {
        $suffix = bin2hex(random_bytes(4));
        $plugin = IntegrationPlugin::query()->updateOrCreate(
            ['domain' => 'upstream', 'slug' => 'zjmf_finance'],
            [
                'plugin_key' => 'zjmf_finance_api',
                'name' => 'Zjmf Finance',
                'version' => '1.0.0',
                'entry_class' => 'TuraIDC\\Plugins\\Servers\\ZjmfFinance\\ZjmfFinancePlugin',
                'provider_class' => null,
                'capabilities_json' => [],
                'status' => IntegrationPlugin::STATUS_ENABLED,
                'installed_at' => now(),
            ]
        );

        $supplier = Supplier::query()->create([
            'name' => 'V2 Supplier '.$suffix,
            'code' => 'v2_supplier_'.$suffix,
            'contact_name' => 'Supplier Contact',
            'contact_phone' => '13900000000',
            'contact_email' => 'supplier-'.$suffix.'@example.com',
            'website' => 'https://supplier.example.test',
            'status' => 1,
            'sort_order' => 1,
            'notes' => 'internal notes',
        ]);

        DB::table('supplier_plugin_bindings')->insert([
            'supplier_id' => (int) $supplier->id,
            'plugin_id' => (int) $plugin->id,
            'provider_key' => 'zjmf_finance_api',
            'environment' => 'production',
            'status' => 1,
            'priority' => 0,
            'base_url' => 'https://provider.example.test',
            'account_name' => 'provider-account',
            'config_json' => json_encode(['provider_config' => ['region' => 'cn']], JSON_UNESCAPED_SLASHES),
            'secret_json' => Crypt::encryptString(json_encode(['api_key' => 'supplier-api-secret'], JSON_UNESCAPED_SLASHES)),
            'has_secret_json' => json_encode(['api_key' => true], JSON_UNESCAPED_SLASHES),
            'last_checked_at' => now(),
            'last_check_status' => 'failed',
            'last_check_error' => 'raw upstream timeout with token supplier-api-secret',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $supplier->refresh();
    }

    private function createAdmin(array $permissions): AdminUser
    {
        $suffix = bin2hex(random_bytes(4));
        $role = Role::query()->create([
            'name' => 'v2-config-'.$suffix,
            'label' => 'V2 Config',
            'permissions' => $permissions,
        ]);

        return AdminUser::query()->create([
            'username' => 'v2-config-'.$suffix,
            'password' => 'Temp@123456',
            'role_id' => (int) $role->id,
            'nickname' => 'V2 Config',
            'email' => 'v2-config-'.$suffix.'@example.com',
            'status' => 1,
        ]);
    }

    /**
     * @return list<string>
     */
    private function pluginPageWhitelist(): array
    {
        return ['list', 'total', 'page', 'page_size'];
    }

    /**
     * @return list<string>
     */
    private function pluginSummaryWhitelist(): array
    {
        return [
            'id',
            'domain',
            'slug',
            'key',
            'name',
            'version',
            'provider_class',
            'capabilities',
            'is_installed',
            'is_enabled',
            'can_enable',
            'enable_disabled_reason',
            'status',
            'installed_at',
            'updated_at',
            'binding_counts',
            'business_reference_count',
            'latest_runtime_log',
            'manifest_missing',
        ];
    }

    /**
     * @return list<string>
     */
    private function pluginDetailWhitelist(): array
    {
        return array_merge($this->pluginSummaryWhitelist(), [
            'entry_class',
            'config',
            'configured_credentials',
            'credential_previews',
        ]);
    }

    /**
     * @return list<string>
     */
    private function pluginSchemaPageWhitelist(): array
    {
        return ['plugin_id', 'domain', 'slug', 'schema'];
    }

    /**
     * @return list<string>
     */
    private function supplierPageWhitelist(): array
    {
        return ['list', 'total', 'page', 'page_size'];
    }

    /**
     * @return list<string>
     */
    private function supplierWhitelist(): array
    {
        return [
            'id',
            'name',
            'code',
            'provider_key',
            'provider_label',
            'connection',
            'credentials',
            'provider_config',
            'upstream_binding',
            'contact_name',
            'contact_phone',
            'contact_email',
            'website',
            'status',
            'sort_order',
            'notes',
            'created_at',
            'updated_at',
            'card',
        ];
    }

    /**
     * @return list<string>
     */
    private function supplierBindingWhitelist(): array
    {
        return [
            'id',
            'plugin_id',
            'provider_key',
            'environment',
            'status',
            'priority',
            'base_url',
            'base_url_configured',
            'account_name',
            'credentials_configured',
            'last_checked_at',
            'last_check_status',
        ];
    }

    /**
     * @return list<string>
     */
    private function settingPageWhitelist(): array
    {
        return ['list', 'total', 'page', 'page_size'];
    }

    /**
     * @return list<string>
     */
    private function settingWhitelist(): array
    {
        return ['group', 'key', 'value', 'sensitive', 'configured', 'display_value'];
    }

    /**
     * @return list<string>
     */
    private function actionResultWhitelist(): array
    {
        return ['id', 'status', 'message', 'detail'];
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
