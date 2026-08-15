<?php

declare(strict_types=1);

namespace App\Services\Sms\Data;

/**
 * 短信发送结果，屏蔽供应商原始响应结构。
 */
final readonly class SmsSendResult
{
    public function __construct(
        public string $status,
        public ?string $requestId = null,
        public ?string $bizId = null,
        public string $templateCode = '',
        public array $templateParams = [],
        public array $raw = [],
    ) {}

    public function toArray(): array
    {
        return [
            'status' => $this->status,
            'request_id' => $this->requestId,
            'template_code' => $this->templateCode,
            'template_params' => $this->templateParams,
        ];
    }
}
