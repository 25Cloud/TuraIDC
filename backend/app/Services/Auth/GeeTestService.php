<?php

namespace App\Services\Auth;

use App\Exceptions\BusinessException;
use App\Services\Integrations\Plugins\IntegrationDriverBindingResolver;
use App\Services\Integrations\Plugins\PluginDomain;
use App\Services\Integrations\Plugins\PluginRuntimeRegistry;
use Illuminate\Support\Facades\Log;

class GeeTestService
{
    private const CLIENT_SCRIPT_PROXY_PATH = '/api/v2/client/auth/captcha-script';

    private const ADMIN_SCRIPT_PROXY_PATH = '/api/v2/admin/auth/captcha-script';

    private ?array $captchaConfigCache = null;

    public function __construct(
        private ?PluginRuntimeRegistry $runtimeRegistry = null,
        private ?IntegrationDriverBindingResolver $bindingResolver = null,
    ) {}

    public function isEnabled(): bool
    {
        if ($this->activeDriver() === '') {
            return false;
        }

        $config = $this->captchaConfig();

        return (bool) ($config['enabled'] ?? false)
            && $this->getCaptchaId() !== '';
    }

    public function getCaptchaId(): string
    {
        return (string) ($this->captchaConfig()['captcha_id'] ?? '');
    }

    public function getProvider(): string
    {
        return $this->activeDriver();
    }

    public function getConfigCacheKey(): string
    {
        $config = $this->captchaConfig();

        return substr(hash('sha256', implode('|', [
            $this->getProvider(),
            (string) ($config['captcha_id'] ?? ''),
            (string) ($config['config_version'] ?? ''),
        ])), 0, 24);
    }

    public function getScriptUrl(): string
    {
        return self::CLIENT_SCRIPT_PROXY_PATH;
    }

    public function getAdminScriptUrl(): string
    {
        return self::ADMIN_SCRIPT_PROXY_PATH;
    }

    public function getScriptContent(): string
    {
        $result = $this->executePlugin('captcha.script');
        if (! (bool) ($result['success'] ?? false)) {
            throw new \RuntimeException((string) ($result['message'] ?? '行为验证脚本暂时不可用'));
        }

        $content = (string) ($result['data']['content'] ?? '');
        if ($content === '') {
            throw new \RuntimeException('行为验证脚本内容为空');
        }

        return $content;
    }

    public function getFallbackScriptContent(): string
    {
        return <<<'JS'
window.__TURA_GEETEST_FALLBACK__ = true;
window.initGeetest4 = window.initGeetest4 || function (options, callback) {
    var errorCallbacks = [];
    var instance = {
        appendTo: function () { return instance; },
        onReady: function (fn) { if (typeof fn === 'function') { fn(); } return instance; },
        onSuccess: function () { return instance; },
        onError: function (fn) { if (typeof fn === 'function') { errorCallbacks.push(fn); } return instance; },
        onClose: function () { return instance; },
        showCaptcha: function () {
            errorCallbacks.forEach(function (fn) { fn(new Error('行为验证脚本暂时不可用')); });
            return instance;
        },
        getValidate: function () { return null; },
        reset: function () { return instance; },
        destroy: function () { return instance; }
    };

    if (typeof callback === 'function') {
        callback(instance);
    }

    return instance;
};
JS;
    }

    public function verify(mixed $payload, ?string $clientIp = null): array
    {
        if (! $this->isEnabled()) {
            return ['ok' => true];
        }

        if (! is_array($payload)) {
            return ['ok' => false, 'message' => '请先完成行为验证'];
        }

        $payload['_client_ip'] = trim((string) ($clientIp ?? ''));

        $result = $this->executePlugin('captcha.verify', $payload);
        if (! (bool) ($result['success'] ?? false) || ! (bool) ($result['data']['verified'] ?? false)) {
            return [
                'ok' => false,
                'message' => (string) ($result['message'] ?? '行为验证未通过，请重试'),
            ];
        }

        return ['ok' => true];
    }

    private function captchaConfig(): array
    {
        if ($this->captchaConfigCache !== null) {
            return $this->captchaConfigCache;
        }

        if ($this->activeDriver() === '') {
            return $this->captchaConfigCache = [
                'enabled' => false,
                'captcha_id' => '',
                'config_version' => 'disabled',
                'script_url' => $this->getScriptUrl(),
            ];
        }

        $result = $this->executePlugin('captcha.config');
        $data = is_array($result['data'] ?? null) ? $result['data'] : [];

        return $this->captchaConfigCache = [
            'enabled' => (bool) ($result['success'] ?? false) && (bool) ($data['enabled'] ?? false),
            'captcha_id' => (string) ($data['captcha_id'] ?? ''),
            'config_version' => substr(hash('sha256', json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)), 0, 16),
            'script_url' => $this->getScriptUrl(),
        ];
    }

    private function executePlugin(string $action, array $payload = []): array
    {
        $driver = $this->activeDriver();
        if ($driver === '') {
            return [
                'success' => false,
                'message' => '未启用人机验证插件',
                'data' => [],
            ];
        }

        try {
            return $this->runtime()->execute(
                domain: PluginDomain::CAPTCHA,
                slugOrKey: $driver,
                action: $action,
                payload: $payload,
            );
        } catch (BusinessException $exception) {
            Log::warning('[captcha] plugin business exception', [
                'driver' => $driver,
                'action' => $action,
                'message' => $exception->getMessage(),
            ]);

            return [
                'success' => false,
                'message' => '行为验证服务暂时不可用，请稍后重试',
                'data' => [],
            ];
        } catch (\Throwable $exception) {
            Log::error('[captcha] plugin execute failed', [
                'driver' => $driver,
                'action' => $action,
                'message' => $exception->getMessage(),
                'exception' => $exception::class,
            ]);

            return [
                'success' => false,
                'message' => '行为验证服务暂时不可用，请稍后重试',
                'data' => [],
            ];
        }
    }

    private function activeDriver(): string
    {
        return $this->bindingResolver()->captchaDriverKey();
    }

    private function runtime(): PluginRuntimeRegistry
    {
        if (! $this->runtimeRegistry instanceof PluginRuntimeRegistry) {
            $this->runtimeRegistry = app(PluginRuntimeRegistry::class);
        }

        return $this->runtimeRegistry;
    }

    private function bindingResolver(): IntegrationDriverBindingResolver
    {
        return $this->bindingResolver ??= app(IntegrationDriverBindingResolver::class);
    }
}
