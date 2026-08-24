<?php

declare(strict_types=1);

namespace TuraIDC\Plugins\Captcha\Corptcha\Lib;

use App\Support\SensitiveDataSanitizer;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class CorptchaCaptchaService
{
    private const DEFAULT_API_BASE_URL = 'https://cpt-api.25y.cn';

    private const DEFAULT_SDK_URL = 'https://res.25y.cn/corptcha/corptcha.iife.js';

    public function key(): string
    {
        return 'corptcha';
    }

    public function label(): string
    {
        return 'Corptcha 人机验证';
    }

    /**
     * @param  array<string, mixed>  $request
     * @return array<string, mixed>
     */
    public function execute(array $request): array
    {
        $action = trim((string) ($request['action'] ?? ''));
        $payload = is_array($request['payload'] ?? null) ? $request['payload'] : [];
        $config = is_array($request['config'] ?? null) ? $request['config'] : [];

        return match ($action) {
            'captcha.config' => $this->configResult($action, $config),
            'captcha.verify' => $this->verify($action, $payload, $config),
            'captcha.script' => $this->script($action, $config),
            default => $this->failure($action, '不支持的人机验证动作'),
        };
    }

    /**
     * @param  array<string, mixed>  $config
     * @return array<string, mixed>
     */
    private function configResult(string $action, array $config): array
    {
        return [
            'success' => true,
            'action' => $action,
            'message' => '',
            'data' => [
                'provider' => 'corptcha',
                'enabled' => $this->isConfigured($config),
                'captcha_id' => $this->siteKey($config),
                'render_mode' => 'inline',
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  array<string, mixed>  $config
     * @return array<string, mixed>
     */
    private function verify(string $action, array $payload, array $config): array
    {
        if (! $this->isConfigured($config)) {
            return $this->failure($action, 'Corptcha 插件配置不完整');
        }

        $token = $this->stringFrom($payload, ['token', 'corptcha_token', 'captcha_token']);
        if ($token === '') {
            return $this->failure($action, '行为验证参数不完整');
        }

        $endpoint = $this->verifyEndpoint($config);
        $purpose = $this->stringFrom($payload, ['purpose']) ?: $this->purpose($config);

        try {
            $response = Http::asJson()
                ->timeout($this->requestTimeout($config))
                ->withToken($this->secret($config))
                ->post($endpoint, [
                    'token' => $token,
                    'purpose' => $purpose,
                    'siteKey' => $this->siteKey($config),
                ]);

            if (! $response->successful()) {
                Log::warning('[captcha:corptcha] verify request failed', [
                    'status' => $response->status(),
                    'body' => SensitiveDataSanitizer::sanitizeText($response->body()),
                ]);

                return $this->failure($action, '行为验证服务暂时不可用，请稍后重试', [
                    'error_type' => 'upstream_http_error',
                    'status' => $response->status(),
                ]);
            }

            $data = $response->json();
            if (! is_array($data) || ($data['success'] ?? false) !== true) {
                return $this->failure($action, '行为验证未通过，请重试', ['verified' => false]);
            }

            return [
                'success' => true,
                'action' => $action,
                'message' => '',
                'data' => ['verified' => true],
                'raw' => $data,
            ];
        } catch (\Throwable $exception) {
            Log::warning('[captcha:corptcha] verify exception', [
                'message' => $exception->getMessage(),
                'exception' => $exception::class,
                'endpoint' => $endpoint,
            ]);

            return $this->failure($action, '行为验证服务暂时不可用，请稍后重试');
        }
    }

    /**
     * @param  array<string, mixed>  $config
     * @return array<string, mixed>
     */
    private function script(string $action, array $config): array
    {
        return [
            'success' => true,
            'action' => $action,
            'message' => '',
            'data' => ['content' => $this->adapterScript($config)],
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function failure(string $action, string $message, array $data = []): array
    {
        return [
            'success' => false,
            'action' => $action,
            'message' => $message,
            'data' => $data,
        ];
    }

    /**
     * @param  array<string, mixed>  $config
     */
    private function isConfigured(array $config): bool
    {
        return $this->siteKey($config) !== '' && $this->secret($config) !== '';
    }

    /**
     * @param  array<string, mixed>  $config
     */
    private function siteKey(array $config): string
    {
        return trim((string) ($config['site_key'] ?? ''));
    }

    /**
     * @param  array<string, mixed>  $config
     */
    private function secret(array $config): string
    {
        return trim((string) ($config['secret'] ?? ''));
    }

    /**
     * @param  array<string, mixed>  $config
     */
    private function verifyEndpoint(array $config): string
    {
        $baseUrl = trim((string) ($config['api_base_url'] ?? ''));

        return rtrim($this->isHttpUrl($baseUrl) ? $baseUrl : self::DEFAULT_API_BASE_URL, '/').'/v1/verify';
    }

    /**
     * @param  array<string, mixed>  $config
     */
    private function sdkUrl(array $config): string
    {
        $sdkUrl = trim((string) ($config['sdk_url'] ?? ''));

        return $this->isHttpUrl($sdkUrl) ? $sdkUrl : self::DEFAULT_SDK_URL;
    }

    /**
     * @param  array<string, mixed>  $config
     */
    private function purpose(array $config): string
    {
        $purpose = trim((string) ($config['purpose'] ?? ''));

        return $purpose !== '' ? $purpose : 'login';
    }

    /**
     * @param  array<string, mixed>  $config
     */
    private function language(array $config): string
    {
        $language = trim((string) ($config['language'] ?? ''));

        return in_array($language, ['zh-CN', 'en-US'], true) ? $language : 'zh-CN';
    }

    /**
     * @param  array<string, mixed>  $config
     */
    private function themeMode(array $config): string
    {
        $mode = trim((string) ($config['theme_mode'] ?? ''));

        return in_array($mode, ['auto', 'light', 'dark'], true) ? $mode : 'auto';
    }

    /**
     * @param  array<string, mixed>  $config
     */
    private function requestTimeout(array $config): int
    {
        $seconds = (int) ($config['request_timeout'] ?? 10);

        return max(1, min(30, $seconds));
    }

    private function isHttpUrl(string $url): bool
    {
        if ($url === '') {
            return false;
        }

        $scheme = parse_url($url, PHP_URL_SCHEME);

        return in_array($scheme, ['http', 'https'], true);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  array<int, string>  $keys
     */
    private function stringFrom(array $payload, array $keys): string
    {
        foreach ($keys as $key) {
            if (array_key_exists($key, $payload)) {
                return trim((string) ($payload[$key] ?? ''));
            }
        }

        return '';
    }

    /**
     * 生成与 Geetest v4 兼容的 initGeetest4 适配脚本：
     * 前端通过统一接口初始化，内部渲染 Corptcha 组件，验证通过后回传一次性 token。
     *
     * @param  array<string, mixed>  $config
     */
    private function adapterScript(array $config): string
    {
        $sdkUrl = json_encode($this->sdkUrl($config), JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        $apiBaseUrl = json_encode($this->verifyEndpointBase($config), JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        $siteKey = json_encode($this->siteKey($config), JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        $purpose = json_encode($this->purpose($config), JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        $language = json_encode($this->language($config), JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        $themeMode = json_encode($this->themeMode($config), JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);

        return str_replace(
            ['__CORPTCHA_SDK_URL__', '__CORPTCHA_API_BASE_URL__', '__CORPTCHA_SITE_KEY__', '__CORPTCHA_PURPOSE__', '__CORPTCHA_LANGUAGE__', '__CORPTCHA_THEME_MODE__'],
            [$sdkUrl, $apiBaseUrl, $siteKey, $purpose, $language, $themeMode],
            <<<'JS'
(function (global) {
    var SDK_URL = __CORPTCHA_SDK_URL__;
    var API_BASE_URL = __CORPTCHA_API_BASE_URL__;
    var SITE_KEY = __CORPTCHA_SITE_KEY__;
    var PURPOSE = __CORPTCHA_PURPOSE__;
    var LANGUAGE = __CORPTCHA_LANGUAGE__;
    var THEME_MODE = __CORPTCHA_THEME_MODE__;
    var sdkPromise = null;

    function loadSdk() {
        if (global.Corptcha) {
            return Promise.resolve(global.Corptcha);
        }

        if (sdkPromise) {
            return sdkPromise;
        }

        sdkPromise = new Promise(function (resolve, reject) {
            var existing = document.querySelector('script[data-corptcha-sdk="sdk"]');
            if (existing) {
                existing.addEventListener('load', function () { resolve(global.Corptcha); }, { once: true });
                existing.addEventListener('error', function () { reject(new Error('Corptcha 脚本加载失败')); }, { once: true });
                return;
            }

            var script = document.createElement('script');
            script.src = SDK_URL;
            script.async = true;
            script.defer = true;
            script.dataset.corptchaSdk = 'sdk';
            script.onload = function () {
                if (global.Corptcha) {
                    resolve(global.Corptcha);
                    return;
                }

                reject(new Error('Corptcha SDK 未初始化'));
            };
            script.onerror = function () { reject(new Error('Corptcha 脚本加载失败')); };
            document.head.appendChild(script);
        });

        return sdkPromise;
    }

    function emit(callbacks, value) {
        callbacks.slice().forEach(function (callback) {
            try {
                callback(value);
            } catch (error) {
                setTimeout(function () { throw error; }, 0);
            }
        });
    }

    function normalizeLang(value) {
        var lang = String(value || '').toLowerCase();
        if (lang === 'zho' || lang === 'zh' || lang === 'zh-cn') {
            return 'zh-CN';
        }
        if (lang === 'eng' || lang === 'en' || lang === 'en-us') {
            return 'en-US';
        }

        return value || LANGUAGE;
    }

    function resolveContainer(options) {
        // 优先使用前端传入的容器（options.appendTo / options.container）
        var configured = options.appendTo || options.container;
        if (typeof configured === 'string') {
            configured = document.querySelector(configured);
        }
        if (configured && configured.nodeType === 1) {
            return { element: configured, autoCreated: false };
        }

        // 兜底：创建可见浮动容器（不再隐藏，避免组件不可见不可点）
        var element = document.createElement('div');
        var id = 'corptcha-container-' + Date.now() + '-' + Math.random().toString(16).slice(2);
        element.id = id;
        element.style.cssText = 'position:fixed;bottom:24px;left:50%;transform:translateX(-50%);z-index:2147483000;';
        document.body.appendChild(element);

        return { element: element, autoCreated: true };
    }

    global.initGeetest4 = function (options, callback) {
        options = options || {};

        var readyCallbacks = [];
        var successCallbacks = [];
        var errorCallbacks = [];
        var closeCallbacks = [];
        var widget = null;
        var lastResult = null;
        var container = null;
        var containerAutoCreated = false;

        var instance = {
            onReady: function (fn) {
                if (typeof fn === 'function') {
                    readyCallbacks.push(fn);
                    if (widget) {
                        fn();
                    }
                }
                return instance;
            },
            onSuccess: function (fn) {
                if (typeof fn === 'function') {
                    successCallbacks.push(fn);
                }
                return instance;
            },
            onError: function (fn) {
                if (typeof fn === 'function') {
                    errorCallbacks.push(fn);
                }
                return instance;
            },
            onClose: function (fn) {
                if (typeof fn === 'function') {
                    closeCallbacks.push(fn);
                }
                return instance;
            },
            showCaptcha: function () {
                if (!widget || typeof widget.execute !== 'function') {
                    emit(errorCallbacks, new Error('行为验证组件初始化中，请稍后重试'));
                    return instance;
                }

                widget.execute();
                return instance;
            },
            getValidate: function () {
                return lastResult;
            },
            reset: function () {
                lastResult = null;
                if (widget && typeof widget.reset === 'function') {
                    widget.reset();
                }
                return instance;
            },
            destroy: function () {
                if (widget && typeof widget.destroy === 'function') {
                    widget.destroy();
                }

                if (container && containerAutoCreated && container.parentNode) {
                    container.parentNode.removeChild(container);
                }
                emit(closeCallbacks);
                return instance;
            }
        };

        if (typeof callback === 'function') {
            callback(instance);
        }

        loadSdk()
            .then(function (Corptcha) {
                var siteKey = options.captchaId || options.captcha_id || options.siteKey || SITE_KEY;
                if (!siteKey) {
                    throw new Error('Corptcha Site ID 不能为空');
                }

                var resolvedContainer = resolveContainer(options);
                container = resolvedContainer.element;
                containerAutoCreated = resolvedContainer.autoCreated;

                widget = Corptcha.render(container, {
                    apiBaseUrl: options.apiBaseUrl || API_BASE_URL,
                    siteKey: String(siteKey),
                    purpose: options.purpose || PURPOSE,
                    language: normalizeLang(options.language || options.lang || LANGUAGE),
                    theme: { mode: options.themeMode || THEME_MODE },
                    autoExecute: false,
                    onSuccess: function (token) {
                        lastResult = { token: String(token || ''), provider: 'corptcha' };
                        if (!lastResult.token) {
                            emit(errorCallbacks, new Error('请先完成行为验证'));
                            return;
                        }
                        emit(successCallbacks);
                    },
                    onError: function (error) {
                        emit(errorCallbacks, error instanceof Error ? error : new Error('行为验证失败，请重试'));
                    },
                    onExpired: function () {
                        lastResult = null;
                        if (widget && typeof widget.execute === 'function') {
                            widget.execute();
                        }
                    }
                });

                emit(readyCallbacks);
            })
            .catch(function (error) {
                emit(errorCallbacks, error instanceof Error ? error : new Error('行为验证初始化失败'));
            });

        return instance;
    };
})(window);
JS);
    }

    /**
     * @param  array<string, mixed>  $config
     */
    private function verifyEndpointBase(array $config): string
    {
        $baseUrl = trim((string) ($config['api_base_url'] ?? ''));

        return rtrim($this->isHttpUrl($baseUrl) ? $baseUrl : self::DEFAULT_API_BASE_URL, '/');
    }
}
