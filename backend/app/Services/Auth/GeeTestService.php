<?php

namespace App\Services\Auth;

use App\Exceptions\BusinessException;
use App\Services\Integrations\Plugins\IntegrationDriverBindingResolver;
use App\Services\Integrations\Plugins\PluginDomain;
use App\Services\Integrations\Plugins\PluginRuntimeRegistry;
use Illuminate\Support\Facades\Log;

class GeeTestService
{
    private const SCRIPT_PROXY_PATH = '/api/v2/client/auth/captcha-script';

    /** 插件未声明 render_mode 时的默认形态：由插件自行弹窗 */
    private const DEFAULT_RENDER_MODE = 'popup';

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

    /**
     * 当前生效的验证码提供商标识（geetest / vaptcha / corptcha / cap / turnstile ...）。
     *
     * 各插件的 captcha.config 一直都在返回这个字段，但此前被 captchaConfig() 丢弃了，
     * 前端因此只能假定是极验。下发它之后前端可以按提供商做差异化文案与样式。
     * 未启用任何插件时返回空串。
     */
    public function getProvider(): string
    {
        return (string) ($this->captchaConfig()['provider'] ?? '');
    }

    /**
     * 组件渲染形态，决定前端把验证组件放在哪里。
     *
     * - popup：插件自带浮层交互（极验等），前端不提供容器，插件自行弹窗；
     * - inline：插件只提供内联 widget（Cloudflare Turnstile、Cap 自绘卡片），
     *   前端在提交按钮上方给出容器，点击提交时就地加载。
     *
     * 两种形态都是「点击提交才加载」，差别只在渲染位置。
     */
    public function getRenderMode(): string
    {
        return (string) ($this->captchaConfig()['render_mode'] ?? self::DEFAULT_RENDER_MODE);
    }

    /**
     * 适配层脚本的版本标识（配置指纹）。
     *
     * captcha-script 端点带 12 小时强缓存，而脚本内容是按插件配置生成的。
     * 前端把这个值并入脚本 URL 的查询参数，配置一改指纹就变，缓存随之失效。
     */
    public function getScriptVersion(): string
    {
        return (string) ($this->captchaConfig()['script_version'] ?? '');
    }

    /**
     * 插件自定义的前端初始化参数（如 Cap 的 api_endpoint），未提供则为空字符串。
     */
    public function getApiEndpoint(): string
    {
        return (string) ($this->captchaConfig()['api_endpoint'] ?? '');
    }

    public function getScriptUrl(): string
    {
        return self::SCRIPT_PROXY_PATH;
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
                'provider' => '',
                'render_mode' => self::DEFAULT_RENDER_MODE,
                'script_version' => '',
                'script_url' => $this->getScriptUrl(),
            ];
        }

        $result = $this->executePlugin('captcha.config');
        $data = is_array($result['data'] ?? null) ? $result['data'] : [];

        $config = [
            'enabled' => (bool) ($result['success'] ?? false) && (bool) ($data['enabled'] ?? false),
            'captcha_id' => (string) ($data['captcha_id'] ?? ''),
            // 插件未显式声明时退回驱动键，保证这个字段总有值可用
            'provider' => (string) ($data['provider'] ?? $this->activeDriver()),
            'render_mode' => $this->normalizeRenderMode($data['render_mode'] ?? null),
            // 插件未声明时回退到 captcha_id，与既有行为一致：
            // 那些脚本内容不随配置变化的插件（如极验只是代理官方 SDK），
            // 仅在换了 captcha_id 时才需要让前端重新拉取。
            'script_version' => (string) ($data['script_version'] ?? ($data['captcha_id'] ?? '')),
            'script_url' => $this->getScriptUrl(),
        ];

        // 透传插件自定义的前端初始化参数（如 Cap 的 api_endpoint）
        $apiEndpoint = trim((string) ($data['api_endpoint'] ?? ''));
        if ($apiEndpoint !== '') {
            $config['api_endpoint'] = $apiEndpoint;
        }

        return $this->captchaConfigCache = $config;
    }

    private function normalizeRenderMode(mixed $value): string
    {
        $mode = is_string($value) ? trim($value) : '';

        return in_array($mode, ['popup', 'inline'], true) ? $mode : self::DEFAULT_RENDER_MODE;
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
