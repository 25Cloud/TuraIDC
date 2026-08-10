<?php

declare(strict_types=1);

namespace App\Services\Integrations\Payments\Data;

final readonly class PaymentPrecreateResult
{
    public function __construct(
        public string $qrCode,
        public string $outTradeNo,
        public array $raw = [],
    ) {}

    public function toArray(): array
    {
        return [
            'qr_code' => $this->qrCode,
            'out_trade_no' => $this->outTradeNo,
            'raw' => $this->raw,
        ];
    }
}
