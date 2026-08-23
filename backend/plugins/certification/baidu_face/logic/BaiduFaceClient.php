<?php

declare(strict_types=1);

namespace TuraIDC\Plugins\Certification\BaiduFace\Logic;

use App\Exceptions\BusinessException;
use App\Support\SensitiveDataSanitizer;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class BaiduFaceClient
{
    private const DEFAULT_OAUTH_ENDPOINT = 'https://aip.baidubce.com/oauth/2.0/token';

    private const DEFAULT_H5_ENTRY_URL = 'https://brain.baidu.com/face/print/';

    private const DEFAULT_H5_TOKEN_ENDPOINT = 'https://aip.baidubce.com/rpc/2.0/brain/solution/faceprint/verifyToken/generate';

    private const DEFAULT_H5_SUBMIT_ENDPOINT = 'https://aip.baidubce.com/rpc/2.0/brain/solution/faceprint/idcard/submit';

    private const DEFAULT_H5_RESULT_ENDPOINT = 'https://aip.baidubce.com/rpc/2.0/brain/solution/faceprint/result/detail';

    private const DEFAULT_DIRECT_V4_ENDPOINT = 'https://aip.baidubce.com/rest/2.0/face/v4/mingjing/verify';

    private const DEFAULT_DIRECT_V3_ENDPOINT = 'https://aip.baidubce.com/rest/2.0/face/v3/person/verify';

    private const ACCESS_TOKEN_CACHE_PREFIX = 'baidu_face_verification:access_token:';

    private const RETURN_URL_CACHE_PREFIX = 'baidu_face_verification:return_url:';

    private const RETURN_URL_CACHE_TTL_SECONDS = 300;

    /**
     * @param  array<string, mixed>  $config
     */
    public function __construct(
        private readonly array $config,
    ) {}

    /**
     * @return array{status: int, message: string, certify_id?: string, raw?: array<string, mixed>}
     */
    public function initialize(string $realName, string $idCard, string $certType, string $returnUrl): array
    {
        if ($this->resolveCertificateType($certType) !== 'IDENTITY_CARD') {
            return ['status' => 400, 'message' => '百度 H5 实名认证当前仅支持大陆身份证'];
        }

        $verifyToken = $this->createVerifyToken($returnUrl);
        if ($verifyToken === '') {
            return ['status' => 400, 'message' => '获取百度实名认证令牌失败，请联系管理员'];
        }

        $submitResult = $this->submitIdentity($verifyToken, $realName, $idCard);
        if (! $this->isBaiduSuccess($submitResult)) {
            return [
                'status' => 400,
                'message' => $this->safeProviderMessage($submitResult, '提交实名认证资料失败，请核对后重试'),
                'raw' => $submitResult,
            ];
        }

        $this->cacheReturnUrl($verifyToken, $this->callbackUrl($returnUrl, $verifyToken));

        return [
            'status' => 200,
            'message' => '实名认证初始化成功',
            'certify_id' => $verifyToken,
            'raw' => [
                'verify_token' => $verifyToken,
                'submit' => $submitResult,
            ],
        ];
    }

    /**
     * @return array{status: int, message: string, url?: string, raw?: array<string, mixed>}
     */
    public function generateScanUrl(string $certifyId): array
    {
        $verifyToken = trim($certifyId);
        if ($verifyToken === '') {
            return ['status' => 400, 'message' => '认证会话不存在或已失效'];
        }

        return [
            'status' => 200,
            'message' => '请打开实名认证链接继续认证',
            'url' => $this->buildH5Url($verifyToken),
            'raw' => ['verify_token' => $verifyToken],
        ];
    }

    /**
     * @return array{status: int, message: string, raw?: array<string, mixed>}
     */
    public function queryStatus(string $certifyId): array
    {
        $verifyToken = trim($certifyId);
        if ($verifyToken === '') {
            return ['status' => 2, 'message' => '认证会话不存在或已失效'];
        }

        $result = $this->postJson($this->endpoint('h5_result_endpoint', self::DEFAULT_H5_RESULT_ENDPOINT), [
            'verify_token' => $verifyToken,
        ]);

        if ($result === null) {
            return ['status' => 3, 'message' => '实名认证接口请求失败，请稍后重试'];
        }

        if ($this->isVerificationPassed($result)) {
            return ['status' => 1, 'message' => '审核通过', 'raw' => $result];
        }

        if ($this->isPendingResult($result)) {
            return ['status' => 4, 'message' => '认证处理中，请稍后再试', 'raw' => $result];
        }

        return [
            'status' => 2,
            'message' => $this->safeProviderMessage($result, '实名认证未通过，请核对后重试'),
            'raw' => $result,
        ];
    }

    /**
     * 服务端直连接口预留给后续 App/上传人脸图场景，现有用户端 H5 流程不会调用。
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function directVerify(array $payload): array
    {
        $image = trim((string) ($payload['image'] ?? ''));
        $imageType = trim((string) ($payload['image_type'] ?? 'BASE64'));
        $realName = trim((string) ($payload['real_name'] ?? $payload['name'] ?? ''));
        $idCard = trim((string) ($payload['id_card'] ?? $payload['id_card_number'] ?? ''));

        if ($image === '' || $realName === '' || $idCard === '') {
            return ['status' => 400, 'message' => '缺少人脸图片、姓名或身份证号'];
        }

        $version = $this->apiVersion();
        $endpoint = $version === 'v3'
            ? $this->endpoint('api_endpoint_v3', self::DEFAULT_DIRECT_V3_ENDPOINT)
            : $this->endpoint('api_endpoint_v4', self::DEFAULT_DIRECT_V4_ENDPOINT);

        $requestPayload = $version === 'v3'
            ? [
                'image' => $image,
                'image_type' => $imageType,
                'id_card_number' => $idCard,
                'name' => $realName,
            ]
            : [
                'image' => $image,
                'image_type' => $imageType,
                'id_card_number' => $idCard,
                'name' => $realName,
                'quality_control' => (string) ($payload['quality_control'] ?? 'NORMAL'),
                'liveness_control' => (string) ($payload['liveness_control'] ?? 'NORMAL'),
                'spoofing_control' => (string) ($payload['spoofing_control'] ?? 'NORMAL'),
                'get_liveness_score' => (string) ($payload['get_liveness_score'] ?? '1'),
                'get_spoofing_score' => (string) ($payload['get_spoofing_score'] ?? '1'),
            ];

        $result = $this->postJson($endpoint, $requestPayload);
        if ($result === null) {
            return ['status' => 500, 'message' => '实名认证接口请求失败，请稍后重试'];
        }

        if ($this->isDirectVerificationPassed($result)) {
            return ['status' => 200, 'message' => '审核通过', 'raw' => $result];
        }

        return [
            'status' => 400,
            'message' => $this->safeProviderMessage($result, '实名认证未通过，请核对后重试'),
            'raw' => $result,
        ];
    }

    /**
     * @param  array<string, mixed>  $config
     */
    public static function forgetAccessTokenCacheForConfig(array $config): void
    {
        $cacheKey = self::accessTokenCacheKeyForConfig($config);
        if ($cacheKey !== null) {
            Cache::forget($cacheKey);
        }
    }

    /**
     * @param  array<string, mixed>  $config
     */
    public static function accessTokenCacheKeyForConfig(array $config): ?string
    {
        $apiKey = trim((string) ($config['api_key'] ?? ''));
        $secretKey = trim((string) ($config['secret_key'] ?? ''));

        if ($apiKey === '' || $secretKey === '') {
            return null;
        }

        return self::ACCESS_TOKEN_CACHE_PREFIX.hash('sha256', $apiKey.'|'.$secretKey);
    }

    private function createVerifyToken(string $returnUrl): string
    {
        $planId = (int) ($this->config['h5_plan_id'] ?? 0);
        if ($planId <= 0) {
            throw new BusinessException('百度 H5 方案ID未配置，请先在插件管理中填写', 42200);
        }

        $callbackUrl = $this->callbackUrl($returnUrl);
        $payload = [
            'plan_id' => $planId,
        ];

        if ($callbackUrl !== '') {
            $payload['redirect_config'] = [
                'success_url' => $callbackUrl,
                'failed_url' => $callbackUrl,
            ];
        }

        $result = $this->postJson($this->endpoint('h5_token_endpoint', self::DEFAULT_H5_TOKEN_ENDPOINT), $payload);
        if ($result === null || ! $this->isBaiduSuccess($result)) {
            return '';
        }

        return $this->stringFromPaths($result, [
            ['result', 'verify_token'],
            ['result', 'token'],
            ['verify_token'],
            ['token'],
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function submitIdentity(string $verifyToken, string $realName, string $idCard): array
    {
        return $this->postJson($this->endpoint('h5_submit_endpoint', self::DEFAULT_H5_SUBMIT_ENDPOINT), [
            'verify_token' => $verifyToken,
            'id_name' => $realName,
            'id_no' => $idCard,
            'certificate_type' => 0,
        ]) ?? [];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>|null
     */
    private function postJson(string $endpoint, array $payload): ?array
    {
        try {
            $response = $this->http()
                ->acceptJson()
                ->asJson()
                ->post($this->withAccessToken($endpoint), $payload);
        } catch (ConnectionException $exception) {
            Log::error('[百度实名认证] 接口请求失败', SensitiveDataSanitizer::sanitize([
                'endpoint' => $endpoint,
                'message' => $exception->getMessage(),
            ]));

            return null;
        }

        $decoded = $response->json();
        if (! is_array($decoded)) {
            Log::warning('[百度实名认证] 接口返回非 JSON', [
                'endpoint' => $endpoint,
                'status' => $response->status(),
            ]);

            return null;
        }

        return $decoded;
    }

    private function accessToken(): string
    {
        $config = [
            'api_key' => $this->apiKey(),
            'secret_key' => $this->secretKey(),
        ];
        $cacheKey = self::accessTokenCacheKeyForConfig($config);
        if ($cacheKey === null) {
            throw new BusinessException('百度实名认证接口未配置，请先在插件管理中填写 API Key 和 Secret Key', 42200);
        }

        $cached = Cache::get($cacheKey);
        if (is_string($cached) && trim($cached) !== '') {
            return $cached;
        }

        try {
            $response = $this->http()
                ->acceptJson()
                ->get($this->endpoint('oauth_endpoint', self::DEFAULT_OAUTH_ENDPOINT), [
                    'grant_type' => 'client_credentials',
                    'client_id' => $config['api_key'],
                    'client_secret' => $config['secret_key'],
                ]);
        } catch (ConnectionException $exception) {
            Log::error('[百度实名认证] access_token 获取失败', SensitiveDataSanitizer::sanitize([
                'message' => $exception->getMessage(),
            ]));

            throw new BusinessException('百度实名认证接口请求失败，请稍后重试', 50000);
        }

        $payload = $response->json();
        if (! is_array($payload)) {
            throw new BusinessException('百度实名认证接口返回异常', 42200);
        }

        $accessToken = trim((string) ($payload['access_token'] ?? ''));
        if ($accessToken === '') {
            Log::warning('[百度实名认证] access_token 响应异常', SensitiveDataSanitizer::sanitize($payload));

            throw new BusinessException('百度实名认证接口配置错误，请联系管理员', 42200);
        }

        $ttl = max(60, (int) ($payload['expires_in'] ?? 2592000) - 300);
        Cache::put($cacheKey, $accessToken, now()->addSeconds($ttl));

        return $accessToken;
    }

    private function withAccessToken(string $endpoint): string
    {
        $separator = str_contains($endpoint, '?') ? '&' : '?';

        return $endpoint.$separator.'access_token='.rawurlencode($this->accessToken());
    }

    private function http(): PendingRequest
    {
        $request = Http::timeout(30)
            ->connectTimeout(10)
            ->withOptions($this->httpOptions());

        return $request;
    }

    /**
     * @return array<string, mixed>
     */
    private function httpOptions(): array
    {
        // 项目硬规则：插件不需要 SSL 与 CA 配置，统一依赖系统 CA。
        // 原实现允许经插件配置或 config('idc.verification.ssl_verify') 关闭证书校验，
        // 而后者在 APP_ENV=local 时默认为 false——等于给证书校验留了一条静默禁用通路。
        return [];
    }

    private function buildH5Url(string $verifyToken): string
    {
        $entry = rtrim($this->endpoint('h5_entry_url', self::DEFAULT_H5_ENTRY_URL), '/').'/';
        $callbackUrl = trim((string) Cache::get($this->returnUrlCacheKey($verifyToken), ''));
        $query = [
            'token' => $verifyToken,
        ];

        if ($callbackUrl !== '') {
            $query['successUrl'] = $callbackUrl;
            $query['failedUrl'] = $callbackUrl;
        }

        return $entry.'?'.http_build_query($query, '', '&', PHP_QUERY_RFC3986);
    }

    private function callbackUrl(string $returnUrl, ?string $certifyId = null): string
    {
        $returnUrl = trim($returnUrl);
        if ($returnUrl === '') {
            return '';
        }

        if ($certifyId === null || $certifyId === '') {
            return $returnUrl;
        }

        $separator = str_contains($returnUrl, '?') ? '&' : '?';

        return $returnUrl.$separator.'certify_id='.rawurlencode($certifyId);
    }

    private function cacheReturnUrl(string $verifyToken, string $returnUrl): void
    {
        if ($returnUrl === '') {
            return;
        }

        Cache::put($this->returnUrlCacheKey($verifyToken), $returnUrl, now()->addSeconds(self::RETURN_URL_CACHE_TTL_SECONDS));
    }

    private function returnUrlCacheKey(string $verifyToken): string
    {
        return self::RETURN_URL_CACHE_PREFIX.hash('sha256', $verifyToken);
    }

    /**
     * @param  array<string, mixed>  $result
     */
    private function isVerificationPassed(array $result): bool
    {
        if (($result['success'] ?? null) === true) {
            return true;
        }

        if (! $this->isBaiduSuccess($result)) {
            return false;
        }

        $status = strtolower($this->stringFromPaths($result, [
            ['result', 'status'],
            ['result', 'verify_status'],
            ['result', 'auth_status'],
            ['status'],
        ]));

        return in_array($status, ['success', 'passed', 'pass', '1', 'true'], true);
    }

    /**
     * @param  array<string, mixed>  $result
     */
    private function isDirectVerificationPassed(array $result): bool
    {
        if (! $this->isBaiduSuccess($result)) {
            return false;
        }

        $verifyStatus = (int) ($result['result']['verify_status'] ?? 0);
        $score = (float) ($result['result']['score'] ?? 0);

        return $verifyStatus === 0 && $score >= $this->scoreThreshold();
    }

    /**
     * @param  array<string, mixed>  $result
     */
    private function isPendingResult(array $result): bool
    {
        $code = (string) ($result['error_code'] ?? $result['code'] ?? '');
        if (in_array($code, ['18', '216402', '216403'], true)) {
            return true;
        }

        $message = strtolower((string) ($result['error_msg'] ?? $result['message'] ?? $result['msg'] ?? ''));
        foreach (['未查询', '未完成', '处理中', '进行中', '等待', 'openapi', 'no result', 'not found'] as $keyword) {
            if (str_contains($message, strtolower($keyword))) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<string, mixed>  $result
     */
    private function isBaiduSuccess(array $result): bool
    {
        if (($result['success'] ?? null) === true) {
            return true;
        }

        $code = $result['error_code'] ?? $result['code'] ?? 0;
        $message = strtoupper(trim((string) ($result['error_msg'] ?? $result['message'] ?? '')));

        return ((string) $code === '0' || $code === 0) && ! in_array($message, ['FAILED', 'FAIL'], true);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function safeProviderMessage(array $payload, string $fallback): string
    {
        $text = trim((string) ($payload['error_msg'] ?? $payload['message'] ?? $payload['msg'] ?? ''));
        if ($text === '') {
            return $fallback;
        }

        if (preg_match('/[a-z]{3,}|error|failed|exception|timeout|curl|http|openapi/i', $text) === 1) {
            return $fallback;
        }

        return $text;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  array<int, array<int, string>>  $paths
     */
    private function stringFromPaths(array $payload, array $paths): string
    {
        foreach ($paths as $path) {
            $value = $payload;
            foreach ($path as $segment) {
                if (! is_array($value) || ! array_key_exists($segment, $value)) {
                    $value = null;
                    break;
                }

                $value = $value[$segment];
            }

            $string = trim((string) ($value ?? ''));
            if ($string !== '') {
                return $string;
            }
        }

        return '';
    }

    private function resolveCertificateType(string $certType): string
    {
        $normalized = strtoupper(trim($certType));

        return $normalized !== '' ? $normalized : 'IDENTITY_CARD';
    }

    private function apiVersion(): string
    {
        $version = strtolower(trim((string) ($this->config['api_version'] ?? 'v4')));

        return $version === 'v3' ? 'v3' : 'v4';
    }

    private function scoreThreshold(): float
    {
        $threshold = (float) ($this->config['score_threshold'] ?? 80);

        return max(0.0, min(100.0, $threshold));
    }

    private function endpoint(string $key, string $default): string
    {
        $endpoint = trim((string) ($this->config[$key] ?? ''));

        return $endpoint !== '' ? $endpoint : $default;
    }

    private function apiKey(): string
    {
        $value = trim((string) ($this->config['api_key'] ?? ''));
        if ($value === '') {
            throw new BusinessException('百度实名认证接口未配置，请先在插件管理中填写 API Key', 42200);
        }

        return $value;
    }

    private function secretKey(): string
    {
        $value = trim((string) ($this->config['secret_key'] ?? ''));
        if ($value === '') {
            throw new BusinessException('百度实名认证接口未配置，请先在插件管理中填写 Secret Key', 42200);
        }

        return $value;
    }
}
