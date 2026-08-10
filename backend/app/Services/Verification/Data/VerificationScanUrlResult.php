<?php

declare(strict_types=1);

namespace App\Services\Verification\Data;

/**
 * 实名认证扫码链接结果。
 */
final readonly class VerificationScanUrlResult
{
    public function __construct(
        public int $status,
        public string $message,
        public string $url = '',
        public array $raw = [],
    ) {}

    public function toArray(): array
    {
        return [
            'status' => $this->status,
            'msg' => $this->message,
            'url' => $this->url,
        ];
    }
}
