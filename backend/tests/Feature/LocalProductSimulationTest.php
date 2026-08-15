<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Constants\InvoiceStatus;
use App\Constants\OrderStatus;
use App\Constants\ServiceStatus;
use App\Models\Order;
use App\Models\Product;
use App\Models\Service;
use App\Models\Supplier;
use App\Models\User;
use App\Services\Finance\CheckoutSecurityService;
use App\Services\Finance\CheckoutService;
use App\Services\Finance\PaymentService;
use App\Services\Integrations\Plugins\PluginBindingResolver;
use App\Services\Integrations\Plugins\PluginFileLoader;
use App\Services\Integrations\Plugins\PluginScanner;
use App\Services\Integrations\Plugins\UpstreamBindingWriter;
use App\Services\Provisioning\ProvisionService;
use App\Services\Upstream\Contracts\ProvidesProvisioning;
use App\Services\Upstream\ProviderRegistry;
use App\Services\Upstream\ProviderResolver;
use Illuminate\Support\Facades\Config;
use Tests\TestCase;

class LocalProductSimulationTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Config::set('queue.default', 'sync');

        // 加载并激活 demo_servers 插件
        app(PluginFileLoader::class)->ensureLoaded(
            app(PluginScanner::class)->requireManifest('upstream', 'demo_servers')
        );
        $this->activateIntegrationPluginForTest('upstream', 'demo_servers');

        // 重建 ProviderRegistry，让 demo_servers 驱动被注册
        $this->app->forgetInstance(ProviderRegistry::class);
        $this->app->forgetInstance(ProviderResolver::class);
    }

    /**
     * 本地模拟：新购商品全链路（Invoice → Order → 支付 → 开通）
     * 不调用第三方 zjmf，全部由 demo_servers 本地模拟完成。
     */
    public function test_local_new_purchase_full_flow_with_demo_servers(): void
    {
        $suffix = bin2hex(random_bytes(4));

        // 1. 创建用户（含余额）
        $user = User::query()->create([
            'email' => "demo-purchase-{$suffix}@example.com",
            'password' => 'Temp@123456',
            'phone' => '13'.str_pad((string) random_int(0, 999999999), 9, '0', STR_PAD_LEFT),
            'status' => 1,
            'is_verified' => 1,
            'verification_status' => 2,
            'nickname' => 'Demo Purchase User',
        ]);
        $user->forceFill(['balance' => '200.00'])->save();

        // 2. 创建上游供应商
        $supplier = Supplier::query()->create([
            'name' => 'Demo 模拟供应商 '.$suffix,
            'code' => 'demo-sim-'.$suffix,
            'interface_type' => 'demo_servers',
            'api_url' => 'http://demo-'.$suffix.'.example.test',
            'api_username' => 'demo',
            'api_key' => 'secret',
            'status' => 1,
            'sort_order' => 1,
        ]);

        // 3. 创建商品，绑定 demo_servers 上游
        $product = Product::query()->create([
            'name' => 'Demo 云服务器 1C2G '.$suffix,
            'product_type' => 'server',
            'pricing' => ['monthly' => '39.00'],
            'setup_fee' => '0.00',
            'config_options' => [],
            'purchase_requires' => [],
            'stock' => 5,
            'status' => 1,
            'auto_setup' => 1,
            'supplier_id' => (int) $supplier->id,
            'provision_module' => 'demo_servers',
            'supplier_product_id' => 1001,
        ]);

        $this->bindProductToDemoServers($supplier, $product, 1001);

        // 验证绑定生效
        $resolver = app(PluginBindingResolver::class);
        $this->assertSame('demo_servers', $resolver->providerKeyForProduct($product), '商品应解析到 demo_servers');
        $this->assertNotNull($resolver->supplierForProduct($product), '商品应解析到供应商');
        $this->assertSame((int) $supplier->id, (int) $resolver->supplierIdForProduct($product));
        $this->assertSame('1001', $resolver->upstreamProductIdForProduct($product));
        $this->assertTrue(
            app(ProviderResolver::class)->resolveForProduct($product)->supports(ProvidesProvisioning::class),
            'demo_servers 应支持 provisioning'
        );

        // 4. 走购买结算流程：创建账单（自动生成订单）
        $checkout = app(CheckoutService::class);
        $checkoutSecurity = app(CheckoutSecurityService::class);

        $quote = $checkout->quote($product, 'monthly', [], 1);
        $tokenData = $checkoutSecurity->issueQuoteToken($product->id, 'monthly', [], array_merge($quote, [
            'subtotal_amount' => $quote['total_amount'],
        ]));

        $invoice = $checkout->create((int) $user->id, [
            'product_id' => (int) $product->id,
            'billing_cycle' => 'monthly',
            'quantity' => 1,
            'config' => [],
            'quote_token' => (string) $tokenData['quote_token'],
        ], [
            'idempotency_key' => 'demo-purchase-'.$suffix,
            'trace_id' => 'trace-demo-purchase-'.$suffix,
        ]);

        $this->assertDatabaseHas('invoices', [
            'id' => (int) $invoice->id,
            'type' => 'new',
            'status' => InvoiceStatus::UNPAID,
        ]);

        // 确认订单同时创建，且包含配置
        $order = Order::query()->where('id', (int) $invoice->order_id)->first();
        $this->assertNotNull($order, '新购应同时创建订单');
        $this->assertSame('new', $order->type);
        $this->assertSame(OrderStatus::PENDING, (int) $order->status);
        $this->assertNotNull($order->config_snapshot);
        $this->assertNotEmpty($order->config_snapshot);

        // 5. 直接测试开通流程（跳过支付，直接调用 processPaidOrder）
        $order->refresh();
        $order->loadMissing(['product.supplier', 'user', 'service']);
        $order->forceFill(['status' => OrderStatus::PAID])->save();
        $order->invoice->forceFill(['status' => InvoiceStatus::PAID, 'paid_amount' => $order->invoice->amount, 'paid_at' => now()])->save();

        $provisionService = app(\App\Services\Provisioning\ProvisionService::class);
        $service = $provisionService->processPaidOrder($order);

        $this->assertNotNull($service, 'processPaidOrder 应返回 Service');
        $this->assertSame(ServiceStatus::ACTIVE, (int) $service->status, '服务应为 Active');
        $this->assertSame(OrderStatus::COMPLETED, (int) $order->fresh()->status, '订单应为 Completed');

        // 验证服务关联
        $this->assertSame((int) $user->id, (int) $service->user_id);
        $this->assertSame((int) $product->id, (int) $service->product_id);

        $provisionData = (array) $service->provision_data;
        $this->assertSame('demo_servers', $provisionData['provider_key'] ?? null);
        $this->assertGreaterThan(0, (int) ($provisionData['upstream_host_id'] ?? 0), 'demo_servers 应返回模拟的 host_id');
    }

    /**
     * 本地模拟：续费账单同时创建订单
     */
    public function test_local_renew_creates_both_invoice_and_order(): void
    {
        $suffix = bin2hex(random_bytes(4));

        // 创建用户、供应商、商品（同上）
        $user = User::query()->create([
            'email' => "demo-renew-{$suffix}@example.com",
            'password' => 'Temp@123456',
            'phone' => '13'.str_pad((string) random_int(0, 999999999), 9, '0', STR_PAD_LEFT),
            'status' => 1,
            'is_verified' => 1,
            'verification_status' => 2,
            'nickname' => 'Demo Renew User',
        ]);
        $user->forceFill(['balance' => '200.00'])->save();

        $supplier = Supplier::query()->create([
            'name' => 'Demo 续费供应商 '.$suffix,
            'code' => 'demo-renew-'.$suffix,
            'interface_type' => 'demo_servers',
            'api_url' => 'http://demo-renew-'.$suffix.'.example.test',
            'api_username' => 'demo',
            'api_key' => 'secret',
            'status' => 1,
            'sort_order' => 1,
        ]);

        $product = Product::query()->create([
            'name' => 'Demo 续费产品 '.$suffix,
            'product_type' => 'server',
            'pricing' => ['monthly' => '39.00'],
            'setup_fee' => '0.00',
            'config_options' => [],
            'purchase_requires' => [],
            'stock' => 5,
            'status' => 1,
            'auto_setup' => 1,
            'supplier_id' => (int) $supplier->id,
            'provision_module' => 'demo_servers',
            'supplier_product_id' => 1001,
        ]);

        $this->bindProductToDemoServers($supplier, $product, 1001);

        // 先购买开通一个服务（用于后续续费）
        $checkout = app(CheckoutService::class);
        $checkoutSecurity = app(CheckoutSecurityService::class);
        $quote = $checkout->quote($product, 'monthly', [], 1);
        $tokenData = $checkoutSecurity->issueQuoteToken($product->id, 'monthly', [], array_merge($quote, [
            'subtotal_amount' => $quote['total_amount'],
        ]));

        $purchaseInvoice = $checkout->create((int) $user->id, [
            'product_id' => (int) $product->id,
            'billing_cycle' => 'monthly',
            'quantity' => 1,
            'config' => [],
            'quote_token' => (string) $tokenData['quote_token'],
        ], [
            'idempotency_key' => 'demo-renew-purchase-'.$suffix,
            'trace_id' => 'trace-demo-renew-purchase-'.$suffix,
        ]);

        app(PaymentService::class)->payByBalance($purchaseInvoice, $user, [
            'trace_id' => 'trace-demo-renew-pay-'.$suffix,
        ]);

        $service = Service::query()->where('user_id', (int) $user->id)
            ->where('product_id', (int) $product->id)
            ->firstOrFail();

        // 续费：通过 ServiceRenewService 创建续费账单
        $renewService = app(\App\Services\Provisioning\ServiceRenewService::class);
        $renewInvoice = $renewService->createRenewInvoiceForUser(
            $user,
            (int) $service->id,
            'monthly',
            0,
            ['trace_id' => 'trace-demo-renew-invoice-'.$suffix]
        );

        // 验证续费账单
        $this->assertDatabaseHas('invoices', [
            'id' => (int) $renewInvoice->id,
            'type' => 'renew',
        ]);

        // 验证同时创建了续费订单，且包含配置
        $this->assertGreaterThan(0, (int) ($renewInvoice->order_id ?? 0), '续费账单应有关联订单');
        $renewOrder = Order::query()->findOrFail((int) $renewInvoice->order_id);
        $this->assertSame('renew', $renewOrder->type);
        $this->assertSame(OrderStatus::PENDING, (int) $renewOrder->status);
        $this->assertNotNull($renewOrder->config_snapshot, '续费订单应包含配置信息');
        $this->assertNotEmpty($renewOrder->config_snapshot, '续费订单配置不应为空');
        $this->assertSame((int) $service->id, (int) $renewOrder->service_id);

        // 续费订单的配置应包含续费关键信息
        $config = $renewOrder->config_snapshot;
        $this->assertArrayHasKey('renew_service_id', $config);
        $this->assertSame((int) $service->id, (int) $config['renew_service_id']);
    }

    // ──────────────────────────────────────────────
    //  Helper
    // ──────────────────────────────────────────────

    private function bindProductToDemoServers(Supplier $supplier, Product $product, int $upstreamProductId): void
    {
        $writer = app(UpstreamBindingWriter::class);

        // 同步供应商插件绑定
        $supplierBindingId = $writer->syncSupplierBinding($supplier, [
            'provider_key' => 'demo_servers',
            'base_url' => (string) $supplier->api_url,
            'account_name' => (string) $supplier->api_username,
            'api_key' => (string) $supplier->api_key,
            'status' => 1,
        ]);
        $this->assertGreaterThan(0, $supplierBindingId, '供应商绑定应成功创建');

        // 同步商品上游绑定
        $productBindingId = $writer->syncProductBinding(
            $product,
            $supplier,
            (string) $upstreamProductId,
        );
        $this->assertGreaterThan(0, $productBindingId, '商品上游绑定应成功创建');
    }
}
