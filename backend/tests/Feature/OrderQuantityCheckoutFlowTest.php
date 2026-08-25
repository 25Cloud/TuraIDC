<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Constants\InvoiceStatus;
use App\Constants\OrderStatus;
use App\Exceptions\BusinessException;
use App\Models\Invoice;
use App\Models\Order;
use App\Models\Product;
use App\Models\Service;
use App\Models\Setting;
use App\Models\User;
use App\Services\Finance\AdminOrderNotificationService;
use App\Services\Finance\CheckoutSecurityService;
use App\Services\Finance\CheckoutService;
use App\Services\Finance\CouponService;
use App\Services\Finance\InvoiceService;
use App\Services\Finance\PaymentService;
use App\Services\Order\PaidOrderBusinessFlowDispatcher;
use App\Services\ProductCatalog\ProductCatalogService;
use App\Services\Provisioning\ProvisionService;
use App\Services\Provisioning\ServiceRenewService;
use App\Services\Referral\ReferralService;
use App\Services\System\OperationLogService;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class OrderQuantityCheckoutFlowTest extends TestCase
{
    public function test_invoice_creation_api_rejects_multiple_quantity_before_checkout(): void
    {
        $suffix = bin2hex(random_bytes(4));
        $user = User::query()->create([
            'email' => "order-quantity-api-{$suffix}@example.com",
            'password' => 'secret123',
            'phone' => '137'.str_pad((string) random_int(0, 99999999), 8, '0', STR_PAD_LEFT),
            'status' => 1,
            'is_verified' => 1,
            'verification_status' => 2,
        ]);

        Sanctum::actingAs($user);

        $this->postJson('/api/v2/client/invoices', [
            'product_id' => 1,
            'billing_cycle' => 'monthly',
            'quantity' => 2,
            'quote_token' => str_repeat('q', 20),
        ])
            ->assertUnprocessable()
            ->assertJsonPath('code', 42200)
            ->assertJsonStructure(['data' => ['errors' => ['quantity']]]);
    }

    public function test_checkout_rejects_multiple_quantity_to_prevent_partial_provisioning(): void
    {
        $suffix = bin2hex(random_bytes(4));
        $user = User::query()->create([
            'email' => "order-quantity-{$suffix}@example.com",
            'password' => 'secret123',
            'phone' => '139'.str_pad((string) random_int(0, 99999999), 8, '0', STR_PAD_LEFT),
            'status' => 1,
            'is_verified' => 1,
            'verification_status' => 2,
        ]);

        $product = Product::query()->create([
            'name' => 'Bulk VPS Plan',
            'product_type' => 'server',
            'pricing' => ['monthly' => '99.00'],
            'setup_fee' => '10.00',
            'config_options' => [],
            'purchase_requires' => [],
            'stock' => 10,
            'status' => 1,
            'auto_setup' => 0,
        ]);
        $checkoutSecurity = new CheckoutSecurityService;
        $invoiceService = new InvoiceService;

        $productCatalogService = $this->createMock(ProductCatalogService::class);
        $productCatalogService->expects($this->never())
            ->method('assertProductCanBeProvisioned');

        $couponService = $this->createMock(CouponService::class);
        $couponService->method('reserveOwnedCouponForInvoice')->willReturn([]);

        $operationLogService = $this->createMock(OperationLogService::class);
        $operationLogService->method('write');

        $adminOrderNotificationService = $this->createMock(AdminOrderNotificationService::class);
        $adminOrderNotificationService->method('notifyInvoicePaidAfterResponse');

        $checkoutService = new CheckoutService(
            $invoiceService,
            $this->createMock(PaymentService::class),
            $productCatalogService,
            $checkoutSecurity,
            $couponService,
            $operationLogService,
            $adminOrderNotificationService,
        );

        $quote = $checkoutService->quote($product, 'monthly', [], 2);
        $quotePayload = array_merge($quote, [
            'subtotal_amount' => $quote['total_amount'],
        ]);
        $tokenData = $checkoutSecurity->issueQuoteToken($product->id, 'monthly', [], $quotePayload);

        try {
            $checkoutService->create($user->id, [
                'product_id' => (int) $product->id,
                'billing_cycle' => 'monthly',
                'quantity' => 2,
                'config' => [],
                'quote_token' => (string) ($tokenData['quote_token'] ?? ''),
            ], [
                'idempotency_key' => 'order-quantity-'.$suffix,
                'trace_id' => 'trace-order-quantity-'.$suffix,
            ]);

            $this->fail('多数量结算必须被拒绝');
        } catch (BusinessException $exception) {
            $this->assertSame('当前暂不支持一次购买多个服务实例，请分次下单', $exception->getMessage());
        }

        $this->assertDatabaseMissing('invoices', ['user_id' => (int) $user->id]);
        $this->assertDatabaseMissing('orders', ['user_id' => (int) $user->id]);
    }

    public function test_invoice_checkout_persists_full_instance_spec_snapshot(): void
    {
        $suffix = bin2hex(random_bytes(4));
        $user = User::query()->create([
            'email' => "invoice-spec-{$suffix}@example.com",
            'password' => 'secret123',
            'phone' => '136'.str_pad((string) random_int(0, 99999999), 8, '0', STR_PAD_LEFT),
            'status' => 1,
            'is_verified' => 1,
            'verification_status' => 2,
        ]);

        $product = Product::query()->create([
            'name' => '西安云电脑 A型',
            'remark' => '通用NAT',
            'product_type' => 'server',
            'pricing' => ['monthly' => '5.00'],
            'setup_fee' => '0.00',
            'config_options' => [
                [
                    'field' => 'cpu',
                    'option_type' => 6,
                    'sub' => [
                        ['id' => '2', 'option_name_first' => '2', 'option_name' => '2核', 'hidden' => 0],
                    ],
                ],
                [
                    'field' => 'memory',
                    'option_type' => 8,
                    'sub' => [
                        ['id' => '1024', 'option_name_first' => '1024', 'option_name' => '1G', 'hidden' => 0],
                    ],
                ],
            ],
            'purchase_requires' => [],
            'stock' => 10,
            'status' => 1,
            'auto_setup' => 0,
        ]);
        Setting::setValue('product', 'instance_spec_catalog', json_encode([
            [
                'id' => 'spec_nat_2c1g',
                'value' => 'nat_2c1g',
                'text' => '通用NAT',
                'status' => '展示中',
                'bindings' => [
                    [
                        'product_id' => (int) $product->id,
                        'status' => 1,
                    ],
                ],
            ],
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

        $checkoutSecurity = new CheckoutSecurityService;
        $invoiceService = new InvoiceService;

        $productCatalogService = $this->createMock(ProductCatalogService::class);
        $productCatalogService->method('assertProductCanBeProvisioned');

        $couponService = $this->createMock(CouponService::class);
        $couponService->method('reserveOwnedCouponForInvoice')->willReturn([]);

        $operationLogService = $this->createMock(OperationLogService::class);
        $operationLogService->method('write');

        $adminOrderNotificationService = $this->createMock(AdminOrderNotificationService::class);
        $adminOrderNotificationService->method('notifyInvoicePaidAfterResponse');

        $checkoutService = new CheckoutService(
            $invoiceService,
            $this->createMock(PaymentService::class),
            $productCatalogService,
            $checkoutSecurity,
            $couponService,
            $operationLogService,
            $adminOrderNotificationService,
        );

        $config = [
            'cpu' => '2',
            'memory' => '1024',
        ];
        $quote = $checkoutService->quote($product, 'monthly', $config, 1);
        $tokenData = $checkoutSecurity->issueQuoteToken($product->id, 'monthly', $config, $quote);

        $invoice = $checkoutService->create($user->id, [
            'product_id' => (int) $product->id,
            'billing_cycle' => 'monthly',
            'quantity' => 1,
            'config' => $config,
            'quote_token' => (string) ($tokenData['quote_token'] ?? ''),
        ], [
            'idempotency_key' => 'invoice-spec-'.$suffix,
            'trace_id' => 'trace-invoice-spec-'.$suffix,
        ]);

        $invoice->refresh()->load('order');

        $this->assertSame('通用NAT-2vcpu-1gib', (string) $invoice->product_spec_snapshot);
        $this->assertSame('通用NAT-2vcpu-1gib', (string) $invoice->order?->product_spec_snapshot);
    }

    public function test_quote_token_and_fingerprint_include_quantity(): void
    {
        $service = new CheckoutSecurityService;
        $quotePayload = [
            'quantity' => 2,
            'subtotal_amount' => '198.00',
            'total_amount' => '198.00',
            'base_amount' => '198.00',
            'config_amount' => '0.00',
            'setup_fee' => '0.00',
            'discount_amount' => '0.00',
        ];

        $token = $service->issueQuoteToken(10, 'monthly', [], $quotePayload);

        $service->assertQuoteToken(
            (string) ($token['quote_token'] ?? ''),
            10,
            'monthly',
            2,
            [],
            '198.00',
            '198.00',
            0,
        );

        $this->assertNotSame(
            $service->buildCheckoutFingerprint(10, 'monthly', 1, [], 0),
            $service->buildCheckoutFingerprint(10, 'monthly', 2, [], 0),
        );
    }

    public function test_process_paid_order_fulfillment_skips_missing_referral_profile_table(): void
    {
        $suffix = bin2hex(random_bytes(4));
        $user = User::query()->create([
            'email' => "order-paid-flow-{$suffix}@example.com",
            'password' => 'secret123',
            'phone' => '138'.str_pad((string) random_int(0, 99999999), 8, '0', STR_PAD_LEFT),
            'status' => 1,
        ]);

        $product = Product::query()->create([
            'name' => 'Auto Provision Test Host',
            'product_type' => 'server',
            'pricing' => ['monthly' => '9.90'],
            'setup_fee' => '0.00',
            'config_options' => [],
            'purchase_requires' => [],
            'stock' => -1,
            'status' => 1,
            'auto_setup' => 1,
        ]);

        $order = Order::query()->create([
            'order_no' => 'ORDPAIDFLOW'.strtoupper($suffix),
            'user_id' => (int) $user->id,
            'product_id' => (int) $product->id,
            'product_spec_snapshot' => '未配置规格 #'.(int) $product->id,
            'product_type_snapshot' => (string) $product->product_type,
            'type' => 'new',
            'amount' => '9.90',
            'discount' => '0.00',
            'paid_amount' => '9.90',
            'billing_cycle' => 'monthly',
            'quantity' => 1,
            'config_snapshot' => [],
            'config_pricing_snapshot' => [],
            'status' => 1,
            'paid_at' => now(),
        ]);

        Invoice::query()->create([
            'invoice_no' => 'INVPAIDFLOW'.strtoupper($suffix),
            'user_id' => (int) $user->id,
            'order_id' => (int) $order->id,
            'type' => 'new',
            'amount' => '9.90',
            'paid_amount' => '9.90',
            'status' => 1,
            'paid_at' => now(),
            'due_date' => now()->addDay(),
        ]);

        $provisionService = $this->createMock(ProvisionService::class);
        $provisionService->expects($this->once())
            ->method('processPaidOrder')
            ->with($this->callback(fn (Order $candidate): bool => (int) $candidate->id === (int) $order->id));

        $paymentService = new PaymentService(
            $provisionService,
            $this->makePaymentGatewayManagerForTest(),
            $this->createMock(ServiceRenewService::class),
            $this->createMock(ReferralService::class),
            $this->createMock(PaidOrderBusinessFlowDispatcher::class),
            $this->createMock(AdminOrderNotificationService::class),
            $this->createMock(CouponService::class),
            new InvoiceService,
        );

        $paymentService->processPaidOrderFulfillmentById((int) $order->id);

        $this->assertDatabaseHas('orders', [
            'id' => (int) $order->id,
            'status' => 1,
        ]);
    }

    public function test_handle_paid_invoice_processes_renew_orders_synchronously(): void
    {
        $suffix = bin2hex(random_bytes(4));
        $user = User::query()->create([
            'email' => "order-renew-sync-{$suffix}@example.com",
            'password' => 'secret123',
            'phone' => '137'.str_pad((string) random_int(0, 99999999), 8, '0', STR_PAD_LEFT),
            'status' => 1,
        ]);

        $product = Product::query()->create([
            'name' => '续费同步测试商品',
            'product_type' => 'server',
            'pricing' => ['monthly' => '16.00'],
            'setup_fee' => '0.00',
            'config_options' => [],
            'purchase_requires' => [],
            'stock' => -1,
            'status' => 1,
            'auto_setup' => 1,
        ]);

        $order = Order::query()->create([
            'order_no' => 'ORDRENEWSYNC'.strtoupper($suffix),
            'user_id' => (int) $user->id,
            'product_id' => (int) $product->id,
            'product_spec_snapshot' => '未配置规格 #'.(int) $product->id,
            'product_type_snapshot' => (string) $product->product_type,
            'type' => 'renew',
            'amount' => '16.00',
            'discount' => '0.00',
            'paid_amount' => '16.00',
            'billing_cycle' => 'monthly',
            'quantity' => 1,
            'config_snapshot' => [],
            'config_pricing_snapshot' => [],
            'status' => OrderStatus::PAID,
            'paid_at' => now(),
        ]);

        $service = Service::query()->create([
            'user_id' => (int) $user->id,
            'product_id' => (int) $product->id,
            'order_id' => (int) $order->id,
            'name' => 'Renew Sync Test Service',
            'domain' => 'renew-sync.example.com',
            'billing_cycle' => 'monthly',
            'amount' => '16.00',
            'status' => 1,
            'provision_data' => [
                'supplier_id' => '1',
                'upstream_host_id' => 58376,
            ],
            'expires_at' => now()->addMonth(),
            'auto_renew' => 0,
        ]);

        $order->forceFill([
            'service_id' => (int) $service->id,
        ])->save();

        $invoice = Invoice::query()->create([
            'invoice_no' => 'INVRENEWSYNC'.strtoupper($suffix),
            'user_id' => (int) $user->id,
            'order_id' => (int) $order->id,
            'type' => 'renew',
            'amount' => '16.00',
            'paid_amount' => '16.00',
            'status' => InvoiceStatus::PAID,
            'paid_at' => now(),
            'due_date' => now()->addDay(),
        ]);

        $serviceRenewService = $this->createMock(ServiceRenewService::class);

        $dispatcher = $this->createMock(PaidOrderBusinessFlowDispatcher::class);
        $dispatcher->expects($this->once())
            ->method('dispatchPaidInvoice')
            ->with(
                $this->callback(fn (Invoice $candidate): bool => (int) $candidate->id === (int) $invoice->id),
                'trace-renew-sync-'.$suffix
            );

        $couponService = $this->createMock(CouponService::class);
        $couponService->expects($this->once())
            ->method('syncInvoiceCouponUsageAfterResponse')
            ->with($this->callback(fn (Invoice $candidate): bool => (int) $candidate->id === (int) $invoice->id));

        $adminOrderNotificationService = $this->createMock(AdminOrderNotificationService::class);
        $adminOrderNotificationService->expects($this->once())
            ->method('notifyInvoicePaidAfterResponse')
            ->with($this->callback(fn (Invoice $candidate): bool => (int) $candidate->id === (int) $invoice->id));

        $paymentService = new PaymentService(
            $this->createMock(ProvisionService::class),
            $this->makePaymentGatewayManagerForTest(),
            $serviceRenewService,
            $this->createMock(ReferralService::class),
            $dispatcher,
            $adminOrderNotificationService,
            $couponService,
            new InvoiceService,
        );

        $paymentService->handlePaidInvoice($invoice, 'trace-renew-sync-'.$suffix);
    }

    /**
     * 商品开启 require_verification 时，未实名用户在下单流程中应被拦截。
     *
     * 实名是按商品维度的开关，未开启的商品不拦截（覆盖见 ProductPurchaseRequiresCheckoutTest）。
     */
    public function test_checkout_service_requires_verified_user_for_new_purchase_when_product_flag_enabled(): void
    {
        $suffix = bin2hex(random_bytes(4));

        $user = User::query()->create([
            'email' => 'invoice-verify-'.$suffix.'@example.com',
            'password' => 'Temp@123456',
            'phone' => '13'.str_pad((string) random_int(0, 999999999), 9, '0', STR_PAD_LEFT),
            'status' => 1,
            'nickname' => 'Invoice Verify',
            'is_verified' => 0,
            'verification_status' => 0,
        ]);

        $product = Product::query()->create([
            'name' => 'Invoice Verify Product '.$suffix,
            'product_type' => 'server',
            'pricing' => ['monthly' => '66.00'],
            'setup_fee' => '0.00',
            'config_options' => [],
            'purchase_requires' => ['require_verification' => true],
            'stock' => 8,
            'status' => 1,
            'sort_order' => 0,
            'provision_module' => null,
            'auto_setup' => 0,
        ]);

        $checkoutSecurity = new CheckoutSecurityService;
        $invoiceService = new InvoiceService;

        $productCatalogService = $this->createMock(ProductCatalogService::class);
        $productCatalogService->method('assertProductCanBeProvisioned');

        $couponService = $this->createMock(CouponService::class);
        $couponService->method('reserveOwnedCouponForInvoice')->willReturn([]);

        $operationLogService = $this->createMock(OperationLogService::class);
        $operationLogService->method('write');

        $adminOrderNotificationService = $this->createMock(AdminOrderNotificationService::class);
        $adminOrderNotificationService->method('notifyInvoicePaidAfterResponse');

        $checkoutService = new CheckoutService(
            $invoiceService,
            $this->createMock(PaymentService::class),
            $productCatalogService,
            $checkoutSecurity,
            $couponService,
            $operationLogService,
            $adminOrderNotificationService,
        );

        $quote = $checkoutService->quote($product, 'monthly', [], 1);
        $tokenData = $checkoutSecurity->issueQuoteToken($product->id, 'monthly', [], $quote);

        try {
            $checkoutService->create($user->id, [
                'product_id' => (int) $product->id,
                'billing_cycle' => 'monthly',
                'quantity' => 1,
                'config' => [],
                'quote_token' => (string) ($tokenData['quote_token'] ?? ''),
            ], [
                'idempotency_key' => 'invoice-verify-'.$suffix,
                'trace_id' => 'trace-invoice-verify-'.$suffix,
            ]);

            $this->fail('Expected BusinessException was not thrown.');
        } catch (BusinessException $exception) {
            $this->assertSame(40301, $exception->getErrorCode());
            $this->assertStringContainsString('实名认证', $exception->getMessage());
        }
    }
}
