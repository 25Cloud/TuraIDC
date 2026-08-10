<?php

declare(strict_types=1);

namespace App\Services\Verification\Contracts;

use App\Services\Verification\Data\VerificationInitializeRequest;
use App\Services\Verification\Data\VerificationInitializeResult;
use App\Services\Verification\Data\VerificationScanUrlResult;
use App\Services\Verification\Data\VerificationStatusResult;

interface VerificationDriver
{
    public function key(): string;

    public function label(): string;

    public function initialize(VerificationInitializeRequest $request): VerificationInitializeResult;

    public function generateScanUrl(string $certifyId): VerificationScanUrlResult;

    public function queryStatus(string $certifyId): VerificationStatusResult;
}
