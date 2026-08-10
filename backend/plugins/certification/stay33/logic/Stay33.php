<?php

declare(strict_types=1);

namespace EwyFinance\Plugins\Certification\Stay33\Logic;

class Stay33
{
    private ?Stay33Client $client = null;

    public function key(): string
    {
        return 'stay33';
    }

    public function label(): string
    {
        return 'Stay33 实名认证';
    }

    public function execute(array $request): array
    {
        $action = trim((string) ($request['action'] ?? ''));
        $payload = is_array($request['payload'] ?? null) ? $request['payload'] : [];
        $config = is_array($request['config'] ?? null) ? $request['config'] : [];

        $client = $this->client($config);

        return match ($action) {
            'certification.initialize' => $this->success($action, $client->initialize(
                realName: (string) ($payload['real_name'] ?? ''),
                idCard: (string) ($payload['id_card'] ?? ''),
                certType: (string) ($payload['cert_type'] ?? ''),
                returnUrl: (string) ($payload['return_url'] ?? ''),
            )),
            'certification.scan_url' => $this->success($action, $client->generateScanUrl(
                (string) ($payload['certify_id'] ?? '')
            )),
            'certification.query_status' => $this->success($action, $client->queryStatus(
                (string) ($payload['certify_id'] ?? '')
            )),
            'certification.verify_callback' => $this->success($action, $this->verifyCallback($payload, $config)),
            'certification.fee_config' => $this->success($action, $this->feeConfig($config)),
            default => [
                'success' => false,
                'action' => $action,
                'message' => 'Unsupported plugin action',
                'data' => [],
            ],
        };
    }

    private function client(array $config): Stay33Client
    {
        if ($this->client === null) {
            $this->client = new Stay33Client($config);
        }

        return $this->client;
    }

    private function success(string $action, array $data): array
    {
        return [
            'success' => true,
            'action' => $action,
            'data' => $data,
        ];
    }

    /**
     * @param  array<string, mixed>  $requestPayload
     * @param  array<string, mixed>  $config
     * @return array{passed: bool, message: string, code: int, http_status: int, replay_key?: string}
     */
    private function verifyCallback(array $requestPayload, array $config): array
    {
        $payload = is_array($requestPayload['payload'] ?? null) ? $requestPayload['payload'] : [];
        $headers = is_array($requestPayload['headers'] ?? null) ? $requestPayload['headers'] : [];

        $signature = trim((string) ($this->header($headers, 'x-signature') ?? $payload['sign'] ?? $payload['signature'] ?? ''));
        $certifyId = trim((string) ($payload['certify_id'] ?? $payload['order_no'] ?? ''));
        $timestamp = trim((string) ($this->header($headers, 'x-timestamp') ?? $payload['timestamp'] ?? ''));
        $nonce = trim((string) ($this->header($headers, 'x-nonce') ?? $payload['nonce'] ?? ''));
        $secret = trim((string) ($config['callback_secret'] ?? $config['key'] ?? ''));

        if ($signature === '' || $certifyId === '' || $timestamp === '' || $nonce === '' || $secret === '') {
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
    private function feeConfig(array $config): array
    {
        $chargeEnabled = filter_var($config['charge_enabled'] ?? false, FILTER_VALIDATE_BOOL);
        $amount = max(0.0, (float) ($config['amount'] ?? 0));
        $freeTimes = max(0, (int) ($config['free_times'] ?? $config['free_attempts'] ?? 0));

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
