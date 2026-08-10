<?php

declare(strict_types=1);

namespace App\Services\Verification\Contracts;

use App\Services\Verification\Data\VerificationFeeConfig;

interface ProvidesVerificationFeeConfig
{
    public function feeConfig(): VerificationFeeConfig;
}
