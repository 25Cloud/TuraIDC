<?php

declare(strict_types=1);

namespace App\Services\Verification\Drivers;

use App\Exceptions\BusinessException;
use App\Models\Setting;
use App\Services\Verification\Contracts\VerificationDriver;
use App\Services\Verification\Data\VerificationInitializeRequest;
use App\Services\Verification\Data\VerificationInitializeResult;
use App\Services\Verification\Data\VerificationScanUrlResult;
use App\Services\Verification\Data\VerificationStatusResult;
use App\Support\SensitiveDataSanitizer;
use Illuminate\Support\Facades\Log;

class Stay33Driver implements VerificationDriver
{
    private const DEFAULT_API_ENDPOINT = 'https://idc.stay33.cn/realname/certapi.php';

    private ?array $lastRequestFailure = null;

    public function key(): string
    {
        return 'stay33';
    }

    public function label(): string
    {
        return 'Stay33 实名认证';
    }

    public function initialize(VerificationInitializeRequest $request): VerificationInitializeResult
    {
        $config = $this->resolveConfig();
        $params = [
            'outer_order_no' => 'ZGYD'.now()->format('YmdHis').random_int(1000, 9999),
            'biz_code' => $config['biz_code'],
            'cert_type' => $request->certType,
            'cert_name' => $request->realName,
            'cert_no' => $request->idCard,
            'return_url' => $request->returnUrl,
        ];

        $result = $this->apiRequest('initialize', $params, $config);

        if ($result === null) {
            return new VerificationInitializeResult(500, $this->getLastFailureMessage());
        }

        if ((int) ($result['status'] ?? 0) === 200 && trim((string) ($result['certify_id'] ?? '')) !== '') {
            return new VerificationInitializeResult(
                status: 200,
                message: $this->safeProviderMessage($result['msg'] ?? '', '请求成功'),
                certifyId: (string) $result['certify_id'],
                raw: $result,
            );
        }

        return new VerificationInitializeResult(
            status: 400,
            message: $this->safeProviderMessage($result['msg'] ?? '', '实名认证接口配置错误，请联系管理员'),
            raw: $result,
        );
    }

    public function generateScanUrl(string $certifyId): VerificationScanUrlResult
    {
        $config = $this->resolveConfig();
        $result = $this->apiRequest('certify', ['certify_id' => $certifyId], $config);

        if ($result === null) {
            return new VerificationScanUrlResult(500, $this->getLastFailureMessage());
        }

        $url = trim((string) ($result['url'] ?? ''));
        if ($url === '') {
            return new VerificationScanUrlResult(
                status: 400,
                message: $this->safeProviderMessage($result['msg'] ?? '', '获取认证链接失败，请联系管理员'),
                raw: $result,
            );
        }

        return new VerificationScanUrlResult(
            status: 200,
            message: $this->safeProviderMessage($result['msg'] ?? '', '请打开实名认证链接继续认证'),
            url: $url,
            raw: $result,
        );
    }

    public function queryStatus(string $certifyId): VerificationStatusResult
    {
        $config = $this->resolveConfig();
        $result = $this->apiRequest('query', ['certify_id' => $certifyId], $config);

        if ($result === null) {
            return new VerificationStatusResult(3, $this->getLastFailureMessage());
        }

        if ((int) ($result['status'] ?? 0) === 200) {
            return new VerificationStatusResult(1, '审核通过', $result);
        }

        $msg = $this->safeProviderMessage($result['msg'] ?? '', '实名认证未通过，请核对后重试');
        $waitingKeywords = ['等待', '认证中', '待认证', '处理中', '审核中'];

        foreach ($waitingKeywords as $keyword) {
            if (str_contains($msg, $keyword)) {
                return new VerificationStatusResult(4, $msg, $result);
            }
        }

        return new VerificationStatusResult(2, $msg, $result);
    }

    private function resolveConfig(): array
    {
        $defaultConfig = (array) config('idc.verification', []);

        $api = (string) Setting::getValue('verification', 'verification_api', $defaultConfig['api'] ?? '');
        $key = (string) Setting::getValue('verification', 'verification_key', $defaultConfig['key'] ?? '');

        if (trim($api) === '' || trim($key) === '') {
            throw new BusinessException('实名认证接口未配置，请先在管理端填写 API 信息', 42200);
        }

        return [
            'api' => $api,
            'key' => $key,
            'biz_code' => (string) Setting::getValue('verification', 'verification_biz_code', $defaultConfig['biz_code'] ?? 'FACE'),
            'api_endpoint' => (string) ($defaultConfig['api_endpoint'] ?? self::DEFAULT_API_ENDPOINT),
            'ssl_verify' => filter_var($defaultConfig['ssl_verify'] ?? true, FILTER_VALIDATE_BOOL),
            'ca_bundle' => (string) ($defaultConfig['ca_bundle'] ?? ''),
        ];
    }

    private function apiRequest(string $action, array $params, array $config): ?array
    {
        $this->lastRequestFailure = null;

        $api = rtrim($config['api_endpoint'], '?').'?action='.$action;
        $headers = [
            'api:'.$config['api'],
            'key:'.$config['key'],
            'Content-Type: application/x-www-form-urlencoded',
        ];
        $postfields = http_build_query($params);

        Log::info('[实名认证] API请求', SensitiveDataSanitizer::sanitize([
            'action' => $action,
            'api_url' => $api,
        ]));

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

        if (str_starts_with($api, 'https://')) {
            $sslVerify = (bool) $config['ssl_verify'];
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, $sslVerify);
            curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, $sslVerify ? 2 : 0);

            $caBundle = (string) $config['ca_bundle'];
            if ($caBundle !== '' && is_file($caBundle)) {
                curl_setopt($ch, CURLOPT_CAINFO, $caBundle);
            }
        }

        $output = curl_exec($ch);
        $curlErrno = curl_errno($ch);
        $curlError = curl_error($ch);
        curl_close($ch);

        if ($output === false) {
            $this->lastRequestFailure = ['type' => 'curl', 'errno' => $curlErrno, 'error' => $curlError];
            Log::error('[实名认证] CURL请求失败', $this->lastRequestFailure);

            if ($this->isSslCertificateError($curlError)) {
                throw new BusinessException('实名认证接口 SSL 证书校验失败', 42200);
            }

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
            return '实名认证接口请求失败，请稍后重试';
        }

        return '实名认证接口返回异常';
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

    private function isSslCertificateError(string $curlError): bool
    {
        $message = strtolower($curlError);

        return str_contains($message, 'ssl certificate problem')
            || str_contains($message, 'certificate verify failed');
    }
}
