<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Constants\InvoiceStatus;
use App\Constants\OrderStatus;
use App\Constants\PaymentStatus;
use App\Models\AdminUser;
use App\Models\AutomationLog;
use App\Models\Invoice;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Role;
use App\Models\User;
use App\Services\Finance\AdminOrderNotificationService;
use App\Services\System\NotificationService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class AdminInvoiceNotificationRegressionTest extends TestCase
{
    private function mirrorUserToIdc(User $user, string $suffix): void
    {
        DB::connection()->table('users')->updateOrInsert(
            ['id' => (int) $user->id],
            [
                'email' => $user->email,
                'phone' => $user->phone,
                'password' => Hash::make('secret123'),
                'status' => 1,
                'referral_code' => 'N'.strtoupper(substr(md5($suffix.'-'.$user->id), 0, 8)),
                'referrer_user_id' => null,
                'member_level_id' => null,
                'login_email_alert' => 1,
                'login_notify' => 1,
                'login_location_alert' => 1,
                'password_change_alert' => 1,
                'phone_change_alert' => 1,
                'email_change_alert' => 1,
                'marketing_alert' => 0,
                'is_verified' => 0,
                'verification_status' => 0,
                'verification_message' => '',
                'verification_certify_id' => null,
                'referred_at' => null,
                'verified_at' => null,
                'last_login_ip' => null,
                'last_login_at' => null,
                'deleted_at' => null,
                'created_at' => now(),
                'updated_at' => now(),
            ]
        );
    }

    public function test_invoice_paid_notification_works_without_order_binding(): void
    {
        $suffix = strtoupper(bin2hex(random_bytes(4)));

        $role = Role::query()->create([
            'name' => 'finance-'.$suffix,
            'label' => 'Finance',
            'permissions' => ['order.list', 'invoice.detail'],
        ]);

        $admin = AdminUser::query()->create([
            'username' => 'invoice-admin-'.$suffix,
            'password' => 'secret123',
            'nickname' => 'Invoice Admin',
            'email' => 'invoice-admin-'.$suffix.'@example.com',
            'role_id' => (int) $role->id,
            'status' => 1,
        ]);

        $user = User::query()->create([
            'email' => 'invoice-user-'.$suffix.'@example.com',
            'password' => 'secret123',
            'phone' => '13'.str_pad((string) random_int(0, 999999999), 9, '0', STR_PAD_LEFT),
            'status' => 1,
            'nickname' => 'Invoice User',
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
        $this->mirrorUserToIdc($user, $suffix);

        $invoice = Invoice::query()->create([
            'invoice_no' => 'INVNOTICE'.$suffix,
            'user_id' => (int) $user->id,
            'order_id' => null,
            'type' => 'renew',
            'product_spec_snapshot' => '通知测试独立实例',
            'amount' => '18.00',
            'paid_amount' => '18.00',
            'billing_cycle' => 'monthly',
            'status' => InvoiceStatus::PAID,
            'paid_at' => now()->subMinute(),
            'due_date' => now()->addDay(),
        ]);

        $payment = Payment::query()->create([
            'payment_no' => 'PAYNOTICE'.$suffix,
            'user_id' => (int) $user->id,
            'order_id' => null,
            'invoice_id' => (int) $invoice->id,
            'gateway' => 'alipay',
            'trade_no' => 'TRADENOTICE'.$suffix,
            'amount' => '18.00',
            'status' => PaymentStatus::SUCCESS,
            'paid_at' => now()->subMinute(),
        ]);

        $capturedPayload = null;
        $notificationService = $this->createMock(NotificationService::class);
        $notificationService->method('sendTemplateEmail')
            ->willReturnCallback(function (string $to, string $templateCode, array $payload) use ($admin, $invoice, $payment, &$capturedPayload): void {
                if ($to !== (string) $admin->email) {
                    return;
                }

                if ($templateCode !== NotificationService::TEMPLATE_ADMIN_ORDER_PAID) {
                    return;
                }

                if (($payload['order_no'] ?? '') !== (string) $invoice->invoice_no) {
                    return;
                }

                if (($payload['invoice_no'] ?? '') !== (string) $invoice->invoice_no) {
                    return;
                }

                if (($payload['product_name'] ?? '') !== '通知测试独立实例') {
                    return;
                }

                if (($payload['payment_method'] ?? '') !== '支付宝') {
                    return;
                }

                if (($payload['trade_no'] ?? '') !== (string) $payment->trade_no) {
                    return;
                }

                $capturedPayload = $payload;
            });

        $service = new AdminOrderNotificationService($notificationService);
        $service->notifyInvoicePaidAfterResponse($invoice);

        $this->assertIsArray($capturedPayload);
        $this->assertSame((string) $invoice->invoice_no, (string) ($capturedPayload['order_no'] ?? ''));
        $this->assertSame('通知测试独立实例', (string) ($capturedPayload['product_name'] ?? ''));

        $this->assertDatabaseHas('automation_logs', [
            'task_key' => 'admin-order-notification',
            'action' => 'invoice_paid',
            'object_type' => 'invoice',
            'object_id' => (int) $invoice->id,
            'rule_key' => 'admin:'.(int) $admin->id,
        ]);

        $this->assertSame(
            1,
            AutomationLog::query()
                ->where('task_key', 'admin-order-notification')
                ->where('action', 'invoice_paid')
                ->where('object_type', 'invoice')
                ->where('object_id', (int) $invoice->id)
                ->where('rule_key', 'admin:'.(int) $admin->id)
                ->count()
        );
    }

    public function test_legacy_order_created_notification_bridges_to_bound_invoice_snapshot(): void
    {
        $suffix = strtoupper(bin2hex(random_bytes(4)));

        $role = Role::query()->create([
            'name' => 'finance-created-'.$suffix,
            'label' => 'Finance Created',
            'permissions' => ['invoice.list'],
        ]);

        $admin = AdminUser::query()->create([
            'username' => 'invoice-created-admin-'.$suffix,
            'password' => 'secret123',
            'nickname' => 'Invoice Created Admin',
            'email' => 'invoice-created-admin-'.$suffix.'@example.com',
            'role_id' => (int) $role->id,
            'status' => 1,
        ]);

        $user = User::query()->create([
            'email' => 'invoice-created-user-'.$suffix.'@example.com',
            'password' => 'secret123',
            'phone' => '13'.str_pad((string) random_int(0, 999999999), 9, '0', STR_PAD_LEFT),
            'status' => 1,
            'nickname' => 'Invoice Created User',
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
        $this->mirrorUserToIdc($user, $suffix);

        $order = Order::query()->create([
            'order_no' => 'ORDLEGACYNOTICE'.$suffix,
            'user_id' => (int) $user->id,
            'type' => 'new',
            'amount' => '66.00',
            'discount' => '0.00',
            'paid_amount' => '0.00',
            'billing_cycle' => 'monthly',
            'status' => OrderStatus::PENDING,
            'product_spec_snapshot' => '旧订单展示名不应作为通知真相',
        ]);

        $invoice = Invoice::query()->create([
            'invoice_no' => 'INVLEGACYNOTICE'.$suffix,
            'user_id' => (int) $user->id,
            'order_id' => (int) $order->id,
            'type' => 'normal',
            'amount' => '66.00',
            'paid_amount' => '0.00',
            'billing_cycle' => 'monthly',
            'status' => InvoiceStatus::UNPAID,
            'product_snapshot_json' => [
                'order_id' => (int) $order->id,
                'order_no' => 'SNAP-ORDER-'.$suffix,
                'product_name' => '账单快照配置名',
                'product_spec_snapshot' => '账单快照配置名',
            ],
            'due_date' => now()->addDay(),
        ]);

        $capturedPayload = null;
        $notificationService = $this->createMock(NotificationService::class);
        $notificationService->method('sendTemplateEmail')
            ->willReturnCallback(function (string $to, string $templateCode, array $payload) use ($admin, &$capturedPayload): void {
                if ($to !== (string) $admin->email) {
                    return;
                }

                if ($templateCode !== NotificationService::TEMPLATE_ADMIN_ORDER_CREATED) {
                    return;
                }

                $capturedPayload = $payload;
            });

        $service = new AdminOrderNotificationService($notificationService);
        $service->notifyOrderCreated($order);

        $this->assertIsArray($capturedPayload);
        $this->assertSame((string) $invoice->invoice_no, (string) ($capturedPayload['invoice_no'] ?? ''));
        $this->assertSame('SNAP-ORDER-'.$suffix, (string) ($capturedPayload['order_no'] ?? ''));
        $this->assertSame('账单快照配置名', (string) ($capturedPayload['product_name'] ?? ''));

        $this->assertDatabaseHas('automation_logs', [
            'task_key' => 'admin-order-notification',
            'action' => 'invoice_created',
            'object_type' => 'invoice',
            'object_id' => (int) $invoice->id,
            'rule_key' => 'admin:'.(int) $admin->id,
        ]);
    }
}
