<?php

declare(strict_types=1);

namespace App\Services\Verification\Data;

/**
 * 实名认证初始化请求数据。
 */
final readonly class VerificationInitializeRequest
{
    public function __construct(
        public string $realName,
        public string $idCard,
        public string $certType,
        public string $returnUrl,
    ) {}
}
