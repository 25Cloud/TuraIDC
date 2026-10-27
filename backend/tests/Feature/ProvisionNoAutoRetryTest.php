<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Constants\InvoiceStatus;
use App\Constants\OrderStatus;
use App\Constants\ServiceStatus;
use App\Exceptions\ProvisionRetryNotAllowedException;
use App\Jobs\ProcessPaidOrderFulfillmentJob;
use App\Models\Invoice;
use App\Models\Order;
use App\Models\Product;
use App\Models\Service;
use App\Models\User;
use App\Services\Finance\PaymentService;
use App\Services\Provisioning\ProvisionService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * 上游开通失败后绝不能被自动重试。
 *
 * 真实事故：一次购买在上游「加购 → 结算」成功后，本地没拿到账单号，失败被当作
 * 可重试错误 —— 首轮同步履约 + provision 队列 $tries=3 重试，上游因此多出
 * 4 笔一模一样的订单（对接魔方财务的客户投诉）。
 */
class ProvisionNoAutoRetryTest extends TestCase
{
    use DatabaseTransactions;

    public function test_failed_upstream_provision_stops_fulfillment_without_retry(): void
    {
        [$invoice, $order] = $this->createPaidOrderFixture();
        $service = $this->createPendingService($order, [
            'provision_error' => '上游余额不足，开通失败',
        ]);

        $this->mock(ProvisionService::class, function ($mock) use ($service): void {
            // 只允许这一次开通尝试：后面的重试都会因为异常被拦下来
            $mock->shouldReceive('processPaidOrder')->once()->andReturn($service);
        });

        try {
            app(PaymentService::class)->processPaidOrderFulfillmentById((int) $order->id);
            $this->fail('上游开通失败必须转人工，不能当成可重试错误');
        } catch (ProvisionRetryNotAllowedException $exception) {
            $this->assertStringContainsString('上游开通未完成', $exception->getMessage());
        }

        $snapshot = (array) ($invoice->fresh()?->config_snapshot ?? []);
        $this->assertArrayNotHasKey('fulfillment_cleared_at', $snapshot);
    }

    public function test_fulfillment_job_marks_invoice_and_does_not_rethrow(): void
    {
        [$invoice, $order] = $this->createPaidOrderFixture();
        $this->createPendingService($order, [
            'provision_error' => '上游账单支付未完成',
        ]);

        $this->mock(ProvisionService::class, function ($mock): void {
            $mock->shouldReceive('processPaidOrder')->andReturnUsing(function (Order $order): Service {
                return $order->service;
            });
        });

        // handle() 内部吞掉「不允许重试」异常：不抛 = 队列不会再跑一遍，也就不会重复下单
        (new ProcessPaidOrderFulfillmentJob((int) $order->id))->handle(app(PaymentService::class));

        $snapshot = (array) ($invoice->fresh()?->config_snapshot ?? []);
        $this->assertTrue((bool) ($snapshot['requires_refund'] ?? false));
    }

    public function test_clear_uncertainty_removes_marker_and_residual_invoice(): void
    {
        [, $order] = $this->createPaidOrderFixture();
        $service = $this->createPendingService($order, [
            'upstream_invoice_id' => 778899,
            'upstream_provision_uncertain_at' => '2026-10-27 10:00:00',
            'upstream_provision_uncertain_reason' => '结算成功但未返回账单号',
        ]);

        $cleared = app(ProvisionService::class)->clearUpstreamProvisionUncertainty($service, '管理员确认上游无订单');
        $provisionData = (array) ($cleared->fresh()?->provision_data ?? []);

        $this->assertArrayNotHasKey('upstream_provision_uncertain_at', $provisionData);
        $this->assertArrayNotHasKey('upstream_provision_uncertain_reason', $provisionData);
        $this->assertArrayNotHasKey('upstream_invoice_id', $provisionData);
    }

    private function createPendingService(Order $order, array $provisionData): Service
    {
        $service = Service::query()->create([
            'user_id' => (int) $order->user_id,
            'product_id' => (int) $order->product_id,
            'order_id' => (int) $order->id,
            'name' => '待开通实例',
            'domain' => 'ser000000000001',
            'billing_cycle' => 'monthly',
            'amount' => '48.00',
            'status' => ServiceStatus::PENDING,
            'auto_renew' => 0,
            'provision_data' => $provisionData,
        ]);

        $order->forceFill([
            'service_id' => (int) $service->id,
            'status' => OrderStatus::PROCESSING,
        ])->save();

        return $service;
    }

    /**
     * @return array{0: Invoice, 1: Order}
     */
    private function createPaidOrderFixture(): array
    {
        $suffix = strtoupper(bin2hex(random_bytes(4)));

        $user = User::query()->create([
            'email' => 'no-auto-retry-'.$suffix.'@example.com',
            'password' => 'secret123',
            'phone' => '13'.str_pad((string) random_int(0, 999999999), 9, '0', STR_PAD_LEFT),
            'status' => 1,
        ]);

        $product = Product::query()->create([
            'name' => '上游不重试测试商品 '.$suffix,
            'product_type' => 'server',
            'pricing' => ['monthly' => '48.00'],
            'setup_fee' => '0.00',
            'config_options' => [],
            'purchase_requires' => [],
            'stock' => -1,
            'status' => 1,
            'auto_setup' => 1,
        ]);

        $order = Order::query()->create([
            'order_no' => 'ORDNORETRY'.$suffix,
            'user_id' => (int) $user->id,
            'product_id' => (int) $product->id,
            'product_spec_snapshot' => (string) $product->name,
            'product_type_snapshot' => (string) $product->product_type,
            'type' => 'new',
            'amount' => '48.00',
            'discount' => '0.00',
            'paid_amount' => '48.00',
            'billing_cycle' => 'monthly',
            'quantity' => 1,
            'config_snapshot' => [],
            'config_pricing_snapshot' => [],
            'status' => OrderStatus::PAID,
            'paid_at' => now(),
        ]);

        $invoice = Invoice::query()->create([
            'invoice_no' => 'INVNORETRY'.$suffix,
            'user_id' => (int) $user->id,
            'order_id' => (int) $order->id,
            'product_id' => (int) $product->id,
            'type' => 'new',
            'amount' => '48.00',
            'discount' => '0.00',
            'paid_amount' => '48.00',
            'status' => InvoiceStatus::PAID,
            'paid_at' => now(),
            'due_date' => now()->addDay(),
            'billing_cycle' => 'monthly',
            'quantity' => 1,
        ]);

        return [$invoice, $order];
    }
}
