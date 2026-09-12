<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Constants\ServiceStatus;
use App\Models\Setting;
use App\Models\User;
use App\Services\ClientServiceConsole\ServiceSuspensionService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * 暂停/解除暂停编排回归。
 *
 * demo_servers 假上游此前没有 suspendHost/unsuspendHost 具名方法，编排层
 * 回退的通用 PUT 路径在它身上会直接 fatal；契约化（ProvidesHostSuspension）
 * 之后 demo 驱动补齐了具名方法。本测试同时覆盖续费后自动解挂路径。
 */
class ServiceSuspensionUpstreamTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();

        $this->activateIntegrationPluginForTest('upstream', 'demo_servers');
    }

    public function test_suspend_and_unsuspend_flow_through_named_upstream_contract(): void
    {
        $stack = $this->makeDemoServiceStack();
        $service = $stack['service'];
        $suspensions = app(ServiceSuspensionService::class);

        $result = $suspensions->suspendForUser($stack['user'], (int) $service->id, ['reason' => '违规使用'], []);
        $this->assertSame('suspend', $result['action']);
        $this->assertSame(ServiceStatus::SUSPENDED, (int) $service->fresh()->status);
        $this->assertSame('违规使用', (string) $service->fresh()->suspended_reason);

        $restored = $suspensions->unsuspendForUser($stack['user'], (int) $service->id, []);
        $this->assertSame('unsuspend', $restored['action']);
        $this->assertSame(ServiceStatus::ACTIVE, (int) $service->fresh()->status);
        $this->assertNull($service->fresh()->suspended_reason);
    }

    public function test_try_unsuspend_upstream_restores_suspended_service_after_renew(): void
    {
        $stack = $this->makeDemoServiceStack();
        $service = $stack['service'];
        $service->forceFill([
            'status' => ServiceStatus::SUSPENDED,
            'suspended_reason' => '到期暂停',
        ])->save();

        $restored = app(ServiceSuspensionService::class)->tryUnsuspendUpstream($service, true);

        $this->assertTrue($restored);
        $this->assertSame(ServiceStatus::ACTIVE, (int) $service->fresh()->status);
        $this->assertNull($service->fresh()->suspended_reason);
    }

    public function test_try_unsuspend_upstream_swallows_failure_without_touching_service(): void
    {
        // 绑定指向不存在的上游 host id 时 resolveUpstreamContext 不报错（demo 不校验），
        // 但可以通过移除服务绑定让 resolveUpstreamContext 抛异常，验证失败路径被吞掉。
        $stack = $this->makeDemoServiceStack();
        $service = $stack['service'];
        $service->forceFill(['status' => ServiceStatus::SUSPENDED, 'suspended_reason' => 'x'])->save();

        DB::table('service_upstream_bindings')->where('service_id', (int) $service->id)->delete();

        $restored = app(ServiceSuspensionService::class)->tryUnsuspendUpstream($service, true);

        $this->assertFalse($restored);
        $this->assertSame(ServiceStatus::SUSPENDED, (int) $service->fresh()->status);
    }

    /**
     * 构建挂接 demo_servers 假上游的完整服务栈（与 OpenApiWriteEndpointsTest 同构）。
     *
     * @return array{user: User, supplier: \App\Models\Supplier, product: \App\Models\Product, service: \App\Models\Service}
     */
    private function makeDemoServiceStack(): array
    {
        $unique = 'suspend-'.bin2hex(random_bytes(4));
        $pluginId = (int) DB::table('integration_plugins')
            ->where('domain', 'upstream')
            ->where('plugin_key', 'demo_servers')
            ->value('id');
        $this->assertGreaterThan(0, $pluginId);

        $user = User::query()->create([
            'email' => $unique.'@example.test',
            'password' => 'Temp@123456',
            'phone' => '13'.str_pad((string) random_int(0, 999999999), 9, '0', STR_PAD_LEFT),
            'status' => 1,
        ]);

        $supplier = \App\Models\Supplier::query()->create([
            'name' => 'Suspend Demo Supplier '.$unique,
            'code' => $unique,
            'interface_type' => 'demo_servers',
            'api_url' => 'https://demo-'.$unique.'.example.test',
            'api_username' => 'demo',
            'api_key' => 'secret',
            'status' => 1,
            'sort_order' => 1,
        ]);

        $product = \App\Models\Product::query()->create([
            'name' => 'Suspend Demo Product '.$unique,
            'product_type' => 'server',
            'pricing' => ['monthly' => '66.00'],
            'setup_fee' => '0.00',
            'config_options' => [],
            'purchase_requires' => [],
            'stock' => -1,
            'status' => 1,
            'auto_setup' => 1,
            'supplier_id' => (int) $supplier->id,
            'supplier_product_id' => 42001,
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
            'upstream_product_id' => '42001',
            'auto_setup' => 1,
            'status' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $service = \App\Models\Service::query()->create([
            'user_id' => (int) $user->id,
            'product_id' => (int) $product->id,
            'name' => 'Suspend Demo Service '.$unique,
            'domain' => $unique.'.example.test',
            'billing_cycle' => 'monthly',
            'amount' => '66.00',
            'status' => ServiceStatus::ACTIVE,
            'locked_pricing' => [],
            'provision_data' => [
                'provider' => 'demo_servers',
                'supplier_id' => (int) $supplier->id,
                'supplier_product_id' => 42001,
                'upstream_host_id' => 42111,
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
            'upstream_service_id' => '42111',
            'status_snapshot' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return ['user' => $user, 'supplier' => $supplier, 'product' => $product, 'service' => $service];
    }
}
