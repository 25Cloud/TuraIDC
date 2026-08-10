<?php

declare(strict_types=1);

namespace App\Services\Sms\Data;

/**
 * 已由业务层完成模板选择与变量替换的短信发送请求。
 */
final readonly class SmsMessageRequest
{
    public function __construct(
        public string $phone,
        public string $templateCode,
        public string $content,
        public array $options = [],
    ) {}

    public function option(string $key, mixed $default = null): mixed
    {
        return $this->options[$key] ?? $default;
    }
}
