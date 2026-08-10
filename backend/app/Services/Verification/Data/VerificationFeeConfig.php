<?php

declare(strict_types=1);

namespace App\Services\Verification\Data;

final readonly class VerificationFeeConfig
{
    public function __construct(
        public int $freeAttempts,
        public float $retryFee,
        public bool $chargeEnabled = false,
        public float $amount = 0.0,
    ) {}

    /**
     * @return array{free_attempts: int, retry_fee: float, charge_enabled: bool, amount: float}
     */
    public function toArray(): array
    {
        return [
            'free_attempts' => max(0, $this->freeAttempts),
            'retry_fee' => max(0.0, $this->retryFee),
            'charge_enabled' => $this->chargeEnabled,
            'amount' => max(0.0, $this->amount),
        ];
    }
}
