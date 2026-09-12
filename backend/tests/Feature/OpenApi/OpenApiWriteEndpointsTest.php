<?php

declare(strict_types=1);

namespace Tests\Feature\OpenApi;

use App\Constants\InvoiceStatus;
use App\Constants\ServiceStatus;
use App\Models\ApiKeyUsageLog;
use App\Models\Invoice;
use App\Models\Setting;
use App\Models\User;
use App\Services\Finance\CheckoutSecurityService;
use App\Services\Finance\CheckoutService;
use App\Services\OpenApi\ApiKeyService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * 开放 API 写端点回归：电源/续费/重装此前零测试覆盖，且重装参数
 * （os_template_id vs os_id）存在字段名错位。统一以 demo_servers 假上游
 * 驱动走完整 HTTP 链路验证。
 */
class OpenApiWriteEndpointsTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();

        Setting::setValues('open_api', ['enabled' => '1']);
        $this->activateIntegrationPluginForTest('upstream', 'demo_servers');
    }

    public function test_power_action_submits_to_upstream_and_records_audit(): void
    {
        $stack = $this->makeDemoServiceStack();
        [$key, $plain] = $this->createKey($stack['user']);

        $this->withToken($plain)
            ->postJson("/api/v2/open/services/{$stack['service']->id}/power", ['action' => 'reboot'])
            ->assertOk()
            ->assertJsonPath('code', 0)
            ->assertJsonPath('data.action', 'reboot');

        // 使用日志按真实密钥归属
        $this->assertDatabaseHas('api_key_usage_logs', [
            'api_key_id' => (int) $key->id,
            'user_id' => (int) $stack['user']->id,
            'method' => 'POST',
            'status_code' => 200,
        ]);
    }

    public function test_renew_creates_and_pays_invoice_via_balance(): void
    {
        $stack = $this->makeDemoServiceStack();
        $user = $stack['user'];
        $user->forceFill(['balance' => '200.00'])->save();
        [$key, $plain] = $this->createKey($user);

        $response = $this->withToken($plain)
            ->postJson("/api/v2/open/services/{$stack['service']->id}/renew", ['billing_cycle' => 'monthly'])
            ->assertOk()
            ->assertJsonPath('code', 0);

        $response->assertJsonPath('data.paid', true);

        $invoice = Invoice::query()->findOrFail((int) $response->json('data.invoice_id'));
        $this->assertSame(InvoiceStatus::PAID, (int) $invoice->status);
        $this->assertSame('99.00', (string) $invoice->amount);
    }

    public function test_renew_without_sufficient_balance_reports_failure(): void
    {
        $stack = $this->makeDemoServiceStack();
        $user = $stack['user'];
        $user->forceFill(['balance' => '1.00'])->save();
        [, $plain] = $this->createKey($user);

        $this->withToken($plain)
            ->postJson("/api/v2/open/services/{$stack['service']->id}/renew", ['billing_cycle' => 'monthly'])
            ->assertStatus(422);
    }

    public function test_reinstall_accepts_os_id_contract(): void
    {
        $stack = $this->makeDemoServiceStack();
        [, $plain] = $this->createKey($stack['user']);

        // 旧参数 os_template_id 已废弃：缺 os_id 必须被参数校验拦下
        $this->withToken($plain)
            ->postJson("/api/v2/open/services/{$stack['service']->id}/reinstall", ['os_template_id' => 3])
            ->assertStatus(422);

        // 重装选项接口提供 os_id 来源（demo 驱动返回内置系统清单）
        $this->withToken($plain)
            ->getJson("/api/v2/open/services/{$stack['service']->id}/reinstall-options")
            ->assertOk()
            ->assertJsonPath('code', 0)
            ->assertJsonStructure(['data' => ['os', 'os_groups']]);

        $this->withToken($plain)
            ->postJson("/api/v2/open/services/{$stack['service']->id}/reinstall", ['os_id' => 'debian-12'])
            ->assertOk()
            ->assertJsonPath('code', 0);
    }

    public function test_invalid_secret_attempt_is_recorded_with_sentinel_key(): void
    {
        $this->withToken('tura_totally_invalid_secret')
            ->getJson('/api/v2/open/balance')
            ->assertStatus(401);

        // 认证失败也要进审计（api_key_id=0 哨兵），否则无效密钥爆破不可见
        $this->assertDatabaseHas('api_key_usage_logs', [
            'api_key_id' => 0,
            'user_id' => 0,
            'method' => 'GET',
            'status_code' => 401,
        ]);
    }

    public function test_write_endpoints_have_independent_stricter_throttle(): void
    {
        Setting::setValues('open_api', ['write_rate_limit' => '2']);

        $base = $this->makeDemoServiceStack();
        [, $plain] = $this->createKey($base['user']);

        // 限流按 IP 对写端点独立计数；每拍用同一用户的新服务实例，避免上一拍
        // 电源动作写入的 pending 快照（runtime_status=starting）触发「状态不支持」守卫干扰判断。
        $hitThrottle = false;
        for ($attempt = 0; $attempt < 3; $attempt++) {
            $stack = $this->makeDemoServiceStack($base);

            $response = $this->withToken($plain)
                ->postJson("/api/v2/open/services/{$stack['service']->id}/power", ['action' => 'on']);

            if ($response->status() === 429) {
                $hitThrottle = true;
                break;
            }

            $response->assertOk();
        }

        $this->assertTrue($hitThrottle, '写请求超过 write_rate_limit 后应被 429 拦下');
    }

    public function test_order_creation_reuses_invoice_for_same_idempotency_key_after_cache_eviction(): void
    {
        $stack = $this->makeDemoServiceStack();
        $user = $stack['user'];
        [, $plain] = $this->createKey($user, ['orders' => 'write', 'products' => 'read']);

        $product = $stack['product'];
        $checkout = app(CheckoutService::class);
        $quote = $checkout->quote($product, 'monthly', [], 1);
        $tokenData = app(CheckoutSecurityService::class)->issueQuoteToken(
            $product->id, 'monthly', [], array_merge($quote, ['subtotal_amount' => $quote['total_amount']])
        );

        $idempotencyKey = 'open-api-order-'.bin2hex(random_bytes(6));
        $payload = [
            'product_id' => (int) $product->id,
            'billing_cycle' => 'monthly',
            'quantity' => 1,
            'config' => [],
            'quote_token' => (string) $tokenData['quote_token'],
            'idempotency_key' => $idempotencyKey,
        ];

        $first = $this->withToken($plain)->postJson('/api/v2/open/orders', $payload)
            ->assertOk()->assertJsonPath('code', 0);
        $firstInvoiceId = (int) $first->json('data.id');

        // 模拟 volatile 缓存驱逐/重启：幂等映射全部丢失
        Cache::store('redis_volatile')->flush();

        $second = $this->withToken($plain)->postJson('/api/v2/open/orders', $payload)
            ->assertOk()->assertJsonPath('code', 0);

        $this->assertSame($firstInvoiceId, (int) $second->json('data.id'), '缓存驱逐后重放必须复用既有账单');
        $this->assertSame(1, Invoice::query()
            ->where('user_id', (int) $user->id)
            ->where('idempotency_key', $idempotencyKey)
            ->count());

    }

    /**
     * 构建挂接 demo_servers 假上游的完整服务栈（用户/供应商/商品/实例/绑定）。
     * 传入 $base 时复用既有用户/供应商/商品，仅追加一个新服务实例。
     *
     * @param  array{user: User, supplier: \App\Models\Supplier, product: \App\Models\Product, product_binding_id: int}|null  $base
     * @return array{user: User, supplier: \App\Models\Supplier, product: \App\Models\Product, service: \App\Models\Service, product_binding_id: int}
     */
    private function makeDemoServiceStack(?array $base = null): array
    {
        $unique = 'open-write-'.bin2hex(random_bytes(4));
        $pluginId = (int) DB::table('integration_plugins')
            ->where('domain', 'upstream')
            ->where('plugin_key', 'demo_servers')
            ->value('id');
        $this->assertGreaterThan(0, $pluginId);

        if ($base !== null) {
            $user = $base['user'];
            $supplier = $base['supplier'];
            $product = $base['product'];
            $supplierBindingId = (int) DB::table('supplier_plugin_bindings')
                ->where('supplier_id', (int) $supplier->id)
                ->value('id');
            $productBindingId = (int) $base['product_binding_id'];
        } else {
            $user = User::query()->create([
                'email' => $unique.'@example.test',
                'password' => 'Temp@123456',
                'phone' => '13'.str_pad((string) random_int(0, 999999999), 9, '0', STR_PAD_LEFT),
                'status' => 1,
            ]);

            $supplier = \App\Models\Supplier::query()->create([
                'name' => 'Open API Demo Supplier '.$unique,
                'code' => $unique,
                'interface_type' => 'demo_servers',
                'api_url' => 'https://demo-'.$unique.'.example.test',
                'api_username' => 'demo',
                'api_key' => 'secret',
                'status' => 1,
                'sort_order' => 1,
            ]);

            $product = \App\Models\Product::query()->create([
                'name' => 'Open API Demo Product '.$unique,
                'product_type' => 'server',
                'pricing' => ['monthly' => '99.00'],
                'setup_fee' => '0.00',
                'config_options' => [],
                'purchase_requires' => [],
                'stock' => -1,
                'status' => 1,
                'auto_setup' => 1,
                'supplier_id' => (int) $supplier->id,
                'supplier_product_id' => 41001,
                'provision_module' => 'demo_servers',
            ]);

            $supplierBindingId = (int) DB::table('supplier_plugin_bindings')->insertGetId([
                'supplier_id' => (int) $supplier->id,
                'plugin_id' => $pluginId,
                'provider_key' => 'demo_servers',
                'environment' => 'production',
                'status' => 1,
                'priority' => 0,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $productBindingId = (int) DB::table('product_upstream_bindings')->insertGetId([
                'product_id' => (int) $product->id,
                'supplier_plugin_binding_id' => $supplierBindingId,
                'plugin_id' => $pluginId,
                'provider_key' => 'demo_servers',
                'upstream_product_id' => '41001',
                'auto_setup' => 1,
                'status' => 1,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        $service = \App\Models\Service::query()->create([
            'user_id' => (int) $user->id,
            'product_id' => (int) $product->id,
            'name' => 'Open API Demo Service '.$unique,
            'domain' => $unique.'.example.test',
            'billing_cycle' => 'monthly',
            'amount' => '99.00',
            'status' => ServiceStatus::ACTIVE,
            'locked_pricing' => [],
            'provision_data' => [
                'provider' => 'demo_servers',
                'supplier_id' => (int) $supplier->id,
                'supplier_product_id' => 41001,
                'upstream_host_id' => 41111,
            ],
            'expires_at' => now()->addMonth(),
            'auto_renew' => 1,
        ]);

        DB::table('service_upstream_bindings')->insert([
            'service_id' => (int) $service->id,
            'product_upstream_binding_id' => $productBindingId,
            'supplier_plugin_binding_id' => $supplierBindingId,
            'plugin_id' => $pluginId,
            'provider_key' => 'demo_servers',
            'upstream_service_id' => '41111',
            'status_snapshot' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return [
            'user' => $user, 'supplier' => $supplier, 'product' => $product,
            'service' => $service, 'product_binding_id' => $productBindingId,
        ];
    }

    /**
     * @param  array<string, string>  $scopes
     * @return array{0: \App\Models\ApiKey, 1: string}
     */
    private function createKey(User $user, array $scopes = [
        'products' => 'read', 'orders' => 'write', 'services' => 'write', 'finance' => 'read',
    ]): array
    {
        return app(ApiKeyService::class)->createForUser($user, '写端点测试', $scopes);
    }
}
