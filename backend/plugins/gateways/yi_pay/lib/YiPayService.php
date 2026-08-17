<?php

declare(strict_types=1);

namespace TuraIDC\Plugins\Gateways\YiPay\Lib;

use App\Services\Integrations\Payments\Data\PaymentRefundRequest;

class YiPayService
{
    public function key(): string
    {
        return 'yipay';
    }

    public function name(): string
    {
        return '易支付';
    }

    /**
     * @param  array<string, mixed>  $request
     * @return array<string, mixed>
     */
    public function execute(array $request): array
    {
        $action = trim((string) ($request['action'] ?? ''));
        $payload = is_array($request['payload'] ?? null) ? $request['payload'] : [];
        $config = is_array($request['config'] ?? null) ? $request['config'] : [];
        $client = new YiPayClient($config);

        return match ($action) {
            'payment.is_enabled' => $this->success($action, [
                'enabled' => $client->isEnabled(),
            ]),
            'payment.matches_merchant' => $this->success($action, [
                'matched' => $client->matchesMerchantId(isset($payload['merchant_id']) ? (string) $payload['merchant_id'] : null),
            ]),
            'payment.options' => $this->success($action, [
                'list' => $client->isEnabled() ? $client->paymentOptions() : [],
            ]),
            'payment.precreate' => $this->success($action, $client->precreate(
                outTradeNo: (string) ($payload['out_trade_no'] ?? ''),
                amount: (float) ($payload['amount'] ?? 0),
                subject: (string) ($payload['subject'] ?? ''),
                paymentType: isset($payload['payment_type']) ? (string) $payload['payment_type'] : null,
            )),
            'payment.query' => $this->success($action, $client->query(
                (string) ($payload['out_trade_no'] ?? '')
            )),
            'payment.refund' => $this->success($action, $client->refund(new PaymentRefundRequest(
                outTradeNo: (string) ($payload['out_trade_no'] ?? ''),
                refundAmount: (float) ($payload['refund_amount'] ?? 0),
                refundReason: (string) ($payload['refund_reason'] ?? ''),
                tradeNo: isset($payload['trade_no']) ? (string) $payload['trade_no'] : null,
                outRequestNo: isset($payload['out_request_no']) ? (string) $payload['out_request_no'] : null,
            ))),
            'payment.verify_notify' => $this->success($action, [
                'verified' => $client->verifyNotify($payload),
            ]),
            default => ['success' => false, 'action' => $action, 'message' => 'Unsupported plugin action', 'data' => []],
        };
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function success(string $action, array $data): array
    {
        return ['success' => true, 'action' => $action, 'data' => $data];
    }
}
