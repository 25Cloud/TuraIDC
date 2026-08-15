<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Contracts\Integrations\Payments\PaymentGatewayInterface;
use App\Exceptions\BusinessException;
use App\Services\Integrations\Payments\Data\PaymentPrecreateRequest;
use App\Services\Integrations\Payments\Data\PaymentPrecreateResult;
use App\Services\Integrations\Payments\Data\PaymentQueryResult;
use App\Services\Integrations\Payments\Data\PaymentRefundRequest;
use App\Services\Integrations\Payments\Data\PaymentRefundResult;
use App\Services\Integrations\Payments\PaymentGatewayManager;
use App\Services\Integrations\Payments\PaymentGatewayRegistry;
use Illuminate\Http\Response;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

class PaymentGatewayRegistryTest extends TestCase
{
    public function test_registry_resolves_gateway_by_key(): void
    {
        $gateway = new FakePaymentGateway('fake_pay');
        $registry = new PaymentGatewayRegistry([$gateway]);

        $this->assertSame($gateway, $registry->get('fake_pay'));
        $this->assertSame(['fake_pay'], $registry->keys());
    }

    public function test_registry_rejects_duplicate_gateway_key(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('重复注册');

        new PaymentGatewayRegistry([
            new FakePaymentGateway('fake_pay'),
            new FakePaymentGateway('fake_pay'),
        ]);
    }

    public function test_registry_reports_missing_gateway_in_chinese(): void
    {
        $this->expectException(BusinessException::class);
        $this->expectExceptionMessage('支付网关未注册或不可用');

        (new PaymentGatewayRegistry)->get('missing');
    }

    public function test_manager_uses_registered_gateway(): void
    {
        $gateway = new FakePaymentGateway('fake_pay');
        $manager = new PaymentGatewayManager(new PaymentGatewayRegistry([$gateway]));

        $this->assertSame($gateway, $manager->gateway('fake_pay'));
    }

    public function test_payment_gateway_dto_keeps_legacy_array_contract(): void
    {
        $precreate = new PaymentPrecreateResult('qr', 'PAY123', ['provider' => 'fake']);
        $query = new PaymentQueryResult('TRADE_SUCCESS', 'TRADE123', 'PAY123', '9.90', ['provider' => 'fake']);
        $refund = new PaymentRefundResult('TRADE123', 'PAY123', '9.90', 'Y', '2026-06-08 18:00:00', ['provider' => 'fake']);

        $this->assertSame('qr', $precreate->toArray()['qr_code']);
        $this->assertSame('TRADE_SUCCESS', $query->toArray()['trade_status']);
        $this->assertSame('9.90', $refund->toArray()['refund_fee']);
    }
}

final readonly class FakePaymentGateway implements PaymentGatewayInterface
{
    public function __construct(
        private string $key,
    ) {}

    public function key(): string
    {
        return $this->key;
    }

    public function name(): string
    {
        return '测试支付';
    }

    public function isEnabled(): bool
    {
        return true;
    }

    public function matchesMerchantId(?string $merchantId): bool
    {
        return $merchantId === null || $merchantId === 'fake_merchant';
    }

    public function precreate(PaymentPrecreateRequest $request): PaymentPrecreateResult
    {
        return new PaymentPrecreateResult('qr://fake', $request->outTradeNo);
    }

    public function query(string $outTradeNo): PaymentQueryResult
    {
        return new PaymentQueryResult('TRADE_SUCCESS', 'TRADE123', $outTradeNo, '1.00');
    }

    public function refund(PaymentRefundRequest $request): PaymentRefundResult
    {
        return new PaymentRefundResult('TRADE123', $request->outTradeNo, number_format($request->refundAmount, 2, '.', ''));
    }

    public function verifyNotify(array $payload): bool
    {
        return true;
    }

    public function buildNotifyResponse(bool $success): Response
    {
        return new Response($success ? 'success' : 'fail', 200, ['Content-Type' => 'text/plain']);
    }
}
