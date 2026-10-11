<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Constants\InvoiceStatus;
use App\Constants\ServiceStatus;
use App\Constants\UserCouponStatus;
use App\Exceptions\BusinessException;
use App\Models\Coupon;
use App\Models\Invoice;
use App\Models\Order;
use App\Models\Product;
use App\Models\Service;
use App\Models\User;
use App\Models\UserCoupon;
use App\Services\Finance\PaymentService;
use App\Services\Provisioning\ServiceRenewService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use PHPUnit\Framework\Attributes\Test;
use ReflectionMethod;
use Tests\TestCase;

/**
 * 续费防双扣回归：
 * - 已支付未履约续费账单的拦截不区分券选择（换券/去券请求同样生效）；
 * - 手动续费命中已支付处理中账单时拒绝复用并抛异常，自动路径保持复用防双扣；
 * - 订单支付应付额不再按 discount 列二次扣减（订单金额即应付价口径）。
 * 使用 DatabaseTransactions：所有写入在测试结束后回滚，不污染 idc_test 现有数据。
 */
class ServiceRenewBlockingAndCouponGuardTest extends TestCase
{
    use DatabaseTransactions;

    #[Test]
    public function blocking_paid_renew_invoice_ignores_coupon_selection(): void
    {
        [$user, $service, , $couponA, $couponB] = $this->fixture();
        $userCouponB = $this->userCouponOf($user, $couponB);

        // 同周期已有已支付未履约续费账单（带券 A）：换券 B 再请求仍必须被拦截复用
        $paidInvoice = Invoice::query()->create([
            'invoice_no' => Invoice::generateInvoiceNo(),
            'user_id' => (int) $user->id,
            'product_id' => (int) $service->product_id,
            'service_id' => (int) $service->id,
            'type' => 'renew',
            'amount' => '20.00',
            'paid_amount' => '20.00',
            'billing_cycle' => 'monthly',
            'coupon_id' => (int) $couponA['id'],
            'user_coupon_id' => (int) $this->userCouponOf($user, $couponA)->id,
            'status' => InvoiceStatus::PAID,
            'paid_at' => now(),
            'due_date' => now()->addDay(),
        ]);

        $result = app(ServiceRenewService::class)->createRenewInvoiceForUser(
            $user,
            (int) $service->id,
            'monthly',
            (int) $userCouponB->id
        );

        $this->assertSame((int) $paidInvoice->id, (int) $result->id);
        $this->assertSame(0, Invoice::query()
            ->where('service_id', (int) $service->id)
            ->where('type', 'renew')
            ->where('status', InvoiceStatus::UNPAID)
            ->count());
    }

    #[Test]
    public function manual_renew_rejects_blocking_paid_invoice(): void
    {
        [$user, $service] = $this->fixture();

        $this->expectException(BusinessException::class);
        $this->expectExceptionMessage('该周期已有一笔正在处理的续费');

        app(ServiceRenewService::class)->createRenewInvoiceForUser(
            $user,
            (int) $service->id,
            (string) $service->billing_cycle,
            0,
            [],
            true
        );
    }

    #[Test]
    public function auto_path_reuses_blocking_paid_invoice(): void
    {
        [$user, $service, $invoice] = $this->fixture();

        $reused = app(ServiceRenewService::class)->createRenewInvoiceForUser(
            $user,
            (int) $service->id,
            (string) $service->billing_cycle,
            0,
            []
        );

        $this->assertSame((int) $invoice->id, (int) $reused->id);
    }

    #[Test]
    public function manual_renew_reuses_unpaid_invoice(): void
    {
        [$user, $service] = $this->fixture(false);
        $renew = app(ServiceRenewService::class);

        // 手动路径只是拒绝「已支付处理中」的账单；未付账单仍应复用而不是抛异常
        $renew->createRenewInvoiceForUser($user, (int) $service->id, 'monthly');
        $invoice = $renew->createRenewInvoiceForUser($user, (int) $service->id, 'monthly', 0, [], true);

        $this->assertSame(InvoiceStatus::UNPAID, (int) $invoice->refresh()->status);
    }

    #[Test]
    public function order_payable_amount_does_not_subtract_discount_twice(): void
    {
        [$order] = $this->paidOrderStub();

        $amount = $this->invokePayableAmount($order);

        $this->assertSame(15.0, $amount);
    }

    #[Test]
    public function order_payable_amount_prefers_invoice_amount(): void
    {
        [$order, $invoice] = $this->paidOrderStub();
        $order->setRelation('invoice', $invoice);

        $this->assertSame(15.0, $this->invokePayableAmount($order));
    }

    /**
     * @return array{0: Order, 1: Invoice}
     */
    private function paidOrderStub(): array
    {
        // 免启动模型实例：单测进程无应用容器，直接 new 会触发 bootSoftDeletes 的 Schema facade。
        $order = (new \ReflectionClass(Order::class))->newInstanceWithoutConstructor();
        $order->setRawAttributes([
            'amount' => '15.00',
            'discount' => '5.00',
            'paid_amount' => '0.00',
        ]);

        $invoice = (new \ReflectionClass(Invoice::class))->newInstanceWithoutConstructor();
        $invoice->setRawAttributes(['amount' => '15.00', 'discount' => '5.00']);

        return [$order, $invoice];
    }

    private function invokePayableAmount(Order $order): float
    {
        $service = app(PaymentService::class);
        $method = new ReflectionMethod(PaymentService::class, 'resolveOrderPayableAmount');

        return $method->invoke($service, $order);
    }

    /**
     * @return array{0: User, 1: Service, 2: Invoice|null, 3: Coupon, 4: Coupon}
     */
    private function fixture(bool $withPaidInvoice = true): array
    {
        $user = User::factory()->create();
        $product = Product::query()->create([
            'name' => '续费防双扣回归'.uniqid(),
            'product_type' => 'cloud_host',
            'status' => 1,
            'pricing' => ['monthly' => '20.00'],
            'setup_fee' => '0.00',
            'config_options' => [],
            'purchase_requires' => [],
            'stock' => -1,
            'auto_setup' => 0,
        ]);
        $service = Service::query()->create([
            'user_id' => (int) $user->id,
            'product_id' => (int) $product->id,
            'billing_cycle' => 'monthly',
            'amount' => 20.00,
            'status' => ServiceStatus::ACTIVE,
            'expires_at' => now()->addMonth(),
        ]);
        $invoice = null;
        if ($withPaidInvoice) {
            $invoice = Invoice::query()->create([
                'invoice_no' => Invoice::generateInvoiceNo(),
                'user_id' => (int) $user->id,
                'product_id' => (int) $product->id,
                'service_id' => (int) $service->id,
                'type' => 'renew',
                'amount' => '20.00',
                'paid_amount' => '20.00',
                'billing_cycle' => 'monthly',
                'status' => InvoiceStatus::PAID,
                'paid_at' => now(),
                'due_date' => now()->addDay(),
            ]);
        }

        $suffix = uniqid();
        $couponA = Coupon::query()->create([
            'name' => '防双扣券A'.$suffix,
            'code' => 'GRD_A_'.$suffix,
            'distribution_type' => 'private',
            'discount_type' => 'fixed',
            'discount_value' => '5.00',
            'status' => 1,
        ]);
        $couponB = Coupon::query()->create([
            'name' => '防双扣券B'.$suffix,
            'code' => 'GRD_B_'.$suffix,
            'distribution_type' => 'private',
            'discount_type' => 'fixed',
            'discount_value' => '3.00',
            'status' => 1,
        ]);

        return [$user, $service, $invoice, $couponA, $couponB];
    }

    private function userCouponOf(User $user, Coupon $coupon): UserCoupon
    {
        return UserCoupon::query()->create([
            'coupon_id' => (int) $coupon->id,
            'coupon_code' => (string) $coupon->code,
            'user_id' => (int) $user->id,
            'status' => UserCouponStatus::OWNED,
        ]);
    }
}
