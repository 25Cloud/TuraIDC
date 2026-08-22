<?php

declare(strict_types=1);

namespace TuraIDC\Plugins\Captcha\Cap\Lib;

use App\Support\CacheKey;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Cap 人机验证（https://trycap.dev，自托管开源 CAPTCHA）。
 *
 * 协议与 reCAPTCHA 同形：
 *  - 前端 widget：<cap-widget data-cap-api-endpoint="{server}/{siteId}/">
 *  - 后端校验：POST {server}/{siteId}/siteverify，JSON body {secret, response}，
 *    响应 {success: true|false}；token 单次有效，必须"先校验后业务"并 fail-closed。
 */
class CapCaptchaService
{
    /** 前端 widget 脚本（生产环境固定版本）。 */
    private const WIDGET_SCRIPT_URL = 'https://cdn.jsdelivr.net/npm/@cap.js/widget@0.1.56';

    private const SCRIPT_CACHE_TTL_SECONDS = 43200;

    public function key(): string
    {
        return 'cap';
    }

    public function label(): string
    {
        return 'Cap 人机验证';
    }

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
                'provider' => 'cap',
                'enabled' => $this->isConfigured($config),
                'captcha_id' => $this->siteId($config),
                'api_endpoint' => $this->apiEndpoint($config),
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
            return $this->failure($action, '人机验证插件配置不完整');
        }

        // 前端提交的 token：对象 {token}（本项目各端统一走该形状），兼容 cap-token / captcha 字符串。
        $token = trim((string) ($payload['token'] ?? $payload['cap-token'] ?? ''));
        if ($token === '' && isset($payload['captcha']) && is_string($payload['captcha'])) {
            $token = trim($payload['captcha']);
        }

        if ($token === '') {
            return $this->failure($action, '人机验证参数不完整');
        }

        $endpoint = $this->siteVerifyEndpoint($config);

        try {
            $response = Http::timeout(10)
                ->withHeaders(['Content-Type' => 'application/json'])
                ->post($endpoint, [
                    'secret' => $this->secretKey($config),
                    'response' => $token,
                ]);

            if (! $response->successful()) {
                Log::warning('[captcha:cap] siteverify request failed', [
                    'status' => $response->status(),
                    'body' => $response->body(),
                ]);

                return $this->failure($action, '人机验证服务暂时不可用，请稍后重试', [
                    'error_type' => 'upstream_http_error',
                    'status' => $response->status(),
                ]);
            }

            $data = $response->json();
            if (! is_array($data) || ! (bool) ($data['success'] ?? false)) {
                return $this->failure($action, '人机验证未通过，请重试', ['verified' => false]);
            }

            return [
                'success' => true,
                'action' => $action,
                'message' => '',
                'data' => ['verified' => true],
                'raw' => $data,
            ];
        } catch (\Throwable $exception) {
            Log::warning('[captcha:cap] siteverify exception', [
                'message' => $exception->getMessage(),
                'exception' => $exception::class,
                'error_type' => $this->classifyHttpException($exception),
                'endpoint' => $endpoint,
            ]);

            // fail-closed：校验服务异常时拒绝请求，不允许静默放行。
            return $this->failure($action, '人机验证服务暂时不可用，请稍后重试', [
                'error_type' => $this->classifyHttpException($exception),
            ]);
        }
    }

    /**
     * 前端 widget 脚本经后端代理缓存下发（与极验/vaptcha 一致，前端统一走代理地址）。
     *
     * @param  array<string, mixed>  $config
     * @return array<string, mixed>
     */
    private function script(string $action, array $config): array
    {
        $cachedScript = Cache::get(CacheKey::capScript());
        if (is_string($cachedScript) && $cachedScript !== '') {
            return $this->scriptResult($action, $cachedScript);
        }

        try {
            $response = Http::timeout(10)->get(self::WIDGET_SCRIPT_URL);

            if (! $response->successful()) {
                throw new \RuntimeException('Cap 脚本拉取失败，状态码：'.$response->status());
            }

            $scriptContent = (string) $response->body();
            if ($scriptContent === '') {
                throw new \RuntimeException('Cap 脚本内容为空');
            }

            Cache::put(
                CacheKey::capScript(),
                $scriptContent,
                now()->addSeconds(self::SCRIPT_CACHE_TTL_SECONDS)
            );

            return $this->scriptResult($action, $scriptContent);
        } catch (\Throwable $exception) {
            Log::warning('[captcha:cap] script fetch exception', [
                'message' => $exception->getMessage(),
                'exception' => $exception::class,
            ]);

            return $this->failure($action, '人机验证脚本暂时不可用，请稍后重试');
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function scriptResult(string $action, string $content): array
    {
        return [
            'success' => true,
            'action' => $action,
            'message' => '',
            'data' => ['content' => $content],
        ];
    }

    private function classifyHttpException(\Throwable $exception): string
    {
        $message = strtolower($exception->getMessage());

        return str_contains($message, 'ssl certificate') || str_contains($message, 'certificate verify')
            ? 'tls_certificate_error'
            : (str_contains($message, 'timed out') ? 'timeout' : 'connection_error');
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
        return $this->serverAddress($config) !== ''
            && $this->siteId($config) !== ''
            && $this->secretKey($config) !== '';
    }

    /**
     * 前端 widget 使用的 API 端点：{server}/{siteId}/（必须以 / 结尾）。
     *
     * @param  array<string, mixed>  $config
     */
    private function apiEndpoint(array $config): string
    {
        $server = rtrim($this->serverAddress($config), '/');

        return $server === '' || $this->siteId($config) === ''
            ? ''
            : $server.'/'.$this->siteId($config).'/';
    }

    /**
     * @param  array<string, mixed>  $config
     */
    private function siteVerifyEndpoint(array $config): string
    {
        return $this->apiEndpoint($config).'siteverify';
    }

    /**
     * @param  array<string, mixed>  $config
     */
    private function serverAddress(array $config): string
    {
        return trim((string) ($config['server_address'] ?? ''));
    }

    /**
     * @param  array<string, mixed>  $config
     */
    private function siteId(array $config): string
    {
        return trim((string) ($config['site_id'] ?? ''));
    }

    /**
     * @param  array<string, mixed>  $config
     */
    private function secretKey(array $config): string
    {
        return trim((string) ($config['secret_key'] ?? ''));
    }
}
