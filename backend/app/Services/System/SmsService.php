<?php

namespace App\Services\System;

use App\Models\MessageLog;
use App\Models\Setting;
use App\Services\Integrations\Plugins\IntegrationDriverBindingResolver;
use App\Services\Integrations\Plugins\PluginConfigRepository;
use App\Services\Sms\Contracts\SmsDriver;
use App\Services\Sms\Data\SmsMessageRequest;
use App\Services\Sms\Data\SmsSendRequest;
use App\Services\Sms\SmsDriverManager;
use App\Support\SmsTemplateCatalog;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class SmsService
{
    private SmsDriverManager $driverManager;

    public function __construct(
        SmsDriverManager $driverManager,
        private ?IntegrationDriverBindingResolver $driverBindingResolver = null,
        private ?PluginConfigRepository $pluginConfigRepository = null,
        private ?NotificationTemplateService $notificationTemplateService = null,
    ) {
        $this->driverManager = $driverManager;
    }

    /**
     * @param  array<string, mixed>  $options
     */
    public function sendVerifyCode(string $phone, string $code, array $options = []): void
    {
        if (! $this->notificationTemplates()->isEnabled('sms', SmsTemplateCatalog::TEMPLATE_VERIFY_CODE)) {
            return;
        }

        $driver = $this->resolveDriverForLog();
        if (($driver?->key() ?? '') === 'aliyun') {
            $this->sendAliyunVerifyCode($phone, $code, $options, $driver);

            return;
        }

        $this->sendTemplateSms($phone, SmsTemplateCatalog::TEMPLATE_VERIFY_CODE, [
            'code' => $code,
            'min' => '5',
            'expire_minutes' => '5',
        ], array_merge($options, [
            'origin_type' => 'sms_verify',
            'fallback_provider_template_id' => $this->legacyVerificationProviderTemplateId(),
        ]));
    }

    /**
     * @param  array<string, mixed>  $options
     */
    private function sendAliyunVerifyCode(string $phone, string $code, array $options, ?SmsDriver $driver): void
    {
        $purpose = $this->normalizeVerifyCodePurpose($options);
        $sendOptions = array_merge($options, [
            'purpose' => $purpose,
            'min' => (string) ($options['min'] ?? '5'),
        ]);
        $logParams = [
            'code' => $code,
            'min' => (string) $sendOptions['min'],
            'purpose' => $purpose,
        ];

        $logContext = $this->createSmsLog(
            $phone,
            'aliyun_verify_code',
            "阿里云短信验证码已发送，验证码：{$code}",
            $logParams,
            'aliyun',
            'sms_verify'
        );

        try {
            if (! $this->isEnabled()) {
                throw new \RuntimeException('短信通知未启用');
            }

            $driver ??= $this->driverManager->resolve('aliyun');
            $result = $driver->sendVerifyCode(new SmsSendRequest($phone, $code, $sendOptions));
            $providerTemplateCode = trim($result->templateCode);
            $updatedParams = $providerTemplateCode !== ''
                ? array_merge($logParams, ['provider_template_id' => $providerTemplateCode])
                : $logParams;

            $this->updateSmsLog($logContext, [
                'status' => 'success',
                'request_id' => $result->requestId,
                'sent_at' => now(),
                'template_code' => $providerTemplateCode !== '' ? $providerTemplateCode : 'aliyun_verify_code',
                'params_json' => $updatedParams,
            ]);
        } catch (\Exception $e) {
            $this->updateSmsLog($logContext, [
                'status' => 'failed',
                'error_msg' => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    /**
     * @param  array<string, mixed>  $params
     * @param  array<string, mixed>  $options
     */
    public function sendTemplateSms(string $phone, string $templateCode, array $params = [], array $options = []): void
    {
        $templateCode = trim($templateCode);
        $template = $this->notificationTemplates()->find('sms', $templateCode);
        if (! is_array($template)) {
            throw new \RuntimeException('短信模板不存在');
        }

        if (! $this->templatePayloadIsEnabled($template)) {
            return;
        }

        $contentTemplate = $this->resolveTemplateContent($template);
        $renderParams = array_merge([
            'site_name' => $this->resolveSiteName(),
        ], $this->stringifyParams($params));
        $content = $this->renderTemplateText($contentTemplate, $renderParams);

        if ($content === '') {
            throw new \RuntimeException('短信模板内容为空');
        }

        $providerTemplateId = $this->resolveProviderTemplateId($templateCode, $template, $options);
        $driver = $this->resolveDriverForLog();
        $provider = $driver?->key() ?? 'unconfigured';
        $logParams = $this->buildLogParams($renderParams, $providerTemplateId);
        $logContent = $this->renderTemplateText($contentTemplate, $logParams);
        $logContext = $this->createSmsLog(
            $phone,
            $templateCode,
            $logContent !== '' ? $logContent : '短信已发送',
            $logParams,
            $provider,
            (string) ($options['origin_type'] ?? 'sms_template')
        );

        try {
            if (! $this->isEnabled()) {
                throw new \RuntimeException('短信通知未启用');
            }

            $driver ??= $this->driverManager->resolve();
            $result = $driver->sendMessage(new SmsMessageRequest($phone, $providerTemplateId, $content, [
                'business_template_code' => $templateCode,
                'provider_template_id' => $providerTemplateId,
            ]));

            $this->updateSmsLog($logContext, [
                'status' => 'success',
                'request_id' => $result->requestId,
                'sent_at' => now(),
            ]);
        } catch (\Exception $e) {
            $this->updateSmsLog($logContext, [
                'status' => 'failed',
                'error_msg' => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    public function getDriverManager(): SmsDriverManager
    {
        return $this->driverManager;
    }

    private function isEnabled(): bool
    {
        $value = Setting::getValue('notification', 'sms_enabled', '0');

        return in_array((string) $value, ['1', 'true', 'on'], true);
    }

    /**
     * @param  array<string, mixed>  $template
     */
    private function resolveTemplateContent(array $template): string
    {
        return (string) ($template['content'] ?? '');
    }

    /**
     * @param  array<string, mixed>  $template
     * @param  array<string, mixed>  $options
     */
    private function resolveProviderTemplateId(string $templateCode, array $template, array $options): string
    {
        $explicit = trim((string) ($options['provider_template_id'] ?? ''));
        if ($explicit !== '') {
            return $explicit;
        }

        $stored = trim((string) ($template['provider_template_id'] ?? ''));
        if ($stored !== '') {
            return $stored;
        }

        $fallback = trim((string) ($options['fallback_provider_template_id'] ?? ''));
        if ($fallback !== '') {
            return $fallback;
        }

        return $templateCode;
    }

    private function notificationTemplates(): NotificationTemplateService
    {
        return $this->notificationTemplateService ??= app(NotificationTemplateService::class);
    }

    /**
     * @param  array<string, mixed>  $template
     */
    private function templatePayloadIsEnabled(array $template): bool
    {
        return ! array_key_exists('is_enabled', $template) || (bool) $template['is_enabled'];
    }

    /**
     * @param  array<string, mixed>  $renderParams
     * @return array<string, string>
     */
    private function buildLogParams(array $renderParams, string $providerTemplateId): array
    {
        $logParams = [];

        foreach ($renderParams as $key => $value) {
            $normalizedKey = trim((string) $key);
            if ($normalizedKey === '') {
                continue;
            }

            $logParams[$normalizedKey] = (string) $value;
        }

        $logParams['provider_template_id'] = $providerTemplateId;

        return $logParams;
    }

    /**
     * @param  array<string, mixed>  $params
     * @return array{id: int|null}
     */
    private function createSmsLog(string $phone, string $templateCode, string $content, array $params, string $provider, string $originType): array
    {
        $traceId = $this->notificationTraceId('sms', $templateCode);

        try {
            $log = MessageLog::create(array_merge([
                'channel' => 'sms',
                'recipient' => $phone,
                'template_code' => $templateCode,
                'content' => $content,
                'params_json' => $params,
                'provider' => $provider,
                'status' => 'pending',
                'origin_type' => $originType,
                'origin_id' => 0,
            ], $this->smsAuditPayload($traceId, $provider)));

            return ['id' => (int) $log->getKey()];
        } catch (\Throwable $exception) {
            Log::warning('短信日志写入失败，已跳过日志写入继续发送', [
                'phone' => $phone,
                'template_code' => $templateCode,
                'message' => $exception->getMessage(),
            ]);
        }

        return ['id' => null];
    }

    /**
     * @return array<string, mixed>
     */
    private function smsAuditPayload(string $traceId, string $driverKey): array
    {
        $context = $this->driverBindingResolver()->smsContext($driverKey !== 'unconfigured' ? $driverKey : null);

        return [
            'plugin_id' => $context['plugin_id'],
            'driver_key' => $context['driver_key'],
            'trace_id' => $traceId,
        ];
    }

    private function driverBindingResolver(): IntegrationDriverBindingResolver
    {
        return $this->driverBindingResolver ??= app(IntegrationDriverBindingResolver::class);
    }

    private function legacyVerificationProviderTemplateId(): string
    {
        $settingTemplateCode = trim((string) Setting::getValue('notification', 'sms_template_code', ''));
        if ($settingTemplateCode !== '') {
            return $settingTemplateCode;
        }

        return SmsTemplateCatalog::TEMPLATE_VERIFY_CODE;
    }

    private function notificationTraceId(string $channel, string $templateCode): string
    {
        $template = trim($templateCode) !== '' ? trim($templateCode) : 'none';

        return substr($channel.':'.$template.':'.str_replace('-', '', (string) Str::uuid()), 0, 64);
    }

    private function resolveDriverForLog(): ?SmsDriver
    {
        try {
            return $this->driverManager->resolve();
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * @param  array<string, mixed>  $options
     */
    private function normalizeVerifyCodePurpose(array $options): string
    {
        $purpose = strtolower(trim((string) (
            $options['purpose']
            ?? $options['scene']
            ?? $options['type']
            ?? 'generic'
        )));

        return match ($purpose) {
            'login', 'register', 'reset', 'reset_password', 'password_reset',
            'change_phone', 'phone_change', 'update_phone', 'bind_phone', 'new_phone',
            'verify_bound_phone', 'verify_phone' => $purpose,
            default => 'generic',
        };
    }

    /**
     * @param  array<string, mixed>  $params
     * @return array<string, string>
     */
    private function stringifyParams(array $params): array
    {
        $normalized = [];

        foreach ($params as $key => $value) {
            if (! is_string($key) || trim($key) === '') {
                continue;
            }

            $normalized[trim($key)] = match (true) {
                is_string($value) => $value,
                is_int($value), is_float($value) => (string) $value,
                is_bool($value) => $value ? '1' : '',
                $value === null => '',
                default => (string) $value,
            };
        }

        return $normalized;
    }

    private function resolveSiteName(): string
    {
        $siteName = trim((string) Setting::getValue(
            'basic',
            'site_name',
            config('idc.site_name', config('app.name', '图拉云'))
        ));

        return $siteName !== '' ? $siteName : (string) config('app.name', '图拉云');
    }

    /**
     * @param  array<string, mixed>  $params
     */
    private function renderTemplateText(string $template, array $params): string
    {
        $rendered = preg_replace_callback(
            '/\{\{#([a-zA-Z0-9_]+)\}\}(.*?)\{\{\/\1\}\}/su',
            function (array $matches) use ($params) {
                $key = (string) ($matches[1] ?? '');
                $value = $params[$key] ?? '';

                return $this->hasTemplateValue($value) ? (string) ($matches[2] ?? '') : '';
            },
            $template
        );

        $rendered = preg_replace_callback(
            '/\{\{\s*([a-zA-Z0-9_]+)\s*\}\}/u',
            static fn (array $matches): string => (string) ($params[(string) ($matches[1] ?? '')] ?? ''),
            $rendered ?? $template
        );

        $rendered = preg_replace_callback(
            '/(?<!\{)\{([a-zA-Z0-9_]+)\}(?!\})/u',
            static fn (array $matches): string => (string) ($params[(string) ($matches[1] ?? '')] ?? ''),
            (string) $rendered
        );

        $rendered = preg_replace("/[ \t]+\n/u", "\n", (string) $rendered) ?? (string) $rendered;
        $rendered = preg_replace("/\n{3,}/u", "\n\n", (string) $rendered) ?? (string) $rendered;

        return trim($rendered);
    }

    private function hasTemplateValue(mixed $value): bool
    {
        return ! in_array($value, [null, '', false], true);
    }

    /**
     * @param  array{id: int|null}  $logContext
     */
    private function updateSmsLog(array $logContext, array $attributes): void
    {
        $id = isset($logContext['id']) ? (int) $logContext['id'] : 0;

        if ($id <= 0) {
            return;
        }

        try {
            MessageLog::query()->whereKey($id)->update($attributes);
        } catch (\Throwable $exception) {
            Log::warning('短信日志状态更新失败，已忽略以避免阻断发送流程', [
                'table' => 'message_logs',
                'id' => $id,
                'message' => $exception->getMessage(),
            ]);
        }
    }
}
