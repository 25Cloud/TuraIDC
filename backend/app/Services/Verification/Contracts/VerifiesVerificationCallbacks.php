<?php

declare(strict_types=1);

namespace App\Services\Verification\Contracts;

use App\Services\Verification\Data\VerificationCallbackRequest;
use App\Services\Verification\Data\VerificationCallbackVerificationResult;

interface VerifiesVerificationCallbacks
{
    public function verifyCallback(VerificationCallbackRequest $request): VerificationCallbackVerificationResult;
}
