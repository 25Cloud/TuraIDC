<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Exceptions\BusinessException;
use App\Models\Setting;
use App\Models\User;
use App\Services\Auth\AccountPolicyService;
use App\Services\Finance\AdminOrderNotificationService;
use App\Services\Finance\CouponService;
use App\Services\Finance\InvoiceService;
use App\Services\Finance\PaymentService;
use App\Services\Order\PaidOrderBusinessFlowDispatcher;
use App\Services\Provisioning\ProvisionService;
use App\Services\Provisioning\ServiceRenewService;
use App\Services\Referral\ReferralService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * 充值的实名要求是「业务线开关 OR 账户策略」：
 * finance.recharge_requires_verification 打开时额外强制实名，页面上的账户策略再叠加
 * 手机号/邮箱要求；两条路径的判定与文案都必须由 AccountPolicyService 给出，不在本地重写。
 */
class RechargeRequiresVerificationTest extends TestCase
{
    use DatabaseTransactions;

    public function test_recharge_by_alipay_requires_verified_user(): void
    {
        $suffix = bin2hex(random_bytes(4));

        $user = User::query()->create([
            'email' => 'recharge-verify-'.$suffix.'@example.com',
            'password' => 'Temp@123456',
            'phone' => '13'.str_pad((string) random_int(0, 999999999), 9, '0', STR_PAD_LEFT),
            'status' => 1,
            'nickname' => 'Recharge Verify',
            'real_name' => '',
            'id_card' => '',
            'is_verified' => 0,
            'verification_status' => 0,
        ]);

        $alipayGateway = $this->makeFakePaymentGateway(['enabled' => true]);

        $service = $this->makePaymentService($alipayGateway);

        $this->expectException(BusinessException::class);
        // 文案来自账户策略服务，入口是本条业务线的充值开关
        $this->expectExceptionMessage('充值前请先完成实名认证');

        try {
            $service->rechargeByAlipay($user, 100.00);
        } catch (BusinessException $exception) {
            $this->assertSame(40301, $exception->getErrorCode());
            throw $exception;
        }
    }

    public function test_recharge_switch_off_still_enforces_account_policy(): void
    {
        // 关掉充值业务线的实名开关，但账户策略要求绑定手机号：未绑手机仍不能充值
        Setting::setValue('finance', 'recharge_requires_verification', '0');
        Setting::setValues(AccountPolicyService::GROUP, [
            'require_verification' => '0',
            'require_phone' => '1',
            'require_email' => '0',
        ]);

        $suffix = bin2hex(random_bytes(4));

        $user = User::query()->create([
            'email' => 'recharge-policy-'.$suffix.'@example.com',
            'password' => 'Temp@123456',
            'phone' => '',
            'status' => 1,
            'nickname' => 'Recharge Policy',
            'real_name' => '',
            'id_card' => '',
            'is_verified' => 1,
            'verification_status' => 2,
        ]);

        $service = $this->makePaymentService($this->makeFakePaymentGateway(['enabled' => true]));

        try {
            $service->rechargeByAlipay($user, 100.00);
            $this->fail('账户策略要求绑定手机号时，未绑手机的用户不应通过充值校验');
        } catch (BusinessException $exception) {
            $this->assertSame(40301, $exception->getErrorCode());
            $this->assertSame('充值前请先完成绑定手机号', $exception->getMessage());
        }
    }

    private function makePaymentService(mixed $gateway): PaymentService
    {
        return new PaymentService(
            $this->createMock(ProvisionService::class),
            $this->makePaymentGatewayManagerForTest($gateway),
            $this->createMock(ServiceRenewService::class),
            $this->createMock(ReferralService::class),
            $this->createMock(PaidOrderBusinessFlowDispatcher::class),
            $this->createMock(AdminOrderNotificationService::class),
            $this->createMock(CouponService::class),
            new InvoiceService,
        );
    }
}
