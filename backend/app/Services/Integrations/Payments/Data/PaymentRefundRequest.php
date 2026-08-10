<?php

declare(strict_types=1);

namespace App\Services\Integrations\Payments\Data;

final readonly class PaymentRefundRequest
{
    public function __construct(
        public string $outTradeNo,
        public float $refundAmount,
        public string $refundReason = '',
        public ?string $tradeNo = null,
        public ?string $outRequestNo = null,
    ) {}
}
