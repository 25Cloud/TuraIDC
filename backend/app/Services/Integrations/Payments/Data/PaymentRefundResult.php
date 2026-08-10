<?php

declare(strict_types=1);

namespace App\Services\Integrations\Payments\Data;

final readonly class PaymentRefundResult
{
    public function __construct(
        public string $tradeNo,
        public string $outTradeNo,
        public string $refundFee,
        public string $fundChange = '',
        public string $gmtRefundPay = '',
        public array $raw = [],
    ) {}

    public function toArray(): array
    {
        return [
            'trade_no' => $this->tradeNo,
            'out_trade_no' => $this->outTradeNo,
            'refund_fee' => $this->refundFee,
            'fund_change' => $this->fundChange,
            'gmt_refund_pay' => $this->gmtRefundPay,
            'raw' => $this->raw,
        ];
    }
}
