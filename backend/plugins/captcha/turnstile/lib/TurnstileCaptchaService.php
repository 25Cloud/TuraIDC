<?php

declare(strict_types=1);

namespace TuraIDC\Plugins\Captcha\Turnstile\Lib;

use App\Services\Integrations\Plugins\PluginConfigRepository;
use App\Services\Integrations\Plugins\PluginDomain;
use App\Support\SensitiveDataSanitizer;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Cloudflare Turnstile 人机验证插件。
 *
 * 与 geetest / vaptcha 插件遵循同一契约：captcha.config / captcha.verify / captcha.script
 * 三个动作，由 App\Services\Auth\GeeTestService 统一派发。
 *
 * 前端不需要为本插件改动：captcha.script 返回的是一段适配层，它加载 Cloudflare 官方 SDK
 * 之后把 window.turnstile 包装成项目既有的 window.initGeetest4 接口（与 vaptcha 插件同样的做法）。
 */
class TurnstileCaptchaService
{
    private const VERIFY_ENDPOINT = 'https://challenges.cloudflare.com/turnstile/v0/siteverify';

    /** 官方 SDK；render=explicit 才能由适配层手动渲染到指定容器 */
    private const SDK_URL = 'https://challenges.cloudflare.com/turnstile/v0/api.js?render=explicit';

    private const DEFAULT_TIMEOUT_SECONDS = 10;

    private const DEFAULT_REPLAY_TTL_SECONDS = 300;

    /** 前端加载 Cloudflare SDK 的超时（秒）；超时即提示联系管理员 */
    private const DEFAULT_SDK_TIMEOUT_SECONDS = 15;

    public function key(): string
    {
        return 'turnstile';
    }

    public function label(): string
    {
        return 'Cloudflare Turnstile';
    }

    /**
     * 「检测」按钮的落点：真正校验配置是否可用，而不只是确认插件能加载。
     *
     * 做法是拿一个明确无效的 token 去打 siteverify。Cloudflare 会先校验 secret 再校验 token，
     * 因此返回什么错误码本身就是诊断信息：
     * - invalid-input-response  → secret 已被接受，配置正确（这是期望结果）
     * - invalid-input-secret    → Secret Key 填错了
     * - 请求抛异常              → 服务端到 Cloudflare 的网络不通（大陆环境常见）
     *
     * 这样管理员保存配置后点一下「检测」，就能立刻区分「密钥错」和「网络不通」，
     * 而不必等到真实用户登录失败才发现。
     *
     * @return array<string, mixed>
     */
    public function healthCheck(): array
    {
        $config = app(PluginConfigRepository::class)
            ->resolvedConfigByDomainAndSlug(PluginDomain::CAPTCHA, 'turnstile');

        if ($this->siteKey($config) === '') {
            return $this->unhealthy('未填写 Site Key（站点密钥）');
        }

        if ($this->secretKey($config) === '') {
            return $this->unhealthy('未填写 Secret Key（密钥）');
        }

        try {
            $response = Http::asForm()
                ->timeout($this->requestTimeout($config))
                ->post(self::VERIFY_ENDPOINT, [
                    'secret' => $this->secretKey($config),
                    'response' => 'turaidc-health-check-invalid-token',
                ]);
        } catch (\Throwable $exception) {
            $errorType = $this->classifyHttpException($exception);

            return $this->unhealthy(
                match ($errorType) {
                    'timeout' => '连接 Cloudflare 验证服务超时，请检查服务器出网或代理配置',
                    'tls_certificate_error' => '与 Cloudflare 建立 TLS 连接失败，请检查服务器的 CA 证书',
                    default => '无法连接 Cloudflare 验证服务，请检查服务器网络',
                },
                ['error_type' => $errorType, 'endpoint' => self::VERIFY_ENDPOINT]
            );
        }

        $data = $response->json();

        // 必须先解析 error-codes、再判断状态码：Cloudflare 对无效 secret 返回的是
        // HTTP 400 并把原因放在 body 里（实测 {"error-codes":["invalid-input-secret"]}）。
        // 若先拦状态码，「密钥填错」就会被误报成「服务异常」，指错方向。
        $errorCodes = is_array($data) && is_array($data['error-codes'] ?? null)
            ? array_map(static fn (mixed $code): string => (string) $code, $data['error-codes'])
            : [];

        if (array_intersect(['invalid-input-secret', 'missing-input-secret'], $errorCodes) !== []) {
            return $this->unhealthy(
                'Secret Key 无效，请核对 Cloudflare 仪表盘中该 Widget 的「密钥」',
                ['error_codes' => $errorCodes, 'status' => $response->status()]
            );
        }

        if (in_array('bad-request', $errorCodes, true)) {
            return $this->unhealthy(
                'Cloudflare 拒绝了校验请求，请核对密钥格式',
                ['error_codes' => $errorCodes, 'status' => $response->status()]
            );
        }

        if (! $response->successful()) {
            return $this->unhealthy(
                'Cloudflare 验证服务返回异常状态码：'.$response->status(),
                ['status' => $response->status(), 'error_codes' => $errorCodes]
            );
        }

        if (! is_array($data)) {
            return $this->unhealthy('Cloudflare 验证服务响应格式异常');
        }

        // 只剩「token 无效」这类错误，或测试密钥直接判定通过——两者都说明密钥与网络均正常
        return [
            'healthy' => true,
            'message' => '配置可用：Secret Key 已被 Cloudflare 接受，服务端网络可达',
            'details' => [
                'site_key' => $this->siteKey($config),
                'appearance' => $this->widgetAppearance($config),
                'render_mode' => 'inline',
                'error_codes' => $errorCodes,
                'note' => 'Site Key 是否被 Cloudflare 接受还取决于该 Widget 的域名列表是否包含本站域名，'
                    .'这一项只能在浏览器实际加载组件时才能验证。',
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $details
     * @return array<string, mixed>
     */
    private function unhealthy(string $message, array $details = []): array
    {
        return [
            'healthy' => false,
            'message' => $message,
            'details' => $details,
        ];
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
            default => [
                'success' => false,
                'action' => $action,
                'message' => '不支持的人机验证动作',
                'data' => [],
            ],
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
                'provider' => 'turnstile',
                'enabled' => $this->isConfigured($config),
                // captcha_id 是本项目下发前端的统一字段名，对 Turnstile 即 site_key
                'captcha_id' => $this->siteKey($config),
                'site_key' => $this->siteKey($config),
                // Turnstile 官方只提供内联 widget，套进弹窗会显得突兀；
                // 采用 inline：点击提交时就地在按钮上方加载组件，完成后继续提交。
                // 极验一类自带浮层交互的插件不声明此字段，走默认的 popup。
                'render_mode' => 'inline',
                // 适配层脚本由服务端按配置生成，且带 12 小时强缓存。
                // 下发配置指纹让前端把它并入脚本 URL，改了外观/呈现方式后缓存立即失效。
                'script_version' => $this->scriptVersion($config),
            ],
        ];
    }

    /**
     * 向 Cloudflare 兑换 token。
     *
     * 两重把关：
     * 1. 本地防重放缓存——Turnstile token 是一次性凭据，重复提交无需再打上游即可拒绝；
     * 2. siteverify 的 success 字段。
     *
     * 不做解题域名（hostname）比对：Widget 能在哪些站点渲染已由 Cloudflare 后台的
     * 「域名」列表限制，服务端再比一次属于兜底，但域名一旦对不上就会拒掉全部验证
     * （API 与前端不同域、或用 IP 访问都会触发），误配代价高于收益。
     *
     * @param  array<string, mixed>  $payload
     * @param  array<string, mixed>  $config
     * @return array<string, mixed>
     */
    private function verify(string $action, array $payload, array $config): array
    {
        if (! $this->isConfigured($config)) {
            return $this->failure($action, 'Turnstile 插件配置不完整');
        }

        $token = $this->stringFrom($payload, ['token', 'response', 'cf-turnstile-response', 'turnstile_token']);
        if ($token === '') {
            return $this->failure($action, '行为验证参数不完整');
        }

        $clientIp = $this->stringFrom($payload, ['_client_ip', 'client_ip', 'ip']);

        $replayKey = $this->replayCacheKey($config, $token);
        if (Cache::has($replayKey)) {
            return $this->failure($action, '行为验证已使用，请重新验证', ['verified' => false]);
        }

        $requestPayload = [
            'secret' => $this->secretKey($config),
            'response' => $token,
            // idempotency_key 让网络抖动下的重试不会把 token 二次消耗掉（官方推荐做法）。
            // 对同一个 token 必须是同一个 key，故由 token 派生而非随机生成。
            'idempotency_key' => $this->idempotencyKey($token),
        ];

        if ($clientIp !== '') {
            $requestPayload['remoteip'] = $clientIp;
        }

        try {
            $response = Http::asForm()
                ->timeout($this->requestTimeout($config))
                ->post(self::VERIFY_ENDPOINT, $requestPayload);

            if (! $response->successful()) {
                Log::warning('[captcha:turnstile] siteverify request failed', [
                    'status' => $response->status(),
                    'body' => SensitiveDataSanitizer::sanitizeText($response->body()),
                ]);

                return $this->failure($action, '行为验证服务暂时不可用，请稍后重试', [
                    'error_type' => 'upstream_http_error',
                    'status' => $response->status(),
                ]);
            }

            $data = $response->json();
            if (! is_array($data)) {
                return $this->failure($action, '行为验证服务响应异常，请稍后重试');
            }

            if (! $this->truthy($data['success'] ?? false)) {
                $errorCodes = array_map(
                    static fn (mixed $code): string => (string) $code,
                    is_array($data['error-codes'] ?? null) ? $data['error-codes'] : []
                );

                Log::info('[captcha:turnstile] siteverify rejected', ['error_codes' => $errorCodes]);

                return $this->failure($action, $this->messageForErrorCodes($errorCodes), [
                    'verified' => false,
                    'error_codes' => $errorCodes,
                ]);
            }

            // 这里刻意不校验 Cloudflare 回传的解题域名（hostname）：
            // Widget 能在哪些站点渲染，已由 Cloudflare 后台的「域名」列表限制；
            // 再加一层服务端域名比对属于兜底，但一旦域名对不上就会拒掉全部验证
            // （API 与前端不同域、或用 IP 访问都会触发），误配代价高于收益，故不做。

            // 校验通过后才落防重放标记：失败的 token 没有必要占用缓存，
            // 且 add() 的原子性顺带挡住了并发双提交。
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
            Log::warning('[captcha:turnstile] siteverify exception', [
                'message' => $exception->getMessage(),
                'exception' => $exception::class,
                'error_type' => $this->classifyHttpException($exception),
            ]);

            return $this->failure($action, '行为验证服务暂时不可用，请稍后重试', [
                'error_type' => $this->classifyHttpException($exception),
            ]);
        }
    }

    /**
     * @param  array<int, string>  $errorCodes
     */
    private function messageForErrorCodes(array $errorCodes): string
    {
        // timeout-or-duplicate：token 已过期或已被兑换过，提示用户重做即可。
        if (in_array('timeout-or-duplicate', $errorCodes, true)) {
            return '行为验证已过期，请重新验证';
        }

        // 这几类是站点配置错误，不是用户操作问题，用可区分的话术避免用户反复重试。
        $configurationErrors = ['missing-input-secret', 'invalid-input-secret', 'bad-request'];
        if (array_intersect($configurationErrors, $errorCodes) !== []) {
            return '行为验证配置有误，请联系管理员';
        }

        if (in_array('internal-error', $errorCodes, true)) {
            return '行为验证服务暂时不可用，请稍后重试';
        }

        return '行为验证未通过，请重试';
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
     * 生成前端适配层：加载 Cloudflare SDK，并把它包装成项目既有的 window.initGeetest4 接口。
     *
     * 这样 frontend-user-v4-console 的 useGeeTestCaptcha 与 frontend-admin-v3 的同名 hook
     * 都不需要为 Turnstile 做任何改动。
     *
     * @param  array<string, mixed>  $config
     */
    private function adapterScript(array $config): string
    {
        $replacements = [
            '__TURA_SDK_URL__' => json_encode(self::SDK_URL, JSON_UNESCAPED_SLASHES),
            '__TURA_SITE_KEY__' => json_encode($this->siteKey($config), JSON_UNESCAPED_SLASHES),
            '__TURA_THEME__' => json_encode($this->widgetTheme($config), JSON_UNESCAPED_SLASHES),
            '__TURA_SIZE__' => json_encode($this->widgetSize($config), JSON_UNESCAPED_SLASHES),
            '__TURA_LANGUAGE__' => json_encode($this->widgetLanguage($config), JSON_UNESCAPED_SLASHES),
            '__TURA_APPEARANCE__' => json_encode($this->widgetAppearance($config), JSON_UNESCAPED_SLASHES),
            '__TURA_SDK_TIMEOUT_MS__' => (string) ($this->sdkTimeoutSeconds($config) * 1000),
        ];

        return strtr($this->adapterTemplate(), $replacements);
    }

    private function adapterTemplate(): string
    {
        return <<<'JS'
(function (global) {
    'use strict';

    var SDK_URL = __TURA_SDK_URL__;
    var SITE_KEY = __TURA_SITE_KEY__;
    var THEME = __TURA_THEME__;
    var SIZE = __TURA_SIZE__;
    var LANGUAGE = __TURA_LANGUAGE__;
    var APPEARANCE = __TURA_APPEARANCE__;
    var SDK_TIMEOUT_MS = __TURA_SDK_TIMEOUT_MS__;
    var TIMEOUT_MESSAGE = '人机验证服务连接超时，请联系管理员';

    var sdkPromise = null;

    function emit(callbacks, arg) {
        callbacks.slice().forEach(function (fn) {
            try {
                fn(arg);
            } catch (error) {
                // 单个订阅者抛错不应打断其余回调
            }
        });
    }

    /**
     * 加载 Cloudflare SDK，带超时。
     *
     * 超时这一层是必需的：Turnstile 的 SDK 与挑战都来自 challenges.cloudflare.com，
     * 在网络受限的环境下这个域名可能长时间无响应而不是明确报错。没有超时的话
     * onerror 不触发、Promise 永不落地，用户看到的就是按钮一直转圈。
     * 超时文案直接引导联系管理员——这是站点侧的网络/配置问题，用户自己重试无用。
     */
    function loadSdk() {
        if (global.turnstile) {
            return Promise.resolve(global.turnstile);
        }

        if (sdkPromise) {
            return sdkPromise;
        }

        sdkPromise = new Promise(function (resolve, reject) {
            var settled = false;

            var timer = setTimeout(function () {
                finish(function () {
                    sdkPromise = null;
                    reject(new Error(TIMEOUT_MESSAGE));
                });
            }, SDK_TIMEOUT_MS);

            function finish(action) {
                if (settled) {
                    return;
                }

                settled = true;
                clearTimeout(timer);
                action();
            }

            function succeed() {
                finish(function () {
                    if (global.turnstile) {
                        resolve(global.turnstile);
                    } else {
                        sdkPromise = null;
                        reject(new Error('人机验证组件未正确加载，请联系管理员'));
                    }
                });
            }

            function fail() {
                finish(function () {
                    sdkPromise = null;
                    reject(new Error('人机验证服务无法加载，请联系管理员'));
                });
            }

            var existing = document.querySelector('script[data-turnstile-sdk="v0"]');
            if (existing) {
                existing.addEventListener('load', succeed, { once: true });
                existing.addEventListener('error', fail, { once: true });

                return;
            }

            var script = document.createElement('script');
            script.src = SDK_URL;
            script.async = true;
            script.defer = true;
            script.dataset.turnstileSdk = 'v0';
            script.onload = succeed;
            script.onerror = fail;
            document.head.appendChild(script);
        });

        return sdkPromise;
    }

    /**
     * 解析调用方显式指定的内联容器。未指定时返回 null，走浮层模式。
     */
    function resolveInlineContainer(options) {
        var target = options.appendTo || options.container;

        if (typeof target === 'string') {
            return document.querySelector(target);
        }

        if (target && target.nodeType === 1) {
            return target;
        }

        return null;
    }

    /**
     * 构建承载验证组件的浮层。
     *
     * Turnstile 官方 SDK 只提供内联 widget，没有弹窗形态；而本项目要求的交互是
     * 「点击提交后弹出验证、完成即继续」，与极验的 bind 模式一致。
     * 因此这里自建一个居中浮层来承载 widget，让两家验证的交互保持统一。
     */
    function createOverlay(onDismiss) {
        var overlay = document.createElement('div');
        overlay.dataset.turnstileOverlay = '1';
        overlay.style.cssText = [
            'position:fixed', 'inset:0', 'z-index:10000',
            'display:flex', 'align-items:center', 'justify-content:center',
            'background:rgba(0,0,0,.45)'
        ].join(';');

        var panel = document.createElement('div');
        panel.style.cssText = [
            'background:#fff', 'border-radius:10px', 'padding:24px 28px',
            'box-shadow:0 12px 40px rgba(0,0,0,.24)',
            'display:flex', 'flex-direction:column', 'align-items:center', 'gap:14px',
            'font-family:inherit'
        ].join(';');

        var title = document.createElement('div');
        title.textContent = '请完成人机验证';
        title.style.cssText = 'font-size:14px;line-height:22px;color:#1f2329';

        var host = document.createElement('div');
        host.style.cssText = 'min-height:65px;display:flex;align-items:center;justify-content:center';

        panel.appendChild(title);
        panel.appendChild(host);
        overlay.appendChild(panel);

        // 点遮罩关闭；点面板内部不关闭
        overlay.addEventListener('click', function (event) {
            if (event.target === overlay) {
                onDismiss();
            }
        });

        return { overlay: overlay, host: host };
    }

    global.initGeetest4 = function (options, callback) {
        options = options || {};

        var readyCallbacks = [];
        var successCallbacks = [];
        var errorCallbacks = [];
        var closeCallbacks = [];

        var widgetId = null;
        var overlayNode = null;
        // 仅当调用方显式给出容器时才内联渲染；否则采用浮层（点击提交后弹出）
        var inlineContainer = resolveInlineContainer(options);
        var lastToken = null;
        var destroyed = false;
        var sdkReady = false;
        var turnstileApi = null;

        // 前端可能沿用 GeeTest 的 captchaId 字段传入站点密钥，优先使用它，其次用服务端注入值
        var siteKey = String(options.captchaId || options.sitekey || SITE_KEY || '');

        function renderWidget(container) {
            widgetId = turnstileApi.render(container, {
                sitekey: siteKey,
                theme: THEME,
                size: SIZE,
                // 语言固定取插件配置：调用方传来的是极验的语言码（如 zho），Turnstile 不认
                language: LANGUAGE,
                // interaction-only 下静默通过时不渲染任何可见内容，即「无感」
                appearance: APPEARANCE,
                callback: function (token) {
                    lastToken = String(token || '');
                    closeOverlay();
                    emit(successCallbacks);
                },
                'error-callback': function (code) {
                    lastToken = null;
                    closeOverlay();
                    var detail = code ? '（' + String(code) + '）' : '';
                    emit(errorCallbacks, new Error('行为验证失败，请重试' + detail));

                    return true;
                },
                'expired-callback': function () {
                    lastToken = null;
                },
                'timeout-callback': function () {
                    lastToken = null;
                    closeOverlay();
                    // 挑战本身超时：单次可能是偶发，持续超时通常是站点侧网络问题
                    emit(errorCallbacks, new Error('人机验证超时，请重试；若持续超时请联系管理员'));
                },
                'unsupported-callback': function () {
                    closeOverlay();
                    emit(errorCallbacks, new Error('当前浏览器不支持行为验证，请更换浏览器'));

                    return true;
                }
            });
        }

        function removeWidget() {
            if (widgetId !== null && turnstileApi && typeof turnstileApi.remove === 'function') {
                try {
                    turnstileApi.remove(widgetId);
                } catch (error) {
                    // 组件已被移除时 remove 会抛错，忽略
                }
            }

            widgetId = null;
        }

        function closeOverlay() {
            if (! overlayNode) {
                return;
            }

            removeWidget();

            if (overlayNode.parentNode) {
                overlayNode.parentNode.removeChild(overlayNode);
            }

            overlayNode = null;
        }

        function openOverlay() {
            if (overlayNode) {
                return;
            }

            var parts = createOverlay(function () {
                closeOverlay();
                // 用户主动关闭：与极验的 onClose 语义一致，由调用方决定提示文案
                emit(closeCallbacks);
            });

            overlayNode = parts.overlay;
            document.body.appendChild(overlayNode);
            renderWidget(parts.host);
        }

        var instance = {
            onReady: function (fn) {
                if (typeof fn === 'function') {
                    readyCallbacks.push(fn);
                    if (widgetId !== null) {
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
                if (destroyed) {
                    return instance;
                }

                // 已解出但尚未被消费：直接上报成功，不重复弹窗
                if (lastToken) {
                    emit(successCallbacks);

                    return instance;
                }

                if (! sdkReady) {
                    emit(errorCallbacks, new Error('行为验证组件初始化中，请稍后重试'));

                    return instance;
                }

                // 内联模式（调用方给了容器）：组件常驻，等它解出即可
                if (inlineContainer) {
                    if (widgetId === null) {
                        renderWidget(inlineContainer);
                    }

                    return instance;
                }

                // 浮层模式：点击提交后才弹出验证窗口，完成即自动关闭
                openOverlay();

                return instance;
            },
            getValidate: function () {
                if (!lastToken) {
                    return null;
                }

                return {
                    token: lastToken,
                    response: lastToken,
                    provider: 'turnstile'
                };
            },
            reset: function () {
                lastToken = null;

                if (inlineContainer) {
                    // 内联组件常驻，就地重置以便重新解题
                    if (widgetId !== null && turnstileApi && typeof turnstileApi.reset === 'function') {
                        try {
                            turnstileApi.reset(widgetId);
                        } catch (error) {
                            // 组件已被移除时 reset 会抛错，忽略
                        }
                    }

                    return instance;
                }

                // 浮层模式下组件随浮层一起销毁，下次 showCaptcha 会重新创建
                closeOverlay();

                return instance;
            },
            destroy: function () {
                destroyed = true;
                lastToken = null;
                closeOverlay();
                removeWidget();

                return instance;
            }
        };

        if (typeof callback === 'function') {
            callback(instance);
        }

        if (siteKey === '') {
            emit(errorCallbacks, new Error('Turnstile 站点密钥未配置'));
            return instance;
        }

        // 只加载 SDK，不渲染任何组件：一律等 showCaptcha 被调用（即用户点击提交）
        // 才就地渲染，页面上不会预先出现验证组件。
        loadSdk()
            .then(function (turnstile) {
                if (destroyed) {
                    return;
                }

                turnstileApi = turnstile;
                sdkReady = true;

                emit(readyCallbacks);
            })
            .catch(function (error) {
                emit(errorCallbacks, error instanceof Error ? error : new Error('行为验证初始化失败'));
            });

        return instance;
    };
})(window);
JS;
    }

    /**
     * token 派生的幂等键：同一 token 的多次重试共用一个 key，避免重试消耗掉 token。
     */
    private function idempotencyKey(string $token): string
    {
        $hash = hash('sha256', $token);

        // Cloudflare 要求 UUID 形态，这里用 hash 前 32 位十六进制拼成 v4 形状
        return sprintf(
            '%s-%s-4%s-8%s-%s',
            substr($hash, 0, 8),
            substr($hash, 8, 4),
            substr($hash, 12, 3),
            substr($hash, 15, 3),
            substr($hash, 18, 12)
        );
    }

    /**
     * @param  array<string, mixed>  $config
     */
    private function replayCacheKey(array $config, string $token): string
    {
        return 'captcha:turnstile:used:'.hash('sha256', $this->siteKey($config).'|'.$token);
    }

    /**
     * @param  array<string, mixed>  $config
     */
    private function replayTtlSeconds(array $config): int
    {
        $ttl = (int) ($config['replay_ttl'] ?? self::DEFAULT_REPLAY_TTL_SECONDS);

        return $ttl >= 60 && $ttl <= 1800 ? $ttl : self::DEFAULT_REPLAY_TTL_SECONDS;
    }

    /**
     * @param  array<string, mixed>  $config
     */
    private function requestTimeout(array $config): int
    {
        $timeout = (int) ($config['request_timeout'] ?? self::DEFAULT_TIMEOUT_SECONDS);

        return $timeout >= 3 && $timeout <= 30 ? $timeout : self::DEFAULT_TIMEOUT_SECONDS;
    }

    /**
     * @param  array<string, mixed>  $config
     */
    private function widgetTheme(array $config): string
    {
        $theme = trim((string) ($config['widget_theme'] ?? 'auto'));

        return in_array($theme, ['auto', 'light', 'dark'], true) ? $theme : 'auto';
    }

    /**
     * @param  array<string, mixed>  $config
     */
    private function widgetSize(array $config): string
    {
        $size = trim((string) ($config['widget_size'] ?? 'normal'));

        return in_array($size, ['normal', 'flexible', 'compact'], true) ? $size : 'normal';
    }

    /**
     * @param  array<string, mixed>  $config
     */
    private function widgetLanguage(array $config): string
    {
        $language = trim((string) ($config['widget_language'] ?? 'zh-cn'));

        return $language !== '' ? $language : 'zh-cn';
    }

    /**
     * 前端加载 Cloudflare SDK 的超时秒数。
     *
     * @param  array<string, mixed>  $config
     */
    private function sdkTimeoutSeconds(array $config): int
    {
        $timeout = (int) ($config['sdk_timeout'] ?? self::DEFAULT_SDK_TIMEOUT_SECONDS);

        return $timeout >= 5 && $timeout <= 60 ? $timeout : self::DEFAULT_SDK_TIMEOUT_SECONDS;
    }

    /**
     * 适配层脚本的配置指纹。
     *
     * 只纳入会写进脚本的字段——密钥类与校验类配置不影响脚本内容，
     * 纳入它们会让指纹在无关变更时也跳动，白白让前端缓存失效。
     *
     * @param  array<string, mixed>  $config
     */
    private function scriptVersion(array $config): string
    {
        return substr(hash('sha256', implode('|', [
            $this->siteKey($config),
            $this->widgetTheme($config),
            $this->widgetSize($config),
            $this->widgetLanguage($config),
            $this->widgetAppearance($config),
        ])), 0, 16);
    }

    /**
     * 组件呈现方式。
     *
     * interaction-only 是「无感」形态：静默通过时组件完全不显示，
     * 只有 Cloudflare 判定需要人工挑战时才出现。默认取它。
     *
     * @param  array<string, mixed>  $config
     */
    private function widgetAppearance(array $config): string
    {
        $appearance = trim((string) ($config['widget_appearance'] ?? 'interaction-only'));

        return in_array($appearance, ['interaction-only', 'always', 'execute'], true)
            ? $appearance
            : 'interaction-only';
    }

    private function classifyHttpException(\Throwable $exception): string
    {
        $message = strtolower($exception->getMessage());

        if (str_contains($message, 'ssl certificate') || str_contains($message, 'certificate verify')) {
            return 'tls_certificate_error';
        }

        return str_contains($message, 'timed out') ? 'timeout' : 'connection_error';
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
        return $this->siteKey($config) !== '' && $this->secretKey($config) !== '';
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
    private function secretKey(array $config): string
    {
        return trim((string) ($config['secret_key'] ?? ''));
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  array<int, string>  $keys
     */
    private function stringFrom(array $payload, array $keys): string
    {
        foreach ($keys as $key) {
            $value = $payload[$key] ?? null;
            if (is_string($value) || is_numeric($value)) {
                $normalized = trim((string) $value);
                if ($normalized !== '') {
                    return $normalized;
                }
            }
        }

        return '';
    }

    private function truthy(mixed $value): bool
    {
        return filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) === true;
    }
}
