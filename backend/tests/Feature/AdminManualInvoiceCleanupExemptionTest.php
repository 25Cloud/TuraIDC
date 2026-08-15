<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Constants\InvoiceStatus;
use App\Constants\OrderStatus;
use App\Models\Invoice;
use App\Models\Order;
use App\Models\User;
use App\Services\Automation\InvoiceCleanupAutomationService;
use App\Services\Finance\CheckoutService;
use App\Services\Finance\PaymentService;
use App\Services\Order\OrderService;
use App\Services\System\SettingService;
use Tests\TestCase;

/**
 * 回归：管理员手动开通产生的挂账单据按 due_date 计费，
 * 不得被 5 分钟支付会话窗口的清理任务取消。
 *
 * 这类单据从未扣过库存，被取消会触发 stock+1 造成库存虚增。
 */
class AdminManualInvoiceCleanupExemptionTest extends TestCase
{
    public function test_admin_manual_invoice_and_order_survive_payment_window_cleanup(): void
    {
        [$manualInvoice, $manualOrder] = $this->createUnpaidPair('manualkeep', adminManual: true);
        [$normalInvoice, $normalOrder] = $this->createUnpaidPair('manualsweep', adminManual: false);

        $summary = $this->runCleanup();

        $this->assertSame(
            InvoiceStatus::UNPAID,
            (int) $manualInvoice->refresh()->status,
            '管理员手动开通的挂账账单不应被支付窗口清理取消'
        );
        $this->assertSame(
            OrderStatus::PENDING,
            (int) $manualOrder->refresh()->status,
            '管理员手动开通的挂账订单不应被取消，否则会触发库存虚增'
        );

        // 对照组证明清理任务本身仍在正常工作
        $this->assertGreaterThanOrEqual(1, $summary['invoices_cancelled']);
        $this->assertSame(InvoiceStatus::CANCELLED, (int) $normalInvoice->refresh()->status);
        $this->assertSame(OrderStatus::CANCELLED, (int) $normalOrder->refresh()->status);
    }

    /**
     * @return array{0: Invoice, 1: Order}
     */
    private function createUnpaidPair(string $prefix, bool $adminManual): array
    {
        $suffix = bin2hex(random_bytes(4));
        $createdAt = now()->subMinutes(6)->startOfSecond();

        $user = User::query()->create([
            'email' => strtolower($prefix).'-'.$suffix.'@example.com',
            'password' => 'Temp@123456',
            'phone' => '13'.str_pad((string) random_int(0, 999999999), 9, '0', STR_PAD_LEFT),
            'status' => 1,
            'nickname' => $prefix,
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
        $user->forceFill(['balance' => '0.00'])->save();

        $configSnapshot = $adminManual ? ['admin_manual' => true, 'source_type' => 'manual'] : [];

        $order = Order::query()->create([
            'order_no' => strtoupper($prefix).'ORD'.strtoupper($suffix),
            'user_id' => (int) $user->id,
            'type' => 'new',
            'amount' => '50.00',
            'discount' => '0.00',
            'paid_amount' => '0.00',
            'billing_cycle' => 'monthly',
            'config_snapshot' => $configSnapshot,
            'status' => OrderStatus::PENDING,
        ]);

        $invoice = Invoice::query()->create([
            'invoice_no' => strtoupper($prefix).'INV'.strtoupper($suffix),
            'user_id' => (int) $user->id,
            'order_id' => (int) $order->id,
            'type' => 'normal',
            'amount' => '50.00',
            'paid_amount' => '0.00',
            'config_snapshot' => $configSnapshot,
            'status' => InvoiceStatus::UNPAID,
            'due_date' => now()->addDays(7),
        ]);

        foreach ([$order, $invoice] as $model) {
            $model->forceFill(['created_at' => $createdAt, 'updated_at' => $createdAt])->save();
        }

        return [$invoice, $order];
    }

    /**
     * @return array<string, int>
     */
    private function runCleanup(): array
    {
        $settingService = $this->createMock(SettingService::class);
        $settingService->method('getAutomationConfig')->willReturn([
            'pending_order_cleanup_enabled' => true,
            'pending_order_cleanup_after_hours' => 1,
            'pending_recharge_cleanup_enabled' => false,
            'pending_recharge_cleanup_after_days' => 0,
        ]);

        return (new InvoiceCleanupAutomationService(
            $settingService,
            app(CheckoutService::class),
            app(PaymentService::class),
            app(OrderService::class),
        ))->handle();
    }
}
