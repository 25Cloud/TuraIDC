<?php

declare(strict_types=1);

namespace TuraIDC\Plugins\Certification\Stay33\Logic;

use App\Exceptions\BusinessException;
use Illuminate\Support\Facades\Log;

/**
 * Stay33 实名认证 HTTP 客户端 — 完全自包含，不依赖内核驱动。
 */
class Stay33Client
{
    private const DEFAULT_API_ENDPOINT = 'https://idc.stay33.cn/realname/certapi.php';

    private ?array $lastRequestFailure = null;

    /**
     * @param  array<string, mixed>  $config  插件配置（来自 execute() 的 $request['config']）
     */
    public function __construct(
        private readonly array $config,
    ) {}

    /**
     * @return array{status: int, message: string, certify_id?: string, raw?: array}
     */
    public function initialize(string $realName, string $idCard, string $certType, string $returnUrl): array
    {
        $params = [
            'outer_order_no' => 'ZGYD'.now()->format('YmdHis').random_int(1000, 9999),
            'biz_code' => $this->config['biz_code'] ?? 'FACE',
            'cert_type' => $certType,
            'cert_name' => $realName,
            'cert_no' => $idCard,
            'return_url' => $returnUrl,
        ];

        $result = $this->apiRequest('initialize', $params);

        if ($result === null) {
            return ['status' => 500, 'message' => $this->getLastFailureMessage()];
        }

        if ((int) ($result['status'] ?? 0) === 200 && trim((string) ($result['certify_id'] ?? '')) !== '') {
            return [
                'status' => 200,
                'message' => $this->safeProviderMessage($result['msg'] ?? '', '请求成功'),
                'certify_id' => (string) $result['certify_id'],
                'raw' => $result,
            ];
        }

        return [
            'status' => 400,
            'message' => $this->safeProviderMessage($result['msg'] ?? '', '实名认证接口配置错误，请联系管理员'),
            'raw' => $result,
        ];
    }

    /**
     * @return array{status: int, message: string, url?: string, raw?: array}
     */
    public function generateScanUrl(string $certifyId): array
    {
        $result = $this->apiRequest('certify', ['certify_id' => $certifyId]);

        if ($result === null) {
            return ['status' => 500, 'message' => $this->getLastFailureMessage()];
        }

        $url = trim((string) ($result['url'] ?? ''));
        if ($url === '') {
            return [
                'status' => 400,
                'message' => $this->safeProviderMessage($result['msg'] ?? '', '获取认证链接失败，请联系管理员'),
                'raw' => $result,
            ];
        }

        return [
            'status' => 200,
            'message' => $this->safeProviderMessage($result['msg'] ?? '', '请打开实名认证链接继续认证'),
            'url' => $url,
            'raw' => $result,
        ];
    }

    /**
     * @return array{status: int, message: string, raw?: array}
     */
    public function queryStatus(string $certifyId): array
    {
        $result = $this->apiRequest('query', ['certify_id' => $certifyId]);

        if ($result === null) {
            return ['status' => 3, 'message' => $this->getLastFailureMessage()];
        }

        if ((int) ($result['status'] ?? 0) === 200) {
            return ['status' => 1, 'message' => '审核通过', 'raw' => $result];
        }

        $msg = $this->safeProviderMessage($result['msg'] ?? '', '实名认证未通过，请核对后重试');
        $waitingKeywords = ['等待', '认证中', '待认证', '处理中', '审核中'];

        foreach ($waitingKeywords as $keyword) {
            if (str_contains($msg, $keyword)) {
                return ['status' => 4, 'message' => $msg, 'raw' => $result];
            }
        }

        return ['status' => 2, 'message' => $msg, 'raw' => $result];
    }

    private function resolveEndpoint(): string
    {
        return (string) ($this->config['api_endpoint'] ?? self::DEFAULT_API_ENDPOINT);
    }

    private function resolveApiKey(): string
    {
        $api = (string) ($this->config['api'] ?? '');
        $key = (string) ($this->config['key'] ?? '');

        if ($api === '' || $key === '') {
            throw new BusinessException('实名认证接口未配置，请先在管理端填写 API 信息', 42200);
        }

        return $api;
    }

    private function resolveSecretKey(): string
    {
        return (string) ($this->config['key'] ?? '');
    }

    private function apiRequest(string $action, array $params): ?array
    {
        $this->lastRequestFailure = null;

        $api = rtrim($this->resolveEndpoint(), '?').'?action='.$action;
        $headers = [
            'api:'.$this->resolveApiKey(),
            'key:'.$this->resolveSecretKey(),
            'Content-Type: application/x-www-form-urlencoded',
        ];
        $postfields = http_build_query($params);

        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $api,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POSTFIELDS => $postfields,
            CURLOPT_CUSTOMREQUEST => 'POST',
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_CONNECTTIMEOUT => 10,
        ]);
        $this->applySslOptions($ch);

        $output = curl_exec($ch);
        $curlErrno = curl_errno($ch);
        $curlError = curl_error($ch);
        curl_close($ch);

        if ($output === false) {
            $this->lastRequestFailure = ['type' => 'curl', 'errno' => $curlErrno, 'error' => $curlError];
            Log::error('[实名认证] CURL请求失败', $this->lastRequestFailure);

            return null;
        }

        $output = trim($output, "\xEF\xBB\xBF");
        $decoded = json_decode($output, true);

        if (! is_array($decoded)) {
            $this->lastRequestFailure = ['type' => 'invalid_json', 'raw' => mb_substr($output, 0, 200)];

            return null;
        }

        return $decoded;
    }

    private function getLastFailureMessage(): string
    {
        if (! is_array($this->lastRequestFailure)) {
            return '网络请求失败，请稍后重试';
        }

        if (($this->lastRequestFailure['type'] ?? '') === 'curl') {
            if ($this->isSslFailure($this->lastRequestFailure)) {
                return '实名认证接口 SSL 证书校验失败，请检查插件 CA 证书配置';
            }

            return '实名认证接口请求失败，请稍后重试';
        }

        return '实名认证接口返回异常';
    }

    private function applySslOptions($ch): void
    {
        $sslVerify = $this->resolveSslVerify();

        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, $sslVerify);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, $sslVerify ? 2 : 0);

        $caBundle = $this->resolveCaBundle();
        if ($sslVerify && $caBundle !== '' && is_file($caBundle)) {
            curl_setopt($ch, CURLOPT_CAINFO, $caBundle);
        }
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
        $errno = (int) ($failure['errno'] ?? 0);
        if (in_array($errno, [35, 51, 58, 59, 60, 77, 83], true)) {
            return true;
        }

        $message = strtolower((string) ($failure['error'] ?? ''));

        return str_contains($message, 'ssl') || str_contains($message, 'certificate');
    }

    private function safeProviderMessage(mixed $message, string $fallback): string
    {
        $text = trim((string) $message);
        if ($text === '') {
            return $fallback;
        }

        if (preg_match('/[a-z]{3,}|error|failed|exception|timeout|curl|http/i', $text) === 1) {
            return $fallback;
        }

        return $text;
    }
}
