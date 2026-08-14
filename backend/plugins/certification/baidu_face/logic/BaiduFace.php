<?php

declare(strict_types=1);

namespace TuraIDC\Plugins\Certification\BaiduFace\Logic;

class BaiduFace
{
    private ?BaiduFaceClient $client = null;

    public function key(): string
    {
        return 'baidu_face';
    }

    public function label(): string
    {
        return '百度智能云人脸实名认证';
    }

    /**
     * @param  array<string, mixed>  $request
     * @return array<string, mixed>
     */
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
            'certification.direct_verify' => $this->success($action, $client->directVerify($payload)),
            'certification.verify_callback' => $this->success($action, $this->verifyCallback($payload)),
            'certification.fee_config' => $this->success($action, $this->feeConfig($config)),
            default => [
                'success' => false,
                'action' => $action,
                'message' => 'Unsupported plugin action',
                'data' => [],
            ],
        };
    }

    /**
     * @param  array<string, mixed>  $config
     */
    private function client(array $config): BaiduFaceClient
    {
        if ($this->client === null) {
            $this->client = new BaiduFaceClient($config);
        }

        return $this->client;
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array{success: bool, action: string, data: array<string, mixed>}
     */
    private function success(string $action, array $data): array
    {
        return [
            'success' => true,
            'action' => $action,
            'data' => $data,
        ];
    }

    /**
     * 百度 H5 方案采用后验结果查询，回跳请求本身不承载可信签名。
     *
     * @param  array<string, mixed>  $requestPayload
     * @return array{passed: bool, message: string, code: int, http_status: int, replay_key?: string}
     */
    private function verifyCallback(array $requestPayload): array
    {
        $payload = is_array($requestPayload['payload'] ?? null) ? $requestPayload['payload'] : [];
        $certifyId = trim((string) ($payload['certify_id'] ?? $payload['token'] ?? ''));

        if ($certifyId === '') {
            return [
                'passed' => false,
                'message' => '签名验证失败',
                'code' => 40001,
                'http_status' => 401,
            ];
        }

        return [
            'passed' => true,
            'message' => '回跳参数已接收',
            'code' => 0,
            'http_status' => 200,
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
}
