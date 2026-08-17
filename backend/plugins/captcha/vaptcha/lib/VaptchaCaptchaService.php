<?php

declare(strict_types=1);

namespace TuraIDC\Plugins\Captcha\Vaptcha\Lib;

use App\Support\CacheKey;
use App\Support\SensitiveDataSanitizer;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class VaptchaCaptchaService
{
    private const DEFAULT_VERIFY_ENDPOINT = 'https://v4i.vaptcha.com/api/v1/verify';

    private const DEFAULT_SDK_URL = 'https://cdn4.vaptcha.com/src/v4.js';

    public function key(): string
    {
        return 'vaptcha';
    }

    public function label(): string
    {
        return 'VAPTCHA 智能人机验证';
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
                'provider' => 'vaptcha',
                'enabled' => $this->isConfigured($config),
                'captcha_id' => $this->vid($config),
                'vid' => $this->vid($config),
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
            return $this->failure($action, 'VAPTCHA 插件配置不完整');
        }

        $token = $this->stringFrom($payload, ['token', 'vaptcha_token']);
        $knock = $this->stringFrom($payload, ['knock', 'vaptcha_knock']);
        $dfu = $this->stringFrom($payload, ['dfu', 'vaptcha_dfu']);
        $ip = $this->stringFrom($payload, ['_client_ip', 'client_ip', 'ip']);

        if ($token === '' || $knock === '') {
            return $this->failure($action, '行为验证参数不完整');
        }

        if ($ip === '') {
            return $this->failure($action, '行为验证 IP 缺失，请稍后重试');
        }

        $replayKey = $this->replayCacheKey($config, $token, $knock, $dfu, $ip);
        if (Cache::has($replayKey)) {
            return $this->failure($action, '行为验证已使用，请重新验证', ['verified' => false]);
        }

        $requestPayload = [
            'vid' => $this->vid($config),
            'vkey' => $this->vkey($config),
            'token' => $token,
            'knock' => $knock,
            'dfu' => $dfu,
            'ip' => $ip,
        ];

        try {
            $response = Http::asJson()
                ->timeout($this->requestTimeout($config))
                ->post($this->verifyEndpoint($config), $requestPayload);

            if (! $response->successful()) {
                Log::warning('[captcha:vaptcha] verify request failed', [
                    'status' => $response->status(),
                    'body' => SensitiveDataSanitizer::sanitizeText($response->body()),
                ]);

                return $this->failure($action, '行为验证服务暂时不可用，请稍后重试');
            }

            $data = $response->json();
            if (! is_array($data)) {
                return $this->failure($action, '行为验证服务响应异常，请稍后重试');
            }

            $result = is_array($data['data'] ?? null) ? $data['data'] : [];
            $codePassed = array_key_exists('code', $data) && (int) $data['code'] === 0;
            $verified = $this->truthy($result['result'] ?? false);
            $responseVid = trim((string) ($result['vid'] ?? ''));
            $vidMatches = $responseVid === '' || hash_equals($this->vid($config), $responseVid);

            if (! $codePassed || ! $verified || ! $vidMatches) {
                return $this->failure($action, '行为验证未通过，请重试', ['verified' => false]);
            }

            if (! Cache::add($replayKey, true, now()->addSeconds($this->replayTtlSeconds($config)))) {
                return $this->failure($action, '行为验证已使用，请重新验证', ['verified' => false]);
            }

            return [
                'success' => true,
                'action' => $action,
                'message' => '',
                'data' => ['verified' => true],
                'raw' => $data,
            ];
        } catch (\Throwable $exception) {
            Log::warning('[captcha:vaptcha] verify exception', [
                'message' => $exception->getMessage(),
                'exception' => $exception::class,
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
            'data' => ['content' => $this->adapterScript($this->sdkUrl($config))],
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
        return $this->vid($config) !== '' && $this->vkey($config) !== '';
    }

    /**
     * @param  array<string, mixed>  $config
     */
    private function vid(array $config): string
    {
        return trim((string) ($config['vid'] ?? ''));
    }

    /**
     * @param  array<string, mixed>  $config
     */
    private function vkey(array $config): string
    {
        return trim((string) ($config['vkey'] ?? ''));
    }

    /**
     * @param  array<string, mixed>  $config
     */
    private function verifyEndpoint(array $config): string
    {
        $endpoint = trim((string) ($config['verify_endpoint'] ?? ''));

        return $this->isHttpUrl($endpoint) ? $endpoint : self::DEFAULT_VERIFY_ENDPOINT;
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
    private function requestTimeout(array $config): int
    {
        $seconds = (int) ($config['request_timeout'] ?? 10);

        return max(1, min(30, $seconds));
    }

    /**
     * @param  array<string, mixed>  $config
     */
    private function replayTtlSeconds(array $config): int
    {
        $seconds = (int) ($config['replay_ttl_seconds'] ?? 3);

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

    private function truthy(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }

        if (is_numeric($value)) {
            return (int) $value === 1;
        }

        return in_array(strtolower(trim((string) $value)), ['1', 'true', 'success', 'yes'], true);
    }

    /**
     * @param  array<string, mixed>  $config
     */
    private function replayCacheKey(array $config, string $token, string $knock, string $dfu, string $ip): string
    {
        return CacheKey::vaptchaVerifiedToken(hash('sha256', implode('|', [
            $this->vid($config),
            $ip,
            $dfu,
            $knock,
            $token,
        ])));
    }

    private function adapterScript(string $sdkUrl): string
    {
        $encodedSdkUrl = json_encode($sdkUrl, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);

        return str_replace('__VAPTCHA_SDK_URL__', $encodedSdkUrl, <<<'JS'
(function (global) {
    var SDK_URL = __VAPTCHA_SDK_URL__;
    var VERIFY_PAGE_URL = 'https://cdn4.vaptcha.com/src/verify.html';
    var sdkPromise = null;

    function loadSdk() {
        if (global.vaptcha) {
            return Promise.resolve(global.vaptcha);
        }

        if (sdkPromise) {
            return sdkPromise;
        }

        sdkPromise = new Promise(function (resolve, reject) {
            var existing = document.querySelector('script[data-vaptcha-sdk="v4"]');
            if (existing) {
                existing.addEventListener('load', function () { resolve(global.vaptcha); }, { once: true });
                existing.addEventListener('error', function () { reject(new Error('VAPTCHA 脚本加载失败')); }, { once: true });
                return;
            }

            var script = document.createElement('script');
            script.src = SDK_URL;
            script.async = true;
            script.defer = true;
            script.dataset.vaptchaSdk = 'v4';
            script.onload = function () {
                if (global.vaptcha) {
                    resolve(global.vaptcha);
                    return;
                }

                reject(new Error('VAPTCHA SDK 未初始化'));
            };
            script.onerror = function () { reject(new Error('VAPTCHA 脚本加载失败')); };
            document.head.appendChild(script);
        });

        return sdkPromise;
    }

    function patchVerifyPageUrl() {
        var core = global.VaptchaCore;
        if (!core || !core.prototype || typeof core.prototype.buildVerifyPageUrl !== 'function') {
            return;
        }
        if (core.prototype.__turaidcVerifyPagePatch) {
            return;
        }

        var original = core.prototype.buildVerifyPageUrl;
        core.prototype.buildVerifyPageUrl = function (mode, display) {
            var url = original.call(this, mode, display);
            try {
                var parsed = new URL(url, window.location.href);
                // Force popup display to prevent full-page redirect on mobile
                parsed.searchParams.set('display', 'popup');
                if (parsed.searchParams.get('mode') === 'mobile') {
                    parsed.searchParams.set('mode', 'pc');
                }
                if (parsed.origin === window.location.origin && parsed.pathname === '/src/verify.html') {
                    parsed = new URL(VERIFY_PAGE_URL + parsed.search);
                    return parsed.toString();
                }
            } catch (error) {
                return url;
            }

            return url;
        };
        core.prototype.__turaidcVerifyPagePatch = true;
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
            return 'en';
        }

        return value || 'zh-CN';
    }

    function ensureContainer(options) {
        var configured = options.container;
        if (typeof configured === 'string' && document.querySelector(configured)) {
            return configured;
        }

        if (configured && configured.nodeType === 1) {
            return configured;
        }

        // VAPTCHA SDK requires a container element; create a hidden one
        var element = document.createElement('div');
        var id = 'vaptcha-container-' + Date.now() + '-' + Math.random().toString(16).slice(2);
        element.id = id;
        element.style.position = 'fixed';
        element.style.left = '0';
        element.style.top = '0';
        element.style.width = '1px';
        element.style.height = '1px';
        element.style.opacity = '0';
        element.style.pointerEvents = 'none';
        document.body.appendChild(element);

        return '#' + id;
    }

    function pickResult(vaptchaObj) {
        var result = vaptchaObj && typeof vaptchaObj.getVerifyResult === 'function'
            ? vaptchaObj.getVerifyResult()
            : null;

        if (!result || !result.token || !result.knock) {
            return null;
        }

        return {
            token: String(result.token || ''),
            knock: String(result.knock || ''),
            dfu: String(result.dfu || ''),
            provider: 'vaptcha'
        };
    }

    global.initGeetest4 = function (options, callback) {
        options = options || {};

        var readyCallbacks = [];
        var successCallbacks = [];
        var errorCallbacks = [];
        var closeCallbacks = [];
        var vaptchaObj = null;
        var lastResult = null;
        var container = null;

        var instance = {
            onReady: function (fn) {
                if (typeof fn === 'function') {
                    readyCallbacks.push(fn);
                    if (vaptchaObj) {
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
                if (!vaptchaObj || typeof vaptchaObj.validate !== 'function') {
                    emit(errorCallbacks, new Error('行为验证组件初始化中，请稍后重试'));
                    return instance;
                }

                Promise.resolve(vaptchaObj.validate())
                    .then(function () {
                        lastResult = pickResult(vaptchaObj);
                        if (!lastResult) {
                            throw new Error('请先完成行为验证');
                        }
                        emit(successCallbacks);
                    })
                    .catch(function (error) {
                        emit(closeCallbacks);
                        emit(errorCallbacks, error instanceof Error ? error : new Error('行为验证失败，请重试'));
                    });

                return instance;
            },
            getValidate: function () {
                return lastResult;
            },
            reset: function () {
                lastResult = null;
                if (vaptchaObj && typeof vaptchaObj.reset === 'function') {
                    vaptchaObj.reset();
                }
                return instance;
            },
            destroy: function () {
                if (vaptchaObj && typeof vaptchaObj.destroy === 'function') {
                    vaptchaObj.destroy();
                }

                if (typeof container === 'string') {
                    var element = document.querySelector(container);
                    if (element && element.parentNode) {
                        element.parentNode.removeChild(element);
                    }
                }
                emit(closeCallbacks);
                return instance;
            }
        };

        if (typeof callback === 'function') {
            callback(instance);
        }

        loadSdk()
            .then(function (vaptcha) {
                var vid = options.captchaId || options.captcha_id || options.vid;
                if (!vid) {
                    throw new Error('VAPTCHA VID 不能为空');
                }

                // Patch verify page URL before creating instance
                patchVerifyPageUrl();

                container = ensureContainer(options);

                return vaptcha({
                    vid: String(vid),
                    container: container,
                    lang: normalizeLang(options.language || options.lang),
                    area: options.area,
                    style: options.style,
                    color: options.color
                });
            })
            .then(function (created) {
                vaptchaObj = created;
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
}
