<?php

declare(strict_types=1);

namespace Tests\Fakes;

use App\Constants\PaymentGatewayCode;
use App\Contracts\Integrations\Payments\PaymentGatewayInterface;
use App\Services\Integrations\Payments\Data\PaymentPrecreateRequest;
use App\Services\Integrations\Payments\Data\PaymentPrecreateResult;
use App\Services\Integrations\Payments\Data\PaymentQueryResult;
use App\Services\Integrations\Payments\Data\PaymentRefundRequest;
use App\Services\Integrations\Payments\Data\PaymentRefundResult;
use Illuminate\Http\Response;

/**
 * 测试用支付网关 Fake：记录调用、支持覆盖预创建/查询/退款/签名校验结果。
 */
final class TestPaymentGateway implements PaymentGatewayInterface
{
    /** @var array<int, array{method: string, payload: mixed}> */
    private array $calls = [];

    /** @param array<string, mixed> $overrides */
    public function __construct(private array $overrides = []) {}

    public function key(): string
    {
        return (string) ($this->overrides['key'] ?? PaymentGatewayCode::ALIPAY);
    }

    public function name(): string
    {
        return (string) ($this->overrides['name'] ?? '支付宝当面付');
    }

    public function isEnabled(): bool
    {
        $this->record('isEnabled');

        return (bool) ($this->overrides['enabled'] ?? true);
    }

    public function matchesMerchantId(?string $merchantId): bool
    {
        $this->record('matchesMerchantId', $merchantId);

        $override = $this->overrides['matches_merchant'] ?? true;
        if (is_callable($override)) {
            return (bool) $override($merchantId, $this);
        }

        return (bool) $override;
    }

    public function precreate(PaymentPrecreateRequest $request): PaymentPrecreateResult
    {
        $this->record('precreate', $request);
        $result = $this->resolveOverride('precreate', $request, [
            'qr_code' => 'https://qr.alipay.test/default',
            'out_trade_no' => $request->outTradeNo,
        ]);

        if ($result instanceof PaymentPrecreateResult) {
            return $result;
        }

        $data = is_array($result) ? $result : [];

        return new PaymentPrecreateResult(
            qrCode: (string) ($data['qr_code'] ?? ''),
            outTradeNo: (string) ($data['out_trade_no'] ?? $request->outTradeNo),
            raw: is_array($data['raw'] ?? null) ? $data['raw'] : $data,
        );
    }

    public function query(string $outTradeNo): PaymentQueryResult
    {
        $this->record('query', $outTradeNo);
        $result = $this->resolveOverride('query', $outTradeNo, [
            'trade_status' => 'WAIT_BUYER_PAY',
            'trade_no' => '',
            'out_trade_no' => $outTradeNo,
            'total_amount' => '0.00',
        ]);

        if ($result instanceof PaymentQueryResult) {
            return $result;
        }

        $data = is_array($result) ? $result : [];

        return new PaymentQueryResult(
            tradeStatus: (string) ($data['trade_status'] ?? ''),
            tradeNo: (string) ($data['trade_no'] ?? ''),
            outTradeNo: (string) ($data['out_trade_no'] ?? $outTradeNo),
            totalAmount: (string) ($data['total_amount'] ?? ''),
            raw: is_array($data['raw'] ?? null) ? $data['raw'] : $data,
        );
    }

    public function refund(PaymentRefundRequest $request): PaymentRefundResult
    {
        $this->record('refund', $request);
        $result = $this->resolveOverride('refund', $request, [
            'trade_no' => $request->tradeNo ?? '',
            'out_trade_no' => $request->outTradeNo,
            'refund_fee' => number_format($request->refundAmount, 2, '.', ''),
            'fund_change' => '',
            'gmt_refund_pay' => '',
        ]);

        if ($result instanceof PaymentRefundResult) {
            return $result;
        }

        $data = is_array($result) ? $result : [];

        return new PaymentRefundResult(
            tradeNo: (string) ($data['trade_no'] ?? ''),
            outTradeNo: (string) ($data['out_trade_no'] ?? $request->outTradeNo),
            refundFee: (string) ($data['refund_fee'] ?? ''),
            fundChange: (string) ($data['fund_change'] ?? ''),
            gmtRefundPay: (string) ($data['gmt_refund_pay'] ?? ''),
            raw: is_array($data['raw'] ?? null) ? $data['raw'] : $data,
        );
    }

    public function verifyNotify(array $payload): bool
    {
        $this->record('verifyNotify', $payload);

        $override = $this->overrides['verify_notify'] ?? true;
        if (is_callable($override)) {
            return (bool) $override($payload, $this);
        }

        return (bool) $override;
    }

    public function buildNotifyResponse(bool $success): Response
    {
        return new Response($success ? 'success' : 'fail', 200, ['Content-Type' => 'text/plain']);
    }

    public function countCalls(string $method): int
    {
        return count(array_filter(
            $this->calls,
            static fn (array $call): bool => $call['method'] === $method
        ));
    }

    private function record(string $method, mixed $payload = null): void
    {
        $this->calls[] = [
            'method' => $method,
            'payload' => $payload,
        ];
    }

    private function resolveOverride(string $key, mixed $argument, mixed $default): mixed
    {
        $override = $this->overrides[$key] ?? $default;

        if ($override instanceof \Throwable) {
            throw $override;
        }

        if (is_callable($override)) {
            return $override($argument, $this);
        }

        return $override;
    }
}
