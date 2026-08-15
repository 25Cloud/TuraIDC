<?php

declare(strict_types=1);

namespace Tests\Feature\Order;

use App\Constants\InvoiceStatus;
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

/**
 * 库存预扣与恢复对称：
 * 下单仅对有限正库存（stock > 0）预扣，取消按创建时实际预扣量（stock_reserved）恢复，
 * 不能对无限库存（-1）或未预扣的订单误加库存。
 */
class CheckoutStockRestoreTest extends TestCase
{
    public function test_checkout_cancel_restores_reserved_stock_for_finite_stock(): void
    {
        $suffix = bin2hex(random_bytes(4));
        [$user, $product] = $this->createUserAndProduct($suffix, 2);
        $checkout = $this->makeCheckoutService();

        $invoice = $this->createCheckoutInvoice($checkout, $user, $product, $suffix);

        $this->assertSame(1, (int) $product->refresh()->stock);
        $this->assertSame(1, (int) data_get($invoice->config_snapshot, 'stock_reserved', -1));

        $checkout->cancel($invoice, [
            'actor_type' => 'client',
            'trace_id' => 'trace-stock-cancel-'.$suffix,
        ]);

        $this->assertSame(2, (int) $product->refresh()->stock);
        $this->assertSame(InvoiceStatus::CANCELLED, (int) $invoice->refresh()->status);
    }

    public function test_checkout_cancel_does_not_touch_unlimited_stock(): void
    {
        $suffix = bin2hex(random_bytes(4));
        [$user, $product] = $this->createUserAndProduct($suffix, -1);
        $checkout = $this->makeCheckoutService();

        $invoice = $this->createCheckoutInvoice($checkout, $user, $product, $suffix);

        $this->assertSame(-1, (int) $product->refresh()->stock);
        $this->assertSame(0, (int) data_get($invoice->config_snapshot, 'stock_reserved', -1));

        $checkout->cancel($invoice, [
            'actor_type' => 'client',
            'trace_id' => 'trace-stock-cancel-unlimited-'.$suffix,
        ]);

        $this->assertSame(-1, (int) $product->refresh()->stock);
    }

    public function test_checkout_cancel_restores_when_reserved_stock_hits_zero(): void
    {
        $suffix = bin2hex(random_bytes(4));
        // 库存恰好 1：预扣后降到 0，取消时必须按记录回补到 1（不能因当前库存为 0 而漏恢复）。
        [$user, $product] = $this->createUserAndProduct($suffix, 1);
        $checkout = $this->makeCheckoutService();

        $invoice = $this->createCheckoutInvoice($checkout, $user, $product, $suffix);

        $this->assertSame(0, (int) $product->refresh()->stock);
        $this->assertSame(1, (int) data_get($invoice->config_snapshot, 'stock_reserved', -1));

        $checkout->cancel($invoice, [
            'actor_type' => 'client',
            'trace_id' => 'trace-stock-cancel-zero-'.$suffix,
        ]);

        $this->assertSame(1, (int) $product->refresh()->stock);
    }

    public function test_legacy_order_without_stock_reserved_preserves_historical_restore(): void
    {
        $suffix = bin2hex(random_bytes(4));
        [$user, $product] = $this->createUserAndProduct($suffix, 3);
        $checkout = $this->makeCheckoutService();

        $invoice = $this->createCheckoutInvoice($checkout, $user, $product, $suffix);

        // 模拟历史数据：快照缺失 stock_reserved 字段时，按历史行为（有限库存 >= 0 才回补）。
        $invoice->forceFill(['config_snapshot' => ['legacy' => true]])->save();

        $checkout->cancel($invoice, [
            'actor_type' => 'client',
            'trace_id' => 'trace-stock-cancel-legacy-'.$suffix,
        ]);

        $this->assertSame(3, (int) $product->refresh()->stock);
    }

    private function createCheckoutInvoice(
        CheckoutService $checkout,
        User $user,
        Product $product,
        string $suffix,
    ): Invoice {
        $security = new CheckoutSecurityService;
        $quote = $checkout->quote($product, 'monthly', [], 1);
        $tokenData = $security->issueQuoteToken($product->id, 'monthly', [], $quote);

        return $checkout->create($user->id, [
            'product_id' => (int) $product->id,
            'billing_cycle' => 'monthly',
            'quantity' => 1,
            'config' => [],
            'quote_token' => (string) ($tokenData['quote_token'] ?? ''),
        ], [
            'idempotency_key' => 'stock-restore-'.$suffix,
            'trace_id' => 'trace-stock-'.$suffix,
        ]);
    }

    private function makeCheckoutService(): CheckoutService
    {
        $productCatalogService = $this->createMock(ProductCatalogService::class);
        $productCatalogService->method('assertProductCanBeProvisioned');

        $couponService = $this->createMock(CouponService::class);
        $couponService->method('reserveOwnedCouponForInvoice')->willReturn([]);
        $couponService->method('releaseInvoiceCoupon');

        $operationLogService = $this->createMock(OperationLogService::class);
        $operationLogService->method('write');

        $adminOrderNotificationService = $this->createMock(AdminOrderNotificationService::class);
        $adminOrderNotificationService->method('notifyInvoicePaidAfterResponse');

        return new CheckoutService(
            new InvoiceService,
            $this->createMock(PaymentService::class),
            $productCatalogService,
            new CheckoutSecurityService,
            $couponService,
            $operationLogService,
            $adminOrderNotificationService,
        );
    }

    /**
     * @return array{0: User, 1: Product}
     */
    private function createUserAndProduct(string $suffix, int $stock): array
    {
        $user = User::query()->create([
            'email' => 'stock-restore-'.$suffix.'@example.com',
            'password' => 'Temp@123456',
            'phone' => '13'.str_pad((string) random_int(0, 999999999), 9, '0', STR_PAD_LEFT),
            'status' => 1,
            'is_verified' => 1,
            'verification_status' => 2,
        ]);

        $product = Product::query()->create([
            'name' => 'Stock Restore Product '.$suffix,
            'product_type' => 'server',
            'pricing' => ['monthly' => '19.00'],
            'setup_fee' => '0.00',
            'config_options' => [],
            'purchase_requires' => [],
            'stock' => $stock,
            'status' => 1,
            'auto_setup' => 0,
        ]);

        return [$user, $product];
    }
}
