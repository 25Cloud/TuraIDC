<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Constants\InvoiceStatus;
use App\Constants\OrderStatus;
use App\Constants\PaymentStatus;
use App\Constants\ServiceStatus;
use App\Constants\UserCouponStatus;
use App\Models\Coupon;
use App\Models\Invoice;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Product;
use App\Models\Service;
use App\Models\User;
use App\Models\UserCoupon;
use App\Services\Finance\AdminOrderNotificationService;
use App\Services\Finance\CheckoutSecurityService;
use App\Services\Finance\CouponService;
use App\Services\Finance\InvoiceService;
use App\Services\Finance\PaymentService;
use App\Services\ProductCatalog\ProductCatalogService;
use App\Services\Provisioning\ServiceRenewService;
use App\Services\System\NotificationService;
use App\Services\System\OperationLogService;
use App\Services\System\SettingService;
use App\Services\Upstream\Drivers\HostingPanelApi\HostingPanelApiDriver;
use App\Services\Upstream\Drivers\HostingPanelApi\HostingPanelApiTransport;
use App\Services\Upstream\ProviderRegistry;
use App\Services\Upstream\ProviderResolver;
use Tests\TestCase;

class ServiceRenewCouponRegressionTest extends TestCase
{
    public function test_it_cancels_stale_pending_renew_invoice_before_creating_coupon_invoice(): void
    {
        $suffix = bin2hex(random_bytes(4));
        $couponCode = 'CPNRENEW'.strtoupper($suffix);

        $user = User::query()->create([
            'email' => 'renew-coupon-'.$suffix.'@example.com',
            'password' => 'secret123',
            'phone' => '137'.str_pad((string) random_int(0, 99999999), 8, '0', STR_PAD_LEFT),
            'status' => 1,
        ]);

        $product = Product::query()->create([
            'name' => 'Renew Coupon Product',
            'product_type' => 'server',
            'pricing' => ['monthly' => '20.00'],
            'setup_fee' => '0.00',
            'config_options' => [],
            'purchase_requires' => [],
            'stock' => -1,
            'status' => 1,
            'auto_setup' => 0,
        ]);

        $service = Service::query()->create([
            'user_id' => (int) $user->id,
            'product_id' => (int) $product->id,
            'order_id' => null,
            'name' => 'Renewable Service',
            'domain' => 'renew-'.$suffix.'.example.com',
            'billing_cycle' => 'monthly',
            'amount' => '20.00',
            'status' => ServiceStatus::ACTIVE,
            'provision_data' => [],
            'expires_at' => now()->addMonth(),
            'auto_renew' => 0,
        ]);

        $coupon = Coupon::query()->create([
            'name' => 'Renew Coupon',
            'code' => $couponCode,
            'distribution_type' => 'public',
            'discount_scope' => 'renew',
            'discount_type' => 'fixed',
            'discount_value' => '5.00',
            'min_amount' => '0.00',
            'used_count' => 0,
            'status' => 1,
            'starts_at' => now()->subHour(),
            'expires_at' => now()->addDay(),
        ]);

        $userCoupon = UserCoupon::query()->create([
            'coupon_id' => (int) $coupon->id,
            'user_id' => (int) $user->id,
            'receive_type' => 'claim',
            'status' => UserCouponStatus::OWNED,
            'claimed_at' => now()->subHour(),
        ]);

        $existingOrder = Order::query()->create([
            'order_no' => 'RENEWOLD'.strtoupper($suffix),
            'user_id' => (int) $user->id,
            'product_id' => (int) $product->id,
            'product_spec_snapshot' => '未配置规格 #'.(int) $product->id,
            'product_type_snapshot' => (string) $product->product_type,
            'service_id' => (int) $service->id,
            'type' => 'renew',
            'coupon_id' => (int) $coupon->id,
            'user_coupon_id' => (int) $userCoupon->id,
            'coupon_code' => $couponCode,
            'amount' => '20.00',
            'discount' => '0.00',
            'paid_amount' => '0.00',
            'billing_cycle' => 'monthly',
            'config_snapshot' => [],
            'coupon_snapshot' => [],
            'status' => OrderStatus::PENDING,
        ]);

        $invoice = Invoice::query()->create([
            'invoice_no' => 'INVRENEW'.strtoupper($suffix),
            'user_id' => (int) $user->id,
            'order_id' => (int) $existingOrder->id,
            'service_id' => (int) $service->id,
            'type' => 'renew',
            'amount' => '20.00',
            'paid_amount' => '0.00',
            'billing_cycle' => 'monthly',
            'coupon_id' => (int) $coupon->id,
            'user_coupon_id' => (int) $userCoupon->id,
            'coupon_code' => $couponCode,
            'status' => InvoiceStatus::UNPAID,
            'due_date' => now()->addDay(),
        ]);

        $payment = Payment::query()->create([
            'payment_no' => Payment::generatePaymentNo(),
            'user_id' => (int) $user->id,
            'order_id' => (int) $existingOrder->id,
            'invoice_id' => (int) $invoice->id,
            'gateway' => 'alipay',
            'amount' => '20.00',
            'status' => PaymentStatus::PENDING,
        ]);

        $couponPayload = [
            'coupon_id' => (int) $coupon->id,
            'user_coupon_id' => (int) $userCoupon->id,
            'code' => $couponCode,
            'discount_amount' => '5.00',
        ];

        $couponService = $this->createMock(CouponService::class);
        // 续费建单在"事务外预判 + 服务行锁内重查"两阶段都会比对优惠金额，
        // 允许 previewOwnedCoupon 至少调用一次，适配既有两阶段复用检查。
        $couponService->expects($this->atLeastOnce())
            ->method('previewOwnedCoupon')
            ->with(
                (int) $userCoupon->id,
                (int) $user->id,
                $this->callback(fn (Product $candidate): bool => (int) $candidate->id === (int) $product->id),
                'monthly',
                20.0,
                'renew'
            )
            ->willReturn($couponPayload);
        $couponService->expects($this->once())
            ->method('reserveOwnedCouponForInvoice')
            ->with(
                (int) $userCoupon->id,
                (int) $user->id,
                $this->callback(fn (Product $candidate): bool => (int) $candidate->id === (int) $product->id),
                'monthly',
                20.0,
                'renew'
            )
            ->willReturn($couponPayload);
        $couponService->expects($this->once())
            ->method('releaseInvoiceCoupon')
            ->with($this->callback(fn (Invoice $candidate): bool => (int) $candidate->id === (int) $invoice->id));

        $paymentService = $this->createMock(PaymentService::class);
        $paymentService->expects($this->once())
            ->method('syncProjection')
            ->with($this->callback(fn (Payment $candidate): bool => (int) $candidate->id === (int) $payment->id));

        $operationLogService = $this->createMock(OperationLogService::class);
        $operationLogService->expects($this->once())->method('write');
        $operationLogService->expects($this->once())->method('writeServiceConsoleLog');

        $this->app->instance(CouponService::class, $couponService);
        $this->app->instance(PaymentService::class, $paymentService);
        $this->app->instance(InvoiceService::class, new InvoiceService);
        $this->app->instance(ProductCatalogService::class, $this->createMock(ProductCatalogService::class));
        $this->app->instance(CheckoutSecurityService::class, new CheckoutSecurityService);
        $this->app->instance(OperationLogService::class, $operationLogService);
        $this->app->instance(AdminOrderNotificationService::class, $this->createMock(AdminOrderNotificationService::class));

        $renewService = new ServiceRenewService(
            new InvoiceService,
            new ProviderResolver(
                new ProviderRegistry([
                    new HostingPanelApiDriver($this->createMock(HostingPanelApiTransport::class)),
                ])
            ),
            $couponService,
            $operationLogService,
            $this->createMock(SettingService::class),
            $this->createMock(NotificationService::class),
        );

        $newInvoice = $renewService->createRenewInvoiceForUser(
            $user,
            (int) $service->id,
            'monthly',
            (int) $userCoupon->id,
            [
                'actor_type' => 'client',
                'actor_user_id' => (int) $user->id,
                'actor_name' => (string) $user->email,
                'trace_id' => 'renew-coupon-'.$suffix,
            ]
        );

        $this->assertDatabaseHas('invoices', [
            'id' => (int) $invoice->id,
            'status' => InvoiceStatus::CANCELLED,
        ]);
        $this->assertDatabaseHas('payments', [
            'id' => (int) $payment->id,
            'status' => PaymentStatus::FAILED,
        ]);

        $this->assertNotSame((int) $invoice->id, (int) $newInvoice->id);
        $this->assertDatabaseHas('invoices', [
            'id' => (int) $newInvoice->id,
            'service_id' => (int) $service->id,
            'status' => InvoiceStatus::UNPAID,
            'coupon_id' => (int) $coupon->id,
            'user_coupon_id' => (int) $userCoupon->id,
            'discount' => '5.00',
            'amount' => '15.00',
        ]);
    }
}
