<?php

declare(strict_types=1);

namespace App\Services\Integrations\Payments\Data;

final readonly class PaymentPrecreateRequest
{
    public function __construct(
        public string $outTradeNo,
        public float $amount,
        public string $subject,
        public ?string $timeoutExpress = null,
        public array $context = [],
    ) {}
}
