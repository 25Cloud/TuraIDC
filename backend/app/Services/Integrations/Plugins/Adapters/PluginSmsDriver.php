<?php

declare(strict_types=1);

namespace App\Services\Integrations\Plugins\Adapters;

use App\Exceptions\BusinessException;
use App\Services\Integrations\Plugins\PluginManifest;
use App\Services\Integrations\Plugins\PluginRuntimeRegistry;
use App\Services\Sms\Contracts\ProvidesVerifyCodeTemplate;
use App\Services\Sms\Contracts\SmsDriver;
use App\Services\Sms\Data\SmsMessageRequest;
use App\Services\Sms\Data\SmsSendRequest;
use App\Services\Sms\Data\SmsSendResult;

final readonly class PluginSmsDriver implements ProvidesVerifyCodeTemplate, SmsDriver
{
    public function __construct(
        private PluginRuntimeRegistry $runtime,
        private PluginManifest $manifest,
    ) {}

    public function key(): string
    {
        return $this->manifest->key;
    }

    public function label(): string
    {
        return $this->manifest->name;
    }

    public function sendMessage(SmsMessageRequest $request): SmsSendResult
    {
        $result = $this->runtime->execute($this->manifest->domain, $this->manifest->slug, 'sms.send_message', [
            'phone' => $request->phone,
            'template_code' => $request->templateCode,
            'content' => $request->content,
            'options' => $request->options,
        ]);

        return $this->normalizeResult($result);
    }

    public function sendVerifyCode(SmsSendRequest $request): SmsSendResult
    {
        $result = $this->runtime->execute($this->manifest->domain, $this->manifest->slug, 'sms.send_verify_code', [
            'phone' => $request->phone,
            'code' => $request->code,
            'options' => $request->options,
        ]);

        return $this->normalizeResult($result);
    }

    public function verifyCodeTemplate(string $purpose): string
    {
        $result = $this->runtime->execute($this->manifest->domain, $this->manifest->slug, 'sms.verify_code_template', [
            'purpose' => $purpose,
        ]);
        $data = is_array($result['data'] ?? null) ? $result['data'] : [];

        return (string) ($data['template'] ?? '');
    }

    /**
     * @param  array<string, mixed>  $result
     */
    private function normalizeResult(array $result): SmsSendResult
    {
        $data = is_array($result['data'] ?? null) ? $result['data'] : [];

        if (($result['success'] ?? true) === false || ($data['success'] ?? true) === false) {
            throw new BusinessException((string) ($data['message'] ?? $result['message'] ?? '短信发送失败，请稍后重试'), 42200);
        }

        return new SmsSendResult(
            status: (string) ($data['status'] ?? 'success'),
            requestId: isset($data['request_id']) ? (string) $data['request_id'] : null,
            bizId: isset($data['biz_id']) ? (string) $data['biz_id'] : null,
            templateCode: (string) ($data['template_code'] ?? ''),
            templateParams: is_array($data['template_params'] ?? null) ? $data['template_params'] : [],
            raw: is_array($data['raw'] ?? null) ? $data['raw'] : $data,
        );
    }
}
