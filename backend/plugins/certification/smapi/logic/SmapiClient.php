<?php

declare(strict_types=1);

namespace TuraIDC\Plugins\Certification\Smapi\Logic;

use App\Exceptions\BusinessException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * 聚合实名（smapi.x1m1.cn）HTTP 客户端 — 完全自包含，不依赖内核驱动。
 *
 * 接口约定：
 * - POST /api/realname/initialize                    初始化认证
 * - GET  /api/realname/certifications/{id}/query     查询认证状态与认证页面链接
 * - 鉴权头：X-App-Key / X-App-Secret
 * - 业务成功：HTTP 2xx 且 body.status === 200 或 body.code === 20000
 */
class SmapiClient
{
    private const DEFAULT_API_ENDPOINT = 'https://smapi.x1m1.cn';

    private ?array $lastRequestFailure = null;

    /**
     * @param  array<string, mixed>  $config  插件配置（来自 execute() 的 $request['config']）
     */
    public function __construct(
        private readonly array $config,
    ) {}

    /**
     * 初始化实名认证。
     *
     * @return array{status: int, message: string, certify_id?: string, raw?: array}
     */
    public function initialize(string $realName, string $idCard, string $returnUrl): array
    {
        $productCode = $this->defaultProductCode();
        if ($productCode === '') {
            return ['status' => 400, 'message' => '请先配置产品标识 product_code'];
        }

        $result = $this->request('POST', '/api/realname/initialize', [
            'product_code' => $productCode,
            'cert_name' => $realName,
            'cert_no' => $idCard,
            'return_url' => $returnUrl,
        ]);

        if ($result === null) {
            return ['status' => 500, 'message' => $this->getLastFailureMessage()];
        }

        if ($this->isBusinessSuccess($result)) {
            $data = $this->resultData($result);
            $certifyId = trim((string) ($data['id'] ?? ''));

            if ($certifyId !== '') {
                return [
                    'status' => 200,
                    'message' => $this->safeProviderMessage($result['msg'] ?? '', '认证初始化成功'),
                    'certify_id' => $certifyId,
                    'raw' => $data,
                ];
            }

            return [
                'status' => 400,
                'message' => '聚合实名平台返回异常：未返回认证标识',
                'raw' => $data,
            ];
        }

        return [
            'status' => 400,
            'message' => $this->safeProviderMessage($this->resultMessage($result), '实名认证接口配置错误，请联系管理员'),
            'raw' => $this->resultData($result),
        ];
    }

    /**
     * 获取认证链接（复用查询接口回显的认证页面地址）。
     *
     * @return array{status: int, message: string, url?: string, raw?: array}
     */
    public function scanUrl(string $certifyId): array
    {
        $result = $this->query($certifyId);

        if ($result === null) {
            return ['status' => 500, 'message' => $this->getLastFailureMessage()];
        }

        if (! $this->isBusinessSuccess($result)) {
            return [
                'status' => 400,
                'message' => $this->safeProviderMessage($this->resultMessage($result), '获取认证链接失败，请联系管理员'),
                'raw' => $this->resultData($result),
            ];
        }

        $data = $this->resultData($result);
        $url = $this->extractCertificationUrl($data);

        if ($url === '') {
            return [
                'status' => 400,
                'message' => '获取认证链接失败，请联系管理员',
                'raw' => $data,
            ];
        }

        return [
            'status' => 200,
            'message' => '请打开实名认证链接继续认证',
            'url' => $url,
            'raw' => $data,
        ];
    }

    /**
     * 查询认证状态。
     *
     * 状态码遵循项目内部约定：1=通过、2=不通过、3=网络错误、4=处理中。
     *
     * @return array{status: int, message: string, raw?: array}
     */
    public function queryStatus(string $certifyId): array
    {
        $result = $this->query($certifyId);

        if ($result === null) {
            return ['status' => 3, 'message' => $this->getLastFailureMessage()];
        }

        if (! $this->isBusinessSuccess($result)) {
            // 查询接口业务失败不判失败，按“处理中”处理，等待下次轮询。
            return ['status' => 4, 'message' => '认证处理中', 'raw' => $this->resultData($result)];
        }

        $data = $this->resultData($result);
        $status = (string) ($data['status'] ?? '');

        if ($status === 'passed') {
            return ['status' => 1, 'message' => '审核通过', 'raw' => $data];
        }

        if ($status === 'failed' || $status === 'updated') {
            $reason = trim((string) ($data['fail_reason'] ?? ''));

            return [
                'status' => 2,
                'message' => $reason !== '' ? $this->safeProviderMessage($reason, '审核未通过') : '审核未通过',
                'raw' => $data,
            ];
        }

        $map = [
            'initialized' => '待认证，请先完成扫码认证',
            'processing' => '认证处理中，请稍后再获取结果',
        ];

        return [
            'status' => 4,
            'message' => isset($map[$status]) ? $map[$status] : '认证处理中',
            'raw' => $data,
        ];
    }

    /**
     * @return array|null 上游原始响应；网络或解析失败返回 null
     */
    private function query(string $certifyId): ?array
    {
        return $this->request('GET', '/api/realname/certifications/'.rawurlencode($certifyId).'/query');
    }

    private function request(string $method, string $path, array $params = []): ?array
    {
        $this->lastRequestFailure = null;

        $url = rtrim($this->resolveEndpoint(), '/').$path;

        try {
            $http = Http::withOptions($this->sslOptions())
                ->withHeaders([
                    'X-App-Key' => $this->resolveAppKey(),
                    'X-App-Secret' => $this->resolveAppSecret(),
                    'Accept' => 'application/json',
                    'Content-Type' => 'application/json',
                ])
                ->timeout(30)
                ->connectTimeout(10);

            $response = $method === 'POST'
                ? $http->post($url, $params)
                : $http->get($url);
        } catch (ConnectionException $exception) {
            $this->lastRequestFailure = ['type' => 'connection', 'error' => $exception->getMessage()];
            Log::error('[实名认证] 请求聚合实名平台失败', ['error' => $exception->getMessage()]);

            return null;
        }

        $body = trim($response->body(), "\xEF\xBB\xBF");
        $decoded = json_decode($body, true);

        if (! is_array($decoded)) {
            $this->lastRequestFailure = [
                'type' => 'invalid_json',
                'raw' => mb_substr($body, 0, 200),
                'http_status' => $response->status(),
            ];

            return null;
        }

        return $decoded;
    }

    private function resolveEndpoint(): string
    {
        return (string) ($this->config['api_url'] ?? self::DEFAULT_API_ENDPOINT);
    }

    private function resolveAppKey(): string
    {
        $value = trim((string) ($this->config['app_key'] ?? ''));
        if ($value === '') {
            throw new BusinessException('实名认证接口未配置，请先在管理端填写 API 信息', 42200);
        }

        return $value;
    }

    private function resolveAppSecret(): string
    {
        $value = trim((string) ($this->config['secret_key'] ?? ''));
        if ($value === '') {
            throw new BusinessException('实名认证接口未配置，请先在管理端填写 API 信息', 42200);
        }

        return $value;
    }

    /**
     * 配置中声明的第一个有效产品标识；未配置时返回空串。
     */
    private function defaultProductCode(): string
    {
        $products = $this->parseProducts();
        if ($products === []) {
            return '';
        }

        return (string) array_key_first($products);
    }

    /**
     * @return array<string, string> 产品标识 => 显示名称
     */
    private function parseProducts(): array
    {
        $value = trim((string) ($this->config['product_code'] ?? ''));
        $products = [];

        foreach (explode('|', $value) as $item) {
            $item = trim($item);
            if ($item === ''
                || str_contains($item, '填写说明')
                || str_contains($item, '请删除本说明')
                || str_contains($item, '例如：')) {
                continue;
            }

            $parts = explode(',', $item, 2);
            $code = trim($parts[0]);
            if ($code === '') {
                continue;
            }

            $products[$code] = isset($parts[1]) && trim($parts[1]) !== '' ? trim($parts[1]) : $code;
        }

        return $products;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function extractCertificationUrl(array $data): string
    {
        foreach (['certify_page_url', 'certify_url', 'url', 'qrcode_url', 'qr_code_url'] as $field) {
            $value = trim((string) ($data[$field] ?? ''));
            if ($value !== '' && $value !== '-') {
                return $value;
            }
        }

        return '';
    }

    /**
     * @param  array<string, mixed>  $result
     */
    private function isBusinessSuccess(array $result): bool
    {
        return (isset($result['status']) && (int) $result['status'] === 200)
            || (isset($result['code']) && (int) $result['code'] === 20000);
    }

    /**
     * @param  array<string, mixed>  $result
     * @return array<string, mixed>
     */
    private function resultData(array $result): array
    {
        return isset($result['data']) && is_array($result['data']) ? $result['data'] : [];
    }

    /**
     * @param  array<string, mixed>  $result
     */
    private function resultMessage(array $result): string
    {
        $message = trim((string) ($result['msg'] ?? $result['message'] ?? ''));

        return $message !== '' ? $message : '聚合实名平台请求失败';
    }

    private function getLastFailureMessage(): string
    {
        if (! is_array($this->lastRequestFailure)) {
            return '网络请求失败，请稍后重试';
        }

        if (($this->lastRequestFailure['type'] ?? '') === 'connection') {
            if ($this->isSslFailure($this->lastRequestFailure)) {
                return '实名认证接口 SSL 证书校验失败，请检查插件 CA 证书配置';
            }

            return '实名认证接口请求失败，请稍后重试';
        }

        return '实名认证接口返回异常';
    }

    /**
     * @return array<string, mixed> Http::withOptions 的 SSL 选项
     */
    private function sslOptions(): array
    {
        if (! $this->resolveSslVerify()) {
            return ['verify' => false];
        }

        $caBundle = $this->resolveCaBundle();
        if ($caBundle !== '' && is_file($caBundle)) {
            return ['verify' => $caBundle];
        }

        return [];
    }

    private function resolveSslVerify(): bool
    {
        $value = $this->config['ssl_verify'] ?? null;
        if ($value !== null && $value !== '') {
            return filter_var($value, FILTER_VALIDATE_BOOL);
        }

        return filter_var(config('idc.verification.ssl_verify', true), FILTER_VALIDATE_BOOL);
    }

    private function resolveCaBundle(): string
    {
        $value = $this->config['ca_bundle'] ?? null;
        if ($value !== null && $value !== '') {
            return trim((string) $value);
        }

        return trim((string) config('idc.verification.ca_bundle', ''));
    }

    /**
     * @param  array<string, mixed>  $failure
     */
    private function isSslFailure(array $failure): bool
    {
        $message = strtolower((string) ($failure['error'] ?? ''));

        return str_contains($message, 'ssl') || str_contains($message, 'certificate');
    }

    private function safeProviderMessage(mixed $message, string $fallback): string
    {
        $text = trim((string) $message);
        if ($text === '') {
            return $fallback;
        }

        // 服务商文案预期为中文；出现连续英文字母一律视为技术细节，直接回退到安全文案。
        if (preg_match('/[a-z]{3,}/i', $text) === 1) {
            return $fallback;
        }

        return $text;
    }
}
