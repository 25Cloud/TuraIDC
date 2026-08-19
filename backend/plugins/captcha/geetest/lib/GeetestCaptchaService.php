<?php

declare(strict_types=1);

namespace TuraIDC\Plugins\Captcha\Geetest\Lib;

use App\Support\CacheKey;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class GeetestCaptchaService
{
    private const API_SERVER = 'https://gcaptcha4.geetest.com';

    private const SCRIPT_UPSTREAM_URL = 'https://static.geetest.com/v4/gt4.js';

    private const SCRIPT_CACHE_TTL_SECONDS = 43200;

    public function key(): string
    {
        return 'geetest';
    }

    public function label(): string
    {
        return 'GeeTest 行为验证';
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
                'provider' => 'geetest',
                'enabled' => $this->isConfigured($config),
                'captcha_id' => $this->captchaId($config),
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
            return $this->failure($action, '行为验证插件配置不完整');
        }

        $lotNumber = trim((string) ($payload['lot_number'] ?? ''));
        $captchaOutput = trim((string) ($payload['captcha_output'] ?? ''));
        $passToken = trim((string) ($payload['pass_token'] ?? ''));
        $genTime = trim((string) ($payload['gen_time'] ?? ''));

        if ($lotNumber === '' || $captchaOutput === '' || $passToken === '' || $genTime === '') {
            return $this->failure($action, '行为验证参数不完整');
        }

        $endpoint = self::API_SERVER.'/validate';
        $signToken = hash_hmac('sha256', $lotNumber, $this->captchaKey($config));

        try {
            $response = Http::asForm()
                ->timeout(10)
                ->post($endpoint, [
                    'lot_number' => $lotNumber,
                    'captcha_output' => $captchaOutput,
                    'pass_token' => $passToken,
                    'gen_time' => $genTime,
                    'captcha_id' => $this->captchaId($config),
                    'sign_token' => $signToken,
                ]);

            if (! $response->successful()) {
                Log::warning('[captcha:geetest] validate request failed', [
                    'status' => $response->status(),
                    'body' => $response->body(),
                ]);

                return $this->failure($action, '行为验证服务暂时不可用，请稍后重试', [
                    'error_type' => 'upstream_http_error',
                    'status' => $response->status(),
                ]);
            }

            $data = $response->json();
            if (($data['result'] ?? '') !== 'success') {
                return $this->failure($action, '行为验证未通过，请重试', ['verified' => false]);
            }

            return [
                'success' => true,
                'action' => $action,
                'message' => '',
                'data' => ['verified' => true],
                'raw' => is_array($data) ? $data : [],
            ];
        } catch (\Throwable $exception) {
            Log::warning('[captcha:geetest] validate exception', [
                'message' => $exception->getMessage(),
                'exception' => $exception::class,
                'error_type' => $this->classifyHttpException($exception),
                'endpoint' => $endpoint,
            ]);

            return $this->failure($action, '行为验证服务暂时不可用，请稍后重试', [
                'error_type' => $this->classifyHttpException($exception),
            ]);
        }
    }

    /**
     * @param  array<string, mixed>  $config
     * @return array<string, mixed>
     */
    private function script(string $action, array $config): array
    {
        $cachedScript = Cache::get(CacheKey::geeTestScript());
        if (is_string($cachedScript) && $cachedScript !== '') {
            return $this->scriptResult($action, $cachedScript);
        }

        try {
            $response = Http::timeout(10)
                ->get(self::SCRIPT_UPSTREAM_URL);

            if (! $response->successful()) {
                throw new \RuntimeException('GeeTest 脚本拉取失败，状态码：'.$response->status());
            }

            $scriptContent = (string) $response->body();
            if ($scriptContent === '') {
                throw new \RuntimeException('GeeTest 脚本内容为空');
            }

            Cache::put(
                CacheKey::geeTestScript(),
                $scriptContent,
                now()->addSeconds(self::SCRIPT_CACHE_TTL_SECONDS)
            );

            return $this->scriptResult($action, $scriptContent);
        } catch (\Throwable $exception) {
            Log::warning('[captcha:geetest] script fetch exception', [
                'message' => $exception->getMessage(),
                'exception' => $exception::class,
            ]);

            return $this->failure($action, '行为验证脚本暂时不可用，请稍后重试');
        }
    }

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
        return $this->captchaId($config) !== '' && $this->captchaKey($config) !== '';
    }

    /**
     * @param  array<string, mixed>  $config
     */
    private function captchaId(array $config): string
    {
        return trim((string) ($config['captcha_id'] ?? ''));
    }

    /**
     * @param  array<string, mixed>  $config
     */
    private function captchaKey(array $config): string
    {
        return trim((string) ($config['captcha_key'] ?? ''));
    }
}
