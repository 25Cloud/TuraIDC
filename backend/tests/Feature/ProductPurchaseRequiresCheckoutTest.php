<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Exceptions\BusinessException;
use App\Models\Invoice;
use App\Models\Product;
use App\Models\User;
use App\Services\Finance\AdminOrderNotificationService;
use App\Services\Finance\CheckoutSecurityService;
use App\Services\Finance\CheckoutService;
use App\Services\Finance\CouponService;
use App\Services\Finance\InvoiceService;
use App\Services\Finance\PaymentService;
use App\Services\ProductCatalog\ProductCatalogService;
use App\Services\System\OperationLogService;
use Tests\TestCase;

class ProductPurchaseRequiresCheckoutTest extends TestCase
{
    public function test_order_creation_requires_verified_user_when_product_demands_verification(): void
    {
        $suffix = bin2hex(random_bytes(4));
        $user = User::query()->create([
            'email' => "purchase-verify-{$suffix}@example.com",
            'password' => 'secret123',
            'phone' => '139'.str_pad((string) random_int(0, 99999999), 8, '0', STR_PAD_LEFT),
            'status' => 1,
            'is_verified' => 0,
        ]);

        $product = Product::query()->create([
            'name' => '实名校验商品',
            'product_type' => 'server',
            'pricing' => ['monthly' => '99.00'],
            'setup_fee' => '0.00',
            'config_options' => [],
            'purchase_requires' => ['require_verification' => true],
            'stock' => 10,
            'status' => 1,
            'auto_setup' => 0,
        ]);

        $checkoutService = $this->makeCheckoutService($product, 1);
        $checkoutSecurity = new CheckoutSecurityService;
        $quote = $checkoutService->quote($product, 'monthly', [], 1);
        $quotePayload = array_merge($quote, [
            'subtotal_amount' => $quote['total_amount'],
        ]);
        $tokenData = $checkoutSecurity->issueQuoteToken($product->id, 'monthly', [], $quotePayload);

        try {
            $checkoutService->create($user->id, [
                'product_id' => (int) $product->id,
                'billing_cycle' => 'monthly',
                'quantity' => 1,
                'config' => [],
                'quote_token' => (string) ($tokenData['quote_token'] ?? ''),
            ], [
                'idempotency_key' => 'purchase-verify-'.$suffix,
                'trace_id' => 'trace-purchase-verify-'.$suffix,
            ]);

            $this->fail('Expected BusinessException was not thrown.');
        } catch (BusinessException $exception) {
            $this->assertSame(40301, $exception->getErrorCode());
            $this->assertStringContainsString('实名认证', $exception->getMessage());
        }
    }

    public function test_order_creation_requires_phone_when_product_demands_phone_binding(): void
    {
        $suffix = bin2hex(random_bytes(4));
        User::withTrashed()->where('phone', '')->forceDelete();
        $user = User::query()->create([
            'email' => "purchase-phone-{$suffix}@example.com",
            'password' => 'secret123',
            'phone' => '',
            'status' => 1,
            'is_verified' => 1,
        ]);

        $product = Product::query()->create([
            'name' => 'Phone Binding Product',
            'product_type' => 'server',
            'pricing' => ['monthly' => '99.00'],
            'setup_fee' => '0.00',
            'config_options' => [],
            'purchase_requires' => ['require_phone' => true],
            'stock' => 10,
            'status' => 1,
            'auto_setup' => 0,
        ]);

        $checkoutService = $this->makeCheckoutService($product, 1);
        $checkoutSecurity = new CheckoutSecurityService;
        $quote = $checkoutService->quote($product, 'monthly', [], 1);
        $quotePayload = array_merge($quote, [
            'subtotal_amount' => $quote['total_amount'],
        ]);
        $tokenData = $checkoutSecurity->issueQuoteToken($product->id, 'monthly', [], $quotePayload);

        try {
            $checkoutService->create($user->id, [
                'product_id' => (int) $product->id,
                'billing_cycle' => 'monthly',
                'quantity' => 1,
                'config' => [],
                'quote_token' => (string) ($tokenData['quote_token'] ?? ''),
            ], [
                'idempotency_key' => 'purchase-phone-'.$suffix,
                'trace_id' => 'trace-purchase-phone-'.$suffix,
            ]);

            $this->fail('Expected BusinessException was not thrown.');
        } catch (BusinessException $exception) {
            $this->assertSame(40302, $exception->getErrorCode());
            $this->assertStringContainsString('手机号', $exception->getMessage());
        } finally {
            User::withTrashed()->whereKey((int) $user->id)->forceDelete();
        }
    }

    /**
     * 商品未开启 require_verification 时，未实名用户也应当能够下单。
     *
     * 实名是按商品维度的开关（管理端可配、官网据此渲染购买条件），不是平台级硬性门禁——
     * 本系统同时存在非中国大陆主体的部署场景。面向大陆主体的部署应在商品上开启该开关。
     */
    public function test_order_creation_allows_unverified_user_when_product_does_not_demand_verification(): void
    {
        $suffix = bin2hex(random_bytes(4));
        $user = User::query()->create([
            'email' => "purchase-no-verify-{$suffix}@example.com",
            'password' => 'secret123',
            'phone' => '139'.str_pad((string) random_int(0, 99999999), 8, '0', STR_PAD_LEFT),
            'status' => 1,
            'is_verified' => 0,
            'verification_status' => 0,
        ]);

        $product = Product::query()->create([
            'name' => '无需实名的商品',
            'product_type' => 'server',
            'pricing' => ['monthly' => '99.00'],
            'setup_fee' => '0.00',
            'config_options' => [],
            'purchase_requires' => [],
            'stock' => 10,
            'status' => 1,
            'auto_setup' => 0,
        ]);

        $checkoutService = $this->makeCheckoutService($product, 1);
        $checkoutSecurity = new CheckoutSecurityService;
        $quote = $checkoutService->quote($product, 'monthly', [], 1);
        $quotePayload = array_merge($quote, [
            'subtotal_amount' => $quote['total_amount'],
        ]);
        $tokenData = $checkoutSecurity->issueQuoteToken($product->id, 'monthly', [], $quotePayload);

        $invoice = $checkoutService->create($user->id, [
            'product_id' => (int) $product->id,
            'billing_cycle' => 'monthly',
            'quantity' => 1,
            'config' => [],
            'quote_token' => (string) ($tokenData['quote_token'] ?? ''),
        ], [
            'idempotency_key' => 'purchase-no-verify-'.$suffix,
            'trace_id' => 'trace-purchase-no-verify-'.$suffix,
        ]);

        $this->assertInstanceOf(Invoice::class, $invoice);
        $this->assertSame((int) $user->id, (int) $invoice->user_id);
    }

    private function makeCheckoutService(Product $product, int $expectedQuantity): CheckoutService
    {
        $invoiceService = new InvoiceService;
        $checkoutSecurity = new CheckoutSecurityService;

        $productCatalogService = $this->createMock(ProductCatalogService::class);
        $productCatalogService->expects($this->once())
            ->method('assertProductCanBeProvisioned')
            ->with(
                $this->callback(fn (Product $candidate): bool => (int) $candidate->id === (int) $product->id),
                $expectedQuantity
            );

        $couponService = $this->createMock(CouponService::class);
        $couponService->method('reserveOwnedCouponForInvoice')->willReturn([]);

        $operationLogService = $this->createMock(OperationLogService::class);
        $operationLogService->method('write');

        $adminOrderNotificationService = $this->createMock(AdminOrderNotificationService::class);
        $adminOrderNotificationService->method('notifyInvoicePaidAfterResponse');

        return new CheckoutService(
            $invoiceService,
            $this->createMock(PaymentService::class),
            $productCatalogService,
            $checkoutSecurity,
            $couponService,
            $operationLogService,
            $adminOrderNotificationService,
        );
    }
}
