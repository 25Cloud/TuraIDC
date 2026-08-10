<?php

declare(strict_types=1);

namespace App\Services\Verification\Data;

/**
 * 实名认证初始化结果，避免业务层直接依赖供应商数组。
 */
final readonly class VerificationInitializeResult
{
    public function __construct(
        public int $status,
        public string $message,
        public string $certifyId = '',
        public array $raw = [],
    ) {}

    public function toArray(): array
    {
        return [
            'status' => $this->status,
            'msg' => $this->message,
            'certify_id' => $this->certifyId,
        ];
    }
}
