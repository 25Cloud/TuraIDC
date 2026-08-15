<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Exceptions\BusinessException;
use App\Models\User;
use App\Services\Finance\AdminOrderNotificationService;
use App\Services\Finance\CouponService;
use App\Services\Finance\InvoiceService;
use App\Services\Finance\PaymentService;
use App\Services\Order\PaidOrderBusinessFlowDispatcher;
use App\Services\Provisioning\ProvisionService;
use App\Services\Provisioning\ServiceRenewService;
use App\Services\Referral\ReferralService;
use Tests\TestCase;

class RechargeRequiresVerificationTest extends TestCase
{
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

        $service = new PaymentService(
            $this->createMock(ProvisionService::class),
            $this->makePaymentGatewayManagerForTest($alipayGateway),
            $this->createMock(ServiceRenewService::class),
            $this->createMock(ReferralService::class),
            $this->createMock(PaidOrderBusinessFlowDispatcher::class),
            $this->createMock(AdminOrderNotificationService::class),
            $this->createMock(CouponService::class),
            new InvoiceService,
        );

        $this->expectException(BusinessException::class);
        $this->expectExceptionMessage('实名认证');

        try {
            $service->rechargeByAlipay($user, 100.00);
        } catch (BusinessException $exception) {
            $this->assertSame(40301, $exception->getErrorCode());
            throw $exception;
        }
    }
}
