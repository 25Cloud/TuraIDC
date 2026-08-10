<?php

declare(strict_types=1);

namespace App\Services\Integrations\Payments\Data;

final readonly class PaymentQueryResult
{
    public function __construct(
        public string $tradeStatus,
        public string $tradeNo,
        public string $outTradeNo,
        public string $totalAmount,
        public array $raw = [],
    ) {}

    public function toArray(): array
    {
        return [
            'trade_status' => $this->tradeStatus,
            'trade_no' => $this->tradeNo,
            'out_trade_no' => $this->outTradeNo,
            'total_amount' => $this->totalAmount,
            'raw' => $this->raw,
        ];
    }
}
