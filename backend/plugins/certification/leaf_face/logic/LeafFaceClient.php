<?php

declare(strict_types=1);

namespace TuraIDC\Plugins\Certification\LeafFace\Logic;

use App\Exceptions\BusinessException;
use App\Support\SensitiveDataSanitizer;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class LeafFaceClient
{
    private const DEFAULT_API_BASE = 'https://face.ly-y.cn';

    private const CREATE_TASK_ENDPOINT = '/api/merchant/verify/tasks';

    private const LOOKUP_TASK_ENDPOINT = '/api/merchant/verify/tasks/{task_no}';

    private const CALLBACK_EVENT = 'verification.task.finished';

    private const MAX_TIMESTAMP_SKEW_SECONDS = 300;

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
            return ['status' => 400, 'message' => 'leaf实名认证当前仅支持大陆身份证'];
        }

        $payload = [
            'type' => 'h5_face',
            'real_name' => $realName,
            'card_no' => $idCard,
            'out_trade_no' => $this->generateOutTradeNo(),
        ];

        if (trim($returnUrl) !== '') {
            $payload['notify_url'] = trim($returnUrl);
            $payload['return_url'] = trim($returnUrl);
        }

        $result = $this->request('POST', self::CREATE_TASK_ENDPOINT, $payload);
        if ($result === null) {
            return ['status' => 400, 'message' => '创建 leaf实名认证任务失败，请联系管理员'];
        }

        $taskNo = trim((string) ($result['task_no'] ?? ($result['task']['task_no'] ?? '')));
        $code = $result['code'] ?? $result['error_code'] ?? null;

        if ($code !== null && $code !== 0 && $code !== '0') {
            return [
                'status' => 400,
                'message' => $this->createTaskFailureMessage($result),
                'raw' => $result,
            ];
        }

        if ($taskNo === '') {
            Log::warning('[leaf实名] 创建任务响应缺少 task_no', SensitiveDataSanitizer::sanitize($result));

            return ['status' => 400, 'message' => '创建 leaf实名认证任务失败，请联系管理员', 'raw' => $result];
        }

        $verifyUrl = trim((string) ($result['verify_url'] ?? ''));
        $this->cacheVerifyUrl($taskNo, $result);

        return [
            'status' => 200,
            'message' => '实名认证初始化成功',
            'certify_id' => $taskNo,
            'task_no' => $taskNo,
            'verify_url' => $verifyUrl,
            'raw' => $result,
        ];
    }

    /**
     * @return array{status: int, message: string, url?: string, raw?: array<string, mixed>}
     */
    public function generateScanUrl(string $certifyId): array
    {
        $taskNo = trim($certifyId);
        if ($taskNo === '') {
            return ['status' => 400, 'message' => '认证会话不存在或已失效'];
        }

        $verifyUrl = $this->cachedVerifyUrl($taskNo);
        if ($verifyUrl === '') {
            return ['status' => 400, 'message' => '认证链接已失效，请返回系统重新发起认证'];
        }

        return [
            'status' => 200,
            'message' => '请打开实名认证链接继续认证',
            'url' => $this->buildVerifyUrl($verifyUrl),
            'raw' => ['task_no' => $taskNo],
        ];
    }

    /**
     * @return array{status: int, message: string, raw?: array<string, mixed>}
     */
    public function queryStatus(string $certifyId): array
    {
        $taskNo = trim($certifyId);
        if ($taskNo === '') {
            return ['status' => 2, 'message' => '认证会话不存在或已失效'];
        }

        $result = $this->request('GET', str_replace('{task_no}', rawurlencode($taskNo), self::LOOKUP_TASK_ENDPOINT));
        if ($result === null) {
            return ['status' => 3, 'message' => '实名认证接口请求失败，请稍后重试'];
        }

        if ($this->isLookupFailure($result)) {
            return ['status' => 4, 'message' => '认证处理中，请稍后再试', 'raw' => $result];
        }

        $status = strtolower(trim((string) ($result['task']['status'] ?? '')));
        if ($status === '') {
            return ['status' => 3, 'message' => '实名认证接口返回异常，请稍后重试', 'raw' => $result];
        }

        return match ($status) {
            'completed' => ['status' => 1, 'message' => '审核通过', 'raw' => $result],
            'failed' => ['status' => 2, 'message' => '实名认证未通过，请重新发起', 'raw' => $result],
            'expired' => ['status' => 2, 'message' => '认证任务已过期，请重新发起', 'raw' => $result],
            'canceled' => ['status' => 2, 'message' => '认证任务已取消，请重新发起', 'raw' => $result],
            'created' => ['status' => 4, 'message' => '等待用户完成认证', 'raw' => $result],
            default => ['status' => 3, 'message' => '实名认证接口返回异常，请稍后重试', 'raw' => $result],
        };
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  array<string, string>  $headers
     * @return array{passed: bool, message: string, code: int, http_status: int, replay_key?: string, certify_id?: string}
     */
    public function verifyCallback(array $payload, array $headers, string $rawBody): array
    {
        $timestamp = trim((string) ($headers['x-leafsm-timestamp'] ?? ''));
        $nonce = trim((string) ($headers['x-leafsm-nonce'] ?? ''));
        $signature = trim((string) ($headers['x-leafsm-signature'] ?? ''));
        $bodySha256 = trim((string) ($headers['x-body-sha256'] ?? ''));
        $event = trim((string) ($headers['x-leafsm-event'] ?? ''));
        $secret = trim((string) ($this->config['app_secret'] ?? ''));

        if ($secret === '') {
            return $this->reject('缺少回调验签配置');
        }

        if ($event !== '' && $event !== self::CALLBACK_EVENT) {
            return $this->reject('回调事件类型不正确');
        }

        if ($timestamp === '' || $nonce === '' || $signature === '') {
            return $this->reject('缺少回调签名参数');
        }

        $parsedTimestamp = strtotime($timestamp);
        if ($parsedTimestamp === false || abs(time() - $parsedTimestamp) > self::MAX_TIMESTAMP_SKEW_SECONDS) {
            return $this->reject('回调时间戳无效或已过期');
        }

        $expectedBodySha256 = hash('sha256', $rawBody);
        if ($bodySha256 !== '' && ! hash_equals($expectedBodySha256, strtolower($bodySha256))) {
            return $this->reject('回调内容摘要不一致');
        }

        $signatureSource = $timestamp."\n".$nonce."\n".$expectedBodySha256;
        $expectedSignature = hash_hmac('sha256', $signatureSource, $secret);
        if (! hash_equals($expectedSignature, strtolower($signature))) {
            return $this->reject('回调签名验证失败');
        }

        $taskNo = trim((string) ($payload['task']['task_no'] ?? ''));

        return [
            'passed' => true,
            'message' => '回调签名验证通过',
            'code' => 0,
            'http_status' => 200,
            'replay_key' => $timestamp.'|'.$nonce,
            'certify_id' => $taskNo,
        ];
    }

    /**
     * @return array{passed: bool, message: string, code: int, http_status: int}
     */
    private function reject(string $message): array
    {
        return [
            'passed' => false,
            'message' => $message,
            'code' => 40001,
            'http_status' => 401,
        ];
    }

    /**
     * @param  array<string, mixed>  $result
     */
    private function createTaskFailureMessage(array $result): string
    {
        $code = (string) ($result['code'] ?? $result['error_code'] ?? '');

        return match (strtoupper($code)) {
            'TWO_FACTOR_MISMATCH' => '姓名和身份证号二要素预校验未通过，请核对后重试',
            'INVALID_NOTIFY_URL' => '回调地址无效，请联系管理员',
            'API_TYPE_NOT_ALLOWED' => '当前应用未开通 h5_face 能力，请联系平台开通',
            'INSUFFICIENT_CREDIT' => '平台认证额度不足，请联系管理员充值',
            'TASK_CREATE_FAILED' => '任务创建失败，请稍后重试',
            'INVALID_ARGUMENT' => '请求参数无效，请核对后重试',
            default => $this->safeProviderMessage($result, '创建 leaf实名认证任务失败，请稍后重试'),
        };
    }

    /**
     * @param  array<string, mixed>  $result
     */
    private function isLookupFailure(array $result): bool
    {
        $code = (string) ($result['code'] ?? $result['error_code'] ?? '');

        return in_array(strtoupper($code), ['TASK_NOT_FOUND', 'INVALID_SIGNATURE', 'REPLAY_REQUEST'], true);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function safeProviderMessage(array $payload, string $fallback): string
    {
        $text = trim((string) ($payload['message'] ?? $payload['msg'] ?? ''));
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
     * @return array<string, mixed>|null
     */
    private function request(string $method, string $path, array $payload = []): ?array
    {
        $base = rtrim($this->apiBase(), '/');
        $endpoint = $base.$path;
        $body = $method === 'GET' ? '' : (string) json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $timestamp = gmdate('c');
        $nonce = $this->generateNonce();
        $bodySha256 = hash('sha256', $body);
        $signature = hash_hmac('sha256', $timestamp."\n".$nonce."\n".$bodySha256, $this->appSecret());

        try {
            $request = $this->http()->withHeaders([
                'X-App-Id' => $this->appId(),
                'X-Timestamp' => $timestamp,
                'X-Nonce' => $nonce,
                'X-Body-Sha256' => $bodySha256,
                'X-Signature' => $signature,
            ]);

            $response = $body === ''
                ? $request->send($method, $endpoint)
                : $request->withBody($body, 'application/json')->send($method, $endpoint);
        } catch (ConnectionException $exception) {
            Log::error('[leaf实名] 接口请求失败', SensitiveDataSanitizer::sanitize([
                'endpoint' => $endpoint,
                'message' => $exception->getMessage(),
            ]));

            return null;
        }

        return $this->decodeResponse($endpoint, $response);
    }

    /**
     * @return array<string, mixed>|null
     */
    private function decodeResponse(string $endpoint, Response $response): ?array
    {
        $decoded = $response->json();
        if (! is_array($decoded)) {
            Log::warning('[leaf实名] 接口返回非 JSON', [
                'endpoint' => $endpoint,
                'status' => $response->status(),
            ]);

            return null;
        }

        return $decoded;
    }

    private function http(): PendingRequest
    {
        return Http::timeout(30)
            ->connectTimeout(10)
            ->acceptJson()
            ->withOptions($this->httpOptions());
    }

    /**
     * @return array<string, mixed>
     */
    private function httpOptions(): array
    {
        // 项目硬规则：插件不需要 SSL 与 CA 配置，统一依赖系统 CA。见 baidu_face 同名方法注释。
        return [];
    }

    /**
     * @param  array<string, mixed>  $result
     */
    private function cacheVerifyUrl(string $taskNo, array $result): void
    {
        $verifyUrl = trim((string) ($result['verify_url'] ?? ''));
        if ($verifyUrl === '') {
            return;
        }

        Cache::put(
            $this->verifyUrlCacheKey($taskNo),
            $verifyUrl,
            now()->addSeconds(7200)
        );
    }

    private function buildVerifyUrl(string $verifyUrl): string
    {
        if (preg_match('#^https?://#i', $verifyUrl) === 1) {
            return $verifyUrl;
        }

        return rtrim($this->apiBase(), '/').'/'.ltrim($verifyUrl, '/');
    }

    private function cachedVerifyUrl(string $taskNo): string
    {
        $raw = Cache::get($this->verifyUrlCacheKey($taskNo));

        return is_string($raw) && trim($raw) !== '' ? trim($raw) : '';
    }

    private function verifyUrlCacheKey(string $taskNo): string
    {
        return 'leaf_face_verification:verify_url:'.hash('sha256', $taskNo);
    }

    private function generateOutTradeNo(): string
    {
        return 'LF'.date('YmdHis').substr(bin2hex(random_bytes(4)), 0, 6);
    }

    private function generateNonce(): string
    {
        return bin2hex(random_bytes(16));
    }

    private function apiBase(): string
    {
        $value = trim((string) ($this->config['api_base_url'] ?? ''));
        if ($value === '') {
            return self::DEFAULT_API_BASE;
        }

        return $value;
    }

    private function appId(): string
    {
        $value = trim((string) ($this->config['app_id'] ?? ''));
        if ($value === '') {
            throw new BusinessException('leaf实名认证接口未配置，请先在插件管理中填写 AppId', 42200);
        }

        return $value;
    }

    private function appSecret(): string
    {
        $value = trim((string) ($this->config['app_secret'] ?? ''));
        if ($value === '') {
            throw new BusinessException('leaf实名认证接口未配置，请先在插件管理中填写 AppSecret', 42200);
        }

        return $value;
    }

    private function resolveCertificateType(string $certType): string
    {
        $normalized = strtoupper(trim($certType));

        return $normalized !== '' ? $normalized : 'IDENTITY_CARD';
    }

}
