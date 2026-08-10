<?php

declare(strict_types=1);

namespace App\Services\Sms\Data;

/**
 * 短信发送请求数据。
 */
final readonly class SmsSendRequest
{
    public function __construct(
        public string $phone,
        public string $code,
        public array $options = [],
    ) {}

    public function option(string $key, mixed $default = null): mixed
    {
        return $this->options[$key] ?? $default;
    }
}
