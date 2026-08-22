<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Constants\ServiceStatus;
use App\Models\Product;
use App\Models\Service;
use App\Models\Supplier;
use App\Models\User;
use App\Services\ClientServiceConsole\ServiceTransformService;
use App\Services\Integrations\Plugins\PluginBindingResolver;
use App\Services\Upstream\ProviderKey;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ServiceProvisionDataTokenSanitizationTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->activateIntegrationPluginForTest('upstream', 'zjmf_finance');
    }

    public function test_projection_excludes_tokens_unless_secrets_requested(): void
    {
        $service = $this->createServiceWithTokens();

        $resolver = app(PluginBindingResolver::class);
        $this->assertArrayNotHasKey('downstream_token', $resolver->serviceProvisionProjection($service));
        $this->assertArrayNotHasKey('ticket_callback_token', $resolver->serviceProvisionProjection($service));

        $withSecrets = $resolver->serviceProvisionProjection($service, true);
        $this->assertSame('snapshot-ds-token', $withSecrets['downstream_token'] ?? null);
        $this->assertSame('snapshot-cb-token', $withSecrets['ticket_callback_token'] ?? null);
    }

    public function test_sanitize_service_provision_data_removes_callback_tokens_only(): void
    {
        $resolver = app(PluginBindingResolver::class);
        $data = [
            'downstream_token' => 'a',
            'ticket_callback_token' => 'b',
            'connection_secret' => 'encrypted',
            'password' => 'pw',
        ];

        $sanitized = $resolver->sanitizeServiceProvisionData($data);

        $this->assertArrayNotHasKey('downstream_token', $sanitized);
        $this->assertArrayNotHasKey('ticket_callback_token', $sanitized);
        $this->assertSame('encrypted', $sanitized['connection_secret']);
        $this->assertSame('pw', $sanitized['password']);
    }

    public function test_password_cache_write_never_persists_tokens_and_snapshot_keeps_them(): void
    {
        $service = $this->createServiceWithTokens();
        // legacy provision_data 里也放 token：即使历史数据已含 token，写回也必须剔除
        $service->forceFill([
            'provision_data' => array_merge((array) $service->provision_data, [
                'downstream_token' => 'legacy-ds-token',
                'ticket_callback_token' => 'legacy-cb-token',
            ]),
        ])->save();

        app(ServiceTransformService::class)->cacheSubmittedPasswordForService($service, 'new-pass-123');

        $persisted = $service->fresh()->provision_data;
        $this->assertIsArray($persisted);
        $this->assertArrayNotHasKey('downstream_token', $persisted);
        $this->assertArrayNotHasKey('ticket_callback_token', $persisted);

        // token 只保留在加密 secret 快照中，供 writer 回退与回调注册使用
        $snapshot = DB::table('service_connection_snapshots')
            ->where('service_id', (int) $service->id)
            ->orderByDesc('id')
            ->first(['secret_json', 'has_secret_json']);
        $this->assertNotNull($snapshot);
        $secrets = json_decode(Crypt::decryptString($snapshot->secret_json), true);
        $this->assertSame('snapshot-ds-token', $secrets['downstream_token'] ?? null);
    }

    private function createServiceWithTokens(): Service
    {
        $unique = 'token-sanitize-'.bin2hex(random_bytes(4));
        $pluginId = (int) DB::table('integration_plugins')
            ->where('domain', 'upstream')
            ->where('plugin_key', ProviderKey::ZJMF_FINANCE_API)
            ->value('id');
        $this->assertGreaterThan(0, $pluginId);

        $user = User::query()->create([
            'email' => $unique.'@example.test',
            'password' => 'Temp@123456',
            'phone' => '15'.str_pad((string) random_int(0, 999999999), 9, '0', STR_PAD_LEFT),
            'status' => 1,
        ]);

        $supplier = Supplier::query()->create([
            'name' => 'Token Sanitize Supplier '.$unique,
            'code' => 'token-'.$unique,
            'interface_type' => ProviderKey::ZJMF_FINANCE_API,
            'api_url' => 'https://supplier-'.$unique.'.example.test',
            'api_username' => 'demo',
            'api_key' => 'secret',
            'status' => 1,
            'sort_order' => 1,
        ]);

        $product = Product::query()->create([
            'name' => 'Token Sanitize Product '.$unique,
            'product_type' => 'server',
            'pricing' => ['monthly' => '99.00'],
            'setup_fee' => '0.00',
            'config_options' => [],
            'purchase_requires' => [],
            'stock' => -1,
            'status' => 1,
            'auto_setup' => 1,
            'supplier_id' => (int) $supplier->id,
            'supplier_product_id' => 123,
            'provision_module' => ProviderKey::ZJMF_FINANCE_API,
        ]);

        $supplierBindingId = DB::table('supplier_plugin_bindings')->insertGetId([
            'supplier_id' => (int) $supplier->id,
            'plugin_id' => $pluginId,
            'provider_key' => ProviderKey::ZJMF_FINANCE_API,
            'environment' => 'production',
            'status' => 1,
            'priority' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $productBindingId = DB::table('product_upstream_bindings')->insertGetId([
            'product_id' => (int) $product->id,
            'supplier_plugin_binding_id' => $supplierBindingId,
            'plugin_id' => $pluginId,
            'provider_key' => ProviderKey::ZJMF_FINANCE_API,
            'upstream_product_id' => '123',
            'auto_setup' => 1,
            'status' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $service = Service::query()->create([
            'user_id' => (int) $user->id,
            'product_id' => (int) $product->id,
            'name' => 'Token Sanitize Service '.$unique,
            'domain' => $unique.'.example.test',
            'billing_cycle' => 'monthly',
            'amount' => '99.00',
            'status' => ServiceStatus::ACTIVE,
            'locked_pricing' => [],
            'provision_data' => [
                'provider_key' => ProviderKey::ZJMF_FINANCE_API,
                'supplier_id' => (int) $supplier->id,
                'upstream_product_id' => 123,
                'upstream_host_id' => 98765,
            ],
            'expires_at' => now()->addMonth(),
            'auto_renew' => 1,
        ]);

        DB::table('service_upstream_bindings')->insert([
            'service_id' => (int) $service->id,
            'product_upstream_binding_id' => $productBindingId,
            'supplier_plugin_binding_id' => $supplierBindingId,
            'plugin_id' => $pluginId,
            'provider_key' => ProviderKey::ZJMF_FINANCE_API,
            'upstream_service_id' => '98765',
            'status_snapshot' => 'Active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // normalized secret 快照：token 唯一允许的存储位置（加密列）
        DB::table('service_connection_snapshots')->insert([
            'service_id' => (int) $service->id,
            'connection_type' => 'default',
            'provider_key' => ProviderKey::ZJMF_FINANCE_API,
            'secret_json' => Crypt::encryptString((string) json_encode([
                'connection_secret' => 'encrypted-secret',
                'password' => 'old-password',
                'downstream_token' => 'snapshot-ds-token',
                'ticket_callback_token' => 'snapshot-cb-token',
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)),
            'has_secret_json' => json_encode([
                'connection_secret' => true,
                'password' => true,
                'downstream_token' => true,
                'ticket_callback_token' => true,
            ]),
            'checked_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $service;
    }
}
