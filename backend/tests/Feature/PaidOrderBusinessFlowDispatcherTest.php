<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Constants\InvoiceStatus;
use App\Constants\OrderStatus;
use App\Exceptions\BusinessException;
use App\Jobs\ProcessPaidOrderFulfillmentJob;
use App\Jobs\ProcessPaidOrderReferralRewardJob;
use App\Models\Invoice;
use App\Models\Order;
use App\Models\Product;
use App\Models\Service;
use App\Models\User;
use App\Services\Finance\PaymentService;
use App\Services\Order\PaidOrderBusinessFlowDispatcher;
use App\Services\Provisioning\ProvisionService;
use App\Services\Provisioning\ServiceRenewService;
use App\Services\User\AccountService;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class PaidOrderBusinessFlowDispatcherTest extends TestCase
{
    public function test_web_payment_dispatches_upgrade_fulfillment_to_queue(): void
    {
        Queue::fake();
        config()->set('queue.default', 'database');

        [$invoice, $order] = $this->createPaidInvoiceWithOrder('upgrade');

        $this->runAsWebRequest(function () use ($invoice): void {
            app(PaidOrderBusinessFlowDispatcher::class)->dispatchPaidInvoice($invoice->fresh(['order']), 'trace-web-upgrade');
        });

        Queue::assertNotPushed(ProcessPaidOrderReferralRewardJob::class);
        Queue::assertPushedOn('provision', ProcessPaidOrderFulfillmentJob::class, function (ProcessPaidOrderFulfillmentJob $job) use ($order): bool {
            return (int) $job->orderId === (int) $order->id;
        });
    }

    public function test_web_payment_fulfills_new_purchase_synchronously(): void
    {
        [$invoice, $order] = $this->createPaidInvoiceWithOrder('new');

        $this->mock(PaymentService::class, function ($mock) use ($order): void {
            $mock->shouldReceive('processPaidOrderReferralRewardById')
                ->andReturnNull();
            $mock->shouldReceive('processPaidOrderFulfillmentById')
                ->once()
                ->with((int) $order->id)
                ->andReturnNull();
        });

        $this->runAsWebRequest(function () use ($invoice): void {
            app(PaidOrderBusinessFlowDispatcher::class)->dispatchPaidInvoice($invoice->fresh(['order']), 'trace-sync-new');
        });
    }

    public function test_synchronous_fulfillment_failure_falls_back_to_queue(): void
    {
        config()->set('queue.default', 'database');

        [$invoice, $order] = $this->createPaidInvoiceWithOrder('new');

        $this->mock(PaymentService::class, function ($mock) use ($order): void {
            $mock->shouldReceive('processPaidOrderReferralRewardById')
                ->andReturnNull();
            $mock->shouldReceive('processPaidOrderFulfillmentById')
                ->once()
                ->with((int) $order->id)
                ->andThrow(new BusinessException('上游开通暂时不可用'));
        });

        $this->runAsWebRequest(function () use ($invoice): void {
            app(PaidOrderBusinessFlowDispatcher::class)->dispatchPaidInvoice($invoice->fresh(['order']), 'trace-sync-fallback');
        });

        $this->assertProvisionJobQueued((int) $order->id);
    }

    public function test_paid_order_jobs_are_unique_by_order_id(): void
    {
        $fulfillmentJob = new ProcessPaidOrderFulfillmentJob(123);
        $referralJob = new ProcessPaidOrderReferralRewardJob(123, 'trace-unique');

        $this->assertInstanceOf(ShouldBeUnique::class, $fulfillmentJob);
        $this->assertInstanceOf(ShouldBeUnique::class, $referralJob);
        $this->assertSame('123', $fulfillmentJob->uniqueId());
        $this->assertSame('123', $referralJob->uniqueId());
    }

    public function test_renew_payment_fulfills_synchronously_without_referral_reward(): void
    {
        [$invoice, $order] = $this->createPaidInvoiceWithOrder('renew');

        $service = new Service;
        $service->setAttribute('id', 99901);
        $this->mock(ServiceRenewService::class, function ($mock) use ($service): void {
            $mock->shouldReceive('processPaidRenewOrder')
                ->once()
                ->andReturn($service);
            $mock->shouldReceive('isRenewInvoiceFulfilled')
                ->once()
                ->andReturnTrue();
        });

        $this->runAsWebRequest(function () use ($invoice): void {
            app(PaymentService::class)->handlePaidInvoice($invoice->fresh(['order']), 'trace-renew-payment');
        });
    }

    public function test_balance_order_payment_keeps_fulfillment_pending_and_falls_back_to_queue(): void
    {
        config()->set('queue.default', 'database');

        [$invoice, $order, $user] = $this->createPaidInvoiceWithOrder(
            'renew',
            OrderStatus::PENDING,
            InvoiceStatus::UNPAID,
        );
        app(AccountService::class)->setCashBalance($user, '100.00');

        $this->mock(ServiceRenewService::class, function ($mock): void {
            $mock->shouldReceive('processPaidRenewOrder')
                ->once()
                ->andReturnNull();
            $mock->shouldReceive('isRenewInvoiceFulfilled')
                ->once()
                ->andReturnFalse();
        });

        $this->runAsWebRequest(function () use ($order, $user): void {
            app(PaymentService::class)->payOrderByBalance($order, $user, ['trace_id' => 'trace-pending-payment']);
        });

        $configSnapshot = (array) ($invoice->fresh()?->config_snapshot ?? []);

        $this->assertTrue((bool) ($configSnapshot['fulfillment_pending'] ?? false));
        $this->assertSame('renew', $configSnapshot['fulfillment_type'] ?? null);
        $this->assertProvisionJobQueued((int) $order->id);
    }

    public function test_fulfillment_pending_is_cleared_after_successful_job_execution(): void
    {
        [$invoice, $order] = $this->createPaidInvoiceWithOrder();
        $invoice->forceFill([
            'config_snapshot' => [
                'fulfillment_pending' => true,
                'fulfillment_type' => 'new',
            ],
        ])->save();

        $this->mock(ProvisionService::class, function ($mock): void {
            $mock->shouldReceive('processPaidOrder')->once()->andReturn(null);
        });

        app(PaymentService::class)->processPaidOrderFulfillmentById((int) $order->id);

        $configSnapshot = (array) ($invoice->fresh()?->config_snapshot ?? []);

        $this->assertFalse((bool) ($configSnapshot['fulfillment_pending'] ?? true));
        $this->assertArrayHasKey('fulfillment_cleared_at', $configSnapshot);
    }

    public function test_fulfillment_pending_remains_when_renewal_is_not_completed(): void
    {
        [$invoice, $order] = $this->createPaidInvoiceWithOrder('renew');
        $invoice->forceFill([
            'config_snapshot' => [
                'fulfillment_pending' => true,
                'fulfillment_type' => 'renew',
            ],
        ])->save();

        $this->mock(ServiceRenewService::class, function ($mock): void {
            $mock->shouldReceive('processPaidRenewOrder')->once()->andReturn(null);
            $mock->shouldReceive('isRenewInvoiceFulfilled')->once()->andReturnFalse();
        });

        try {
            app(PaymentService::class)->processPaidOrderFulfillmentById((int) $order->id);
            $this->fail('未完成的续费履约必须交由队列重试');
        } catch (BusinessException $exception) {
            $this->assertSame('支付后履约未完成，等待后续重试', $exception->getMessage());
        }

        $configSnapshot = (array) ($invoice->fresh()?->config_snapshot ?? []);

        $this->assertTrue((bool) ($configSnapshot['fulfillment_pending'] ?? false));
        $this->assertArrayNotHasKey('fulfillment_cleared_at', $configSnapshot);
    }

    public function test_fulfillment_pending_remains_when_new_purchase_requires_manual_provisioning(): void
    {
        [$invoice, $order] = $this->createPaidInvoiceWithOrder();
        $invoice->forceFill([
            'config_snapshot' => [
                'fulfillment_pending' => true,
                'fulfillment_type' => 'new',
            ],
        ])->save();

        try {
            app(PaymentService::class)->processPaidOrderFulfillmentById((int) $order->id);
            $this->fail('待人工开通的新购不能被当作已完成履约');
        } catch (BusinessException $exception) {
            $this->assertSame('支付后履约未完成，等待后续重试', $exception->getMessage());
        }

        $configSnapshot = (array) ($invoice->fresh()?->config_snapshot ?? []);

        $this->assertTrue((bool) ($configSnapshot['fulfillment_pending'] ?? false));
        $this->assertArrayNotHasKey('fulfillment_cleared_at', $configSnapshot);
        $this->assertSame(OrderStatus::PROCESSING, (int) $order->fresh()->status);
    }

    /**
     * 断言 provision 队列中存在该订单的履约任务，并在断言后清理该任务。
     */
    private function assertProvisionJobQueued(int $orderId): void
    {
        $jobRow = DB::table('jobs')
            ->where('queue', 'provision')
            ->orderByDesc('id')
            ->limit(50)
            ->get()
            ->first(function (object $row) use ($orderId): bool {
                $payload = json_decode((string) $row->payload, true);
                if (! is_array($payload) || ! isset($payload['data']['command'])) {
                    return false;
                }

                $command = @unserialize((string) $payload['data']['command']);

                return $command instanceof ProcessPaidOrderFulfillmentJob
                    && (int) $command->orderId === $orderId;
            });

        try {
            $this->assertNotNull($jobRow, "order {$orderId} 的履约任务未进入 provision 队列");
        } finally {
            if ($jobRow) {
                DB::table('jobs')->where('id', $jobRow->id)->delete();
            }
        }
    }

    /**
     * @return array{0: Invoice, 1: Order, 2: User}
     */
    private function createPaidInvoiceWithOrder(
        string $type = 'new',
        int $orderStatus = OrderStatus::PAID,
        int $invoiceStatus = InvoiceStatus::PAID,
    ): array {
        $suffix = strtoupper(bin2hex(random_bytes(4)));
        $user = User::query()->create([
            'email' => 'paid-dispatch-'.$suffix.'@example.com',
            'password' => 'secret123',
            'phone' => '13'.str_pad((string) random_int(0, 999999999), 9, '0', STR_PAD_LEFT),
            'status' => 1,
        ]);

        $product = Product::query()->create([
            'name' => '支付后队列开通测试商品 '.$suffix,
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
            'order_no' => 'ORDPAIDDISPATCH'.$suffix,
            'user_id' => (int) $user->id,
            'product_id' => (int) $product->id,
            'product_spec_snapshot' => (string) $product->name,
            'product_type_snapshot' => (string) $product->product_type,
            'type' => $type,
            'amount' => '48.00',
            'discount' => '0.00',
            'paid_amount' => $orderStatus === OrderStatus::PENDING ? '0.00' : '48.00',
            'billing_cycle' => 'monthly',
            'quantity' => 1,
            'config_snapshot' => [],
            'config_pricing_snapshot' => [],
            'status' => $orderStatus,
            'paid_at' => $orderStatus === OrderStatus::PENDING ? null : now(),
        ]);

        $invoice = Invoice::query()->create([
            'invoice_no' => 'INVPAIDDISPATCH'.$suffix,
            'user_id' => (int) $user->id,
            'order_id' => (int) $order->id,
            'product_id' => (int) $product->id,
            'type' => $type,
            'amount' => '48.00',
            'discount' => '0.00',
            'paid_amount' => $invoiceStatus === InvoiceStatus::UNPAID ? '0.00' : '48.00',
            'status' => $invoiceStatus,
            'paid_at' => $invoiceStatus === InvoiceStatus::UNPAID ? null : now(),
            'due_date' => now()->addDay(),
            'billing_cycle' => 'monthly',
            'quantity' => 1,
        ]);

        return [$invoice, $order, $user];
    }

    private function runAsWebRequest(callable $callback): void
    {
        $consoleFlag = new \ReflectionProperty(app(), 'isRunningInConsole');
        $consoleFlag->setAccessible(true);
        $originalConsoleFlag = $consoleFlag->getValue(app());
        $consoleFlag->setValue(app(), false);

        try {
            $callback();
        } finally {
            $consoleFlag->setValue(app(), $originalConsoleFlag);
        }
    }
}
