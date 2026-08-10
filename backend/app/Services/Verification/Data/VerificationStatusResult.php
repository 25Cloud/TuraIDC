<?php

declare(strict_types=1);

namespace App\Services\Verification\Data;

/**
 * 实名认证查询结果，状态码使用项目内部状态。
 */
final readonly class VerificationStatusResult
{
    public function __construct(
        public int $status,
        public string $message,
        public array $raw = [],
    ) {}

    public function toArray(): array
    {
        return [
            'status' => $this->status,
            'msg' => $this->message,
        ];
    }
}
