<?php

declare(strict_types=1);

namespace TuraIDC\Plugins\Certification\DemoVerification\Logic;

class DemoVerification
{
    public function key(): string
    {
        return 'demo_verification';
    }

    public function label(): string
    {
        return 'Demo 实名认证';
    }

    /**
     * @return array{status: int, msg: string, certify_id: string}
     */
    public function initialize(string $realName, string $idCard): array
    {
        return [
            'status' => 200,
            'msg' => 'Demo 认证已初始化',
            'certify_id' => 'demo-certify-'.sha1($realName.$idCard),
        ];
    }

    /**
     * @return array{status: int, msg: string, url: string}
     */
    public function generateScanUrl(string $certifyId): array
    {
        return [
            'status' => 200,
            'msg' => 'Demo 扫码链接已生成',
            'url' => 'https://example.test/verification/demo?certify_id='.rawurlencode($certifyId),
        ];
    }

    /**
     * @return array{status: int, msg: string}
     */
    public function queryStatus(string $certifyId): array
    {
        return [
            'status' => 4,
            'msg' => 'Demo 认证待完成',
        ];
    }

    /**
     * @param  array<string, mixed>  $requestPayload
     * @param  array<string, mixed>  $config
     * @return array{passed: bool, message: string, code: int, http_status: int, replay_key?: string}
     */
    public function verifyCallback(array $requestPayload, array $config): array
    {
        $payload = is_array($requestPayload['payload'] ?? null) ? $requestPayload['payload'] : [];
        $headers = is_array($requestPayload['headers'] ?? null) ? $requestPayload['headers'] : [];
        $certifyId = trim((string) ($payload['certify_id'] ?? ''));
        $timestamp = trim((string) ($this->header($headers, 'x-timestamp') ?? $payload['timestamp'] ?? ''));
        $nonce = trim((string) ($this->header($headers, 'x-nonce') ?? $payload['nonce'] ?? ''));
        $signature = trim((string) ($this->header($headers, 'x-signature') ?? $payload['sign'] ?? $payload['signature'] ?? ''));
        $secret = trim((string) ($config['app_secret'] ?? ''));

        if ($certifyId === '' || $timestamp === '' || $nonce === '' || $signature === '' || $secret === '') {
            return $this->callbackRejected();
        }

        $timestampValue = (int) $timestamp;
        if ($timestampValue <= 0 || abs(time() - $timestampValue) > 300) {
            return $this->callbackRejected();
        }

        $expectedSign = hash_hmac('sha256', $this->canonicalPayload($payload, $timestamp, $nonce), $secret);
        if (! hash_equals($expectedSign, $signature)) {
            return $this->callbackRejected();
        }

        return [
            'passed' => true,
            'message' => '签名验证通过',
            'code' => 0,
            'http_status' => 200,
            'replay_key' => $certifyId.'|'.$nonce,
        ];
    }

    /**
     * @param  array<string, mixed>  $config
     * @return array{free_attempts: int, retry_fee: float, free_times: int, amount: float, charge_enabled: bool}
     */
    public function feeConfig(array $config): array
    {
        $chargeEnabled = filter_var($config['charge_enabled'] ?? false, FILTER_VALIDATE_BOOL);
        $amount = max(0.0, (float) ($config['amount'] ?? 0));
        $freeTimes = max(0, (int) ($config['free_times'] ?? 0));

        return [
            'free_attempts' => $freeTimes,
            'retry_fee' => $chargeEnabled ? $amount : 0.0,
            'free_times' => $freeTimes,
            'amount' => $amount,
            'charge_enabled' => $chargeEnabled,
        ];
    }

    /**
     * @param  array<string, string>  $headers
     */
    private function header(array $headers, string $name): ?string
    {
        $normalized = strtolower($name);

        return array_key_exists($normalized, $headers) ? (string) $headers[$normalized] : null;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function canonicalPayload(array $payload, string $timestamp, string $nonce): string
    {
        unset($payload['sign'], $payload['signature']);

        $payload['timestamp'] = $timestamp;
        $payload['nonce'] = $nonce;

        $this->ksortRecursive($payload);

        return json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '{}';
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function ksortRecursive(array &$payload): void
    {
        ksort($payload);

        foreach ($payload as &$value) {
            if (is_array($value)) {
                $this->ksortRecursive($value);
            }
        }
    }

    /**
     * @return array{passed: bool, message: string, code: int, http_status: int}
     */
    private function callbackRejected(): array
    {
        return [
            'passed' => false,
            'message' => '签名验证失败',
            'code' => 40001,
            'http_status' => 401,
        ];
    }
}
