<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Constants\PaymentStatus;
use App\Constants\ServiceStatus;
use App\Constants\UserCouponStatus;
use App\Models\Coupon;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\Product;
use App\Models\Service;
use App\Models\User;
use App\Models\UserCoupon;
use App\Services\Finance\FinanceDocumentService;
use App\Services\Provisioning\ServiceRenewService;
use App\Services\ZjmfUpstream\UpstreamProvisionService;
use Tests\TestCase;

/**
 * 两条修复的回归护栏：
 *
 * 1) 带券续费的 order.amount 必须与 invoice.amount 同为「券后应付额」。
 *    支付回调 assertBusinessPaymentComposition 直接比对这两者，口径不一致会让
 *    「续费 + 优惠券 + 第三方网关」整笔回滚——用户付了钱、服务却续不上。
 *    两条续费入口都要覆盖：上一轮修复只改了其中一条，另一条的缺陷原样保留。
 *
 * 2) 到期欠费停机（SUSPENDED + suspended_reason='expired'）不得由下游 unsuspend 解除。
 *    只判 status 挡不住它，因为到期停机本身就是 SUSPENDED。
 */
class RenewAmountAndExpiredUnsuspendTest extends TestCase
{
    private const CYCLE_PRICE = '20.00';

    private const COUPON_VALUE = '5.00';

    private const PAYABLE = 15.00;

    public function test_renew_order_entry_keeps_order_and_invoice_amounts_aligned_with_coupon(): void
    {
        [$user, $service, $userCoupon] = $this->makeRenewableFixture('order-entry');

        $order = app(ServiceRenewService::class)
            ->createRenewOrderForUser($user, (int) $service->id, 'monthly', (int) $userCoupon->id);

        $invoice = Invoice::query()->where('order_id', (int) $order->id)->firstOrFail();

        $this->assertSame(self::PAYABLE, round((float) $order->amount, 2), 'order.amount 必须是券后应付额');
        $this->assertSame(5.00, round((float) $order->discount, 2), '券额仍须记录在订单上');
        $this->assertSame(self::PAYABLE, round((float) $invoice->amount, 2), 'invoice.amount 必须是券后应付额');
        $this->assertSame(
            round((float) $order->amount, 2),
            round((float) $invoice->amount, 2),
            '两侧口径必须一致，否则支付回调会抛「订单与账单金额不一致」'
        );

        // 端到端复现原缺陷的触发点：第三方网关回调走的就是这条路径
        $payment = Payment::query()->create([
            'payment_no' => Payment::generatePaymentNo(),
            'user_id' => (int) $user->id,
            'order_id' => (int) $order->id,
            'invoice_id' => (int) $invoice->id,
            'gateway' => 'alipay',
            'amount' => number_format(self::PAYABLE, 2, '.', ''),
            'status' => PaymentStatus::SUCCESS,
        ]);

        $record = app(FinanceDocumentService::class)->recordThirdPartyPayment($payment, $invoice);
        $this->assertNotNull($record->id, '带券续费的第三方支付回调不得被金额一致性断言拦下');
    }

    public function test_renew_invoice_entry_keeps_order_and_invoice_amounts_aligned_with_coupon(): void
    {
        [$user, $service, $userCoupon] = $this->makeRenewableFixture('invoice-entry');

        $invoice = app(ServiceRenewService::class)
            ->createRenewInvoiceForUser($user, (int) $service->id, 'monthly', (int) $userCoupon->id);

        $this->assertSame(self::PAYABLE, round((float) $invoice->amount, 2));

        $order = $invoice->order()->first();
        $this->assertNotNull($order, '续费账单必须有影子订单');
        $this->assertSame(
            round((float) $order->amount, 2),
            round((float) $invoice->amount, 2),
            '两条续费入口必须遵守同一口径——上一轮只修了一条，另一条留了同样的坑'
        );
    }

    /**
     * 这里刻意用字面量 'expired' 而不是 Service::SUSPENDED_REASON_EXPIRED。
     *
     * 该常量是本次修复才引入的，用它会让「只打测试补丁跑在未修复代码上」的反向验证
     * 因未定义常量而 Error，掩盖掉真正要证明的行为差异。用例应当钉住**可观测行为**
     * ——库里实际存的那个字符串——而不是依赖修复自身引入的符号。
     */
    private const EXPIRED_REASON = 'expired';

    public function test_expired_suspension_cannot_be_lifted_through_the_upstream_protocol(): void
    {
        [$user, $service] = $this->makeRenewableFixture('expired-unsuspend');
        $service->forceFill([
            'status' => ServiceStatus::SUSPENDED,
            'suspended_reason' => self::EXPIRED_REASON,
        ])->save();

        $result = app(UpstreamProvisionService::class)->execute($user, [
            'id' => (int) $service->id,
            'func' => 'unsuspend',
        ]);

        $this->assertSame(400, $result['status'], '到期欠费停机不得由下游解除');

        $fresh = $service->fresh();
        $this->assertSame(ServiceStatus::SUSPENDED, (int) $fresh->status);
        $this->assertSame(
            self::EXPIRED_REASON,
            (string) $fresh->suspended_reason,
            'suspended_reason 一旦被清空，自动取消流程就再也筛不到该服务，等于永久规避欠费终止'
        );
    }

    public function test_the_constant_matches_the_persisted_marker(): void
    {
        // 上面用字面量换取反向验证的可读性，这条负责把常量与字面量钉在一起，
        // 防止将来有人改了常量值而上面的用例还在测旧字符串。
        $this->assertSame(self::EXPIRED_REASON, Service::SUSPENDED_REASON_EXPIRED);
    }

    public function test_manual_suspension_can_still_be_lifted_through_the_upstream_protocol(): void
    {
        // 正向对照：修复只针对「到期欠费」这一种停机，别的停机原因不受影响
        [$user, $service] = $this->makeRenewableFixture('manual-unsuspend');
        $service->forceFill([
            'status' => ServiceStatus::SUSPENDED,
            'suspended_reason' => '管理员手动暂停',
        ])->save();

        $result = app(UpstreamProvisionService::class)->execute($user, [
            'id' => (int) $service->id,
            'func' => 'unsuspend',
        ]);

        $this->assertSame(200, $result['status']);
        $this->assertSame(ServiceStatus::ACTIVE, (int) $service->fresh()->status);
    }

    /**
     * @return array{0: User, 1: Service, 2: UserCoupon}
     */
    private function makeRenewableFixture(string $tag): array
    {
        $suffix = $tag.'-'.bin2hex(random_bytes(4));

        $user = User::query()->create([
            'email' => 'renew-align-'.$suffix.'@example.com',
            'password' => 'secret123',
            'phone' => '137'.str_pad((string) random_int(0, 99999999), 8, '0', STR_PAD_LEFT),
            'status' => 1,
        ]);

        $product = Product::query()->create([
            'name' => 'Renew Align Product',
            'product_type' => 'server',
            'pricing' => ['monthly' => self::CYCLE_PRICE],
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
            'domain' => 'align-'.$suffix.'.example.com',
            'billing_cycle' => 'monthly',
            'amount' => self::CYCLE_PRICE,
            'status' => ServiceStatus::ACTIVE,
            'provision_data' => [],
            'expires_at' => now()->addMonth(),
            'auto_renew' => 0,
        ]);

        $coupon = Coupon::query()->create([
            'name' => 'Renew Align Coupon',
            'code' => 'CPNALIGN'.strtoupper(bin2hex(random_bytes(4))),
            'distribution_type' => 'public',
            'discount_scope' => 'renew',
            'discount_type' => 'fixed',
            'discount_value' => self::COUPON_VALUE,
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

        return [$user, $service, $userCoupon];
    }
}
