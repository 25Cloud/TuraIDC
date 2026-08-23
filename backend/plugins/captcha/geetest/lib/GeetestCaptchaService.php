<?php

declare(strict_types=1);

namespace TuraIDC\Plugins\Captcha\Geetest\Lib;

use App\Services\Integrations\Plugins\PluginConfigRepository;
use App\Services\Integrations\Plugins\PluginDomain;
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

    /**
     * 「检测」按钮的落点：真正校验配置是否可用，而不只是确认插件能加载。
     *
     * 分两步，各自对应一类典型故障：
     * 1. 拉取官方前端脚本 —— 验证服务器能出网到 static.geetest.com，
     *    这是「用户打不开验证组件」最常见的原因；
     * 2. 用无效参数打 /validate —— 极验会先认 captcha_id，
     *    若返回 captcha_id 相关错误即说明 ID 填错了。
     *
     * 注意 captcha_key 无法在不做真实验证的情况下单独确认：极验的签名校验发生在
     * lot_number 合法之后，用假 lot_number 打过去，服务端不会走到验签这一步。
     * 因此这里如实把「密钥正确性需真实验证一次」写进结果，不假装已经验过。
     *
     * @return array<string, mixed>
     */
    public function healthCheck(): array
    {
        $config = app(PluginConfigRepository::class)
            ->resolvedConfigByDomainAndSlug(PluginDomain::CAPTCHA, 'geetest');

        if ($this->captchaId($config) === '') {
            return $this->unhealthy('未填写 Captcha ID');
        }

        if ($this->captchaKey($config) === '') {
            return $this->unhealthy('未填写 Captcha Key');
        }

        // 第一步：官方脚本可达性
        try {
            $scriptResponse = Http::timeout(10)->get(self::SCRIPT_UPSTREAM_URL);
            if (! $scriptResponse->successful()) {
                return $this->unhealthy(
                    '拉取极验前端脚本失败，状态码：'.$scriptResponse->status(),
                    ['stage' => 'script', 'status' => $scriptResponse->status()]
                );
            }
        } catch (\Throwable $exception) {
            return $this->unhealthy(
                '无法连接极验脚本服务（'.self::SCRIPT_UPSTREAM_URL.'），请检查服务器网络',
                ['stage' => 'script', 'error_type' => $this->classifyHttpException($exception)]
            );
        }

        // 第二步：校验接口可达性与 captcha_id 有效性
        try {
            $validateResponse = Http::asForm()
                ->timeout(10)
                ->post(self::API_SERVER.'/validate', [
                    'lot_number' => 'turaidc-health-check',
                    'captcha_output' => 'turaidc-health-check',
                    'pass_token' => 'turaidc-health-check',
                    'gen_time' => (string) time(),
                    'captcha_id' => $this->captchaId($config),
                    'sign_token' => hash_hmac('sha256', 'turaidc-health-check', $this->captchaKey($config)),
                ]);
        } catch (\Throwable $exception) {
            return $this->unhealthy(
                '无法连接极验校验服务（'.self::API_SERVER.'），请检查服务器网络',
                ['stage' => 'validate', 'error_type' => $this->classifyHttpException($exception)]
            );
        }

        if (! $validateResponse->successful()) {
            return $this->unhealthy(
                '极验校验服务返回异常状态码：'.$validateResponse->status(),
                ['stage' => 'validate', 'status' => $validateResponse->status()]
            );
        }

        $data = $validateResponse->json();
        if (! is_array($data)) {
            return $this->unhealthy('极验校验服务响应格式异常', ['stage' => 'validate']);
        }

        // 极验的 /validate 有两种响应形态，必须分开处理：
        // 1. 配置层错误 → {"status":"error","code":"-50103","msg":"not captcha"}
        //    （实测：captcha_id 不存在为 -50103，captcha_id 为空为 -50101）
        // 2. 验证结果   → {"result":"success"|"fail","reason":"..."}
        // 早先只读 reason，遇到形态 1 会读到空字符串，把「ID 填错」误判成「配置可用」。
        if ((string) ($data['status'] ?? '') === 'error') {
            $code = (string) ($data['code'] ?? '');
            $msg = (string) ($data['msg'] ?? '');

            $message = match ($code) {
                '-50101' => 'Captcha ID 为空或格式不正确',
                '-50103' => 'Captcha ID 不存在，请核对极验控制台分配的 Captcha ID',
                default => '极验拒绝了校验请求：'.($msg !== '' ? $msg : '错误码 '.$code),
            };

            return $this->unhealthy($message, ['stage' => 'validate', 'code' => $code, 'msg' => $msg]);
        }

        // 走到验证结果形态说明 captcha_id 已被极验接受（否则会停在形态 1）
        return [
            'healthy' => true,
            'message' => '配置可用：极验脚本与校验接口均可访问，Captcha ID 已被接受',
            'details' => [
                'captcha_id' => $this->captchaId($config),
                'result' => $data['result'] ?? null,
                'reason' => $data['reason'] ?? null,
                'note' => 'Captcha Key 的正确性需要完成一次真实验证才能确认——'
                    .'极验只有在 lot_number 合法后才会校验签名，健康检查用的是构造参数，走不到验签那步。',
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

            // 配置层错误与「用户没通过验证」是两件事：极验对 Captcha ID 无效这类问题
            // 返回 {"status":"error","code":"-501xx"}（实测），而不是 result=fail。
            // 不加区分的话，用户看到的是「验证未通过、请重试」，重试永远无用，
            // 而运维侧也没有任何线索指向配置错误。
            if (is_array($data) && (string) ($data['status'] ?? '') === 'error') {
                Log::error('[captcha:geetest] 极验拒绝校验请求，通常是 Captcha ID / Captcha Key 配置有误', [
                    'code' => $data['code'] ?? null,
                    'msg' => $data['msg'] ?? null,
                ]);

                return $this->failure($action, '行为验证服务配置有误，请联系管理员', ['verified' => false]);
            }

            if (! is_array($data) || ($data['result'] ?? '') !== 'success') {
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
