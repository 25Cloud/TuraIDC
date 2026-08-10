<?php

declare(strict_types=1);

namespace App\Services\Integrations\Plugins\Adapters;

use App\Contracts\Integrations\Payments\PaymentGatewayInterface;
use App\Exceptions\BusinessException;
use App\Services\Integrations\Payments\Data\PaymentPrecreateRequest;
use App\Services\Integrations\Payments\Data\PaymentPrecreateResult;
use App\Services\Integrations\Payments\Data\PaymentQueryResult;
use App\Services\Integrations\Payments\Data\PaymentRefundRequest;
use App\Services\Integrations\Payments\Data\PaymentRefundResult;
use App\Services\Integrations\Plugins\PluginManifest;
use App\Services\Integrations\Plugins\PluginRuntimeRegistry;
use Illuminate\Http\Response;

final readonly class PluginPaymentGateway implements PaymentGatewayInterface
{
    public function __construct(
        private PluginRuntimeRegistry $runtime,
        private PluginManifest $manifest,
    ) {}

    public function key(): string
    {
        return $this->manifest->key;
    }

    public function name(): string
    {
        return $this->manifest->name;
    }

    public function isEnabled(): bool
    {
        $data = $this->executeData('payment.is_enabled', []);

        return (bool) ($data['enabled'] ?? false);
    }

    public function matchesMerchantId(?string $merchantId): bool
    {
        $data = $this->executeData('payment.matches_merchant', [
            'merchant_id' => $merchantId,
        ]);

        return (bool) ($data['matched'] ?? false);
    }

    public function precreate(PaymentPrecreateRequest $request): PaymentPrecreateResult
    {
        $data = $this->executeData('payment.precreate', [
            'out_trade_no' => $request->outTradeNo,
            'amount' => $request->amount,
            'subject' => $request->subject,
            'timeout_express' => $request->timeoutExpress,
            'context' => $request->context,
            ...$request->context,
        ]);

        return new PaymentPrecreateResult(
            qrCode: (string) ($data['qr_code'] ?? ''),
            outTradeNo: (string) ($data['out_trade_no'] ?? $request->outTradeNo),
            raw: is_array($data['raw'] ?? null) ? $data['raw'] : $data,
        );
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function paymentOptions(): array
    {
        try {
            $data = $this->executeData('payment.options', []);
        } catch (BusinessException $exception) {
            if ($exception->getMessage() !== 'Unsupported plugin action') {
                throw $exception;
            }

            return [];
        }

        $list = $data['list'] ?? $data['options'] ?? [];

        return collect(is_array($list) ? $list : [])
            ->filter(fn ($item): bool => is_array($item))
            ->values()
            ->all();
    }

    public function query(string $outTradeNo): PaymentQueryResult
    {
        $data = $this->executeData('payment.query', [
            'out_trade_no' => $outTradeNo,
        ]);

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
        $data = $this->executeData('payment.refund', [
            'out_trade_no' => $request->outTradeNo,
            'refund_amount' => $request->refundAmount,
            'refund_reason' => $request->refundReason,
            'trade_no' => $request->tradeNo,
            'out_request_no' => $request->outRequestNo,
        ]);

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
        $result = $this->runtime->execute($this->manifest->domain, $this->manifest->slug, 'payment.verify_notify', $payload);

        return (bool) ($result['data']['verified'] ?? false);
    }

    public function buildNotifyResponse(bool $success): Response
    {
        return response($success ? 'success' : 'fail', 200)->header('Content-Type', 'text/plain');
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function executeData(string $action, array $payload): array
    {
        $result = $this->runtime->execute($this->manifest->domain, $this->manifest->slug, $action, $payload);

        if (($result['success'] ?? true) === false) {
            throw new BusinessException((string) ($result['message'] ?? '支付插件执行失败'), 42200);
        }

        return is_array($result['data'] ?? null) ? $result['data'] : [];
    }
}
