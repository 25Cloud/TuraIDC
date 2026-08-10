<?php

declare(strict_types=1);

namespace App\Services\Verification\Data;

final readonly class VerificationCallbackVerificationResult
{
    public function __construct(
        public bool $passed,
        public string $message = '签名验证失败',
        public int $code = 40001,
        public int $httpStatus = 401,
        public ?string $replayKey = null,
    ) {}

    public static function pass(?string $replayKey = null): self
    {
        return new self(true, replayKey: $replayKey);
    }

    public static function fail(string $message = '签名验证失败', int $code = 40001, int $httpStatus = 401): self
    {
        return new self(false, $message, $code, $httpStatus);
    }
}
