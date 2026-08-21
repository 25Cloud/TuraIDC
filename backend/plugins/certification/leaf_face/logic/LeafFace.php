<?php

declare(strict_types=1);

namespace TuraIDC\Plugins\Certification\LeafFace\Logic;

class LeafFace
{
    private ?LeafFaceClient $client = null;

    public function key(): string
    {
        return 'leaf_face';
    }

    public function label(): string
    {
        return 'leaf实名';
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

    /**
     * @param  array<string, mixed>  $config
     */
    private function client(array $config): LeafFaceClient
    {
        if ($this->client === null) {
            $this->client = new LeafFaceClient($config);
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
     * leaf 平台通过服务端回调通知任务终态，验签由 LeafFaceClient 完成。
     *
     * @param  array<string, mixed>  $requestPayload
     * @param  array<string, mixed>  $config
     * @return array{passed: bool, message: string, code: int, http_status: int, replay_key?: string, certify_id?: string}
     */
    private function verifyCallback(array $requestPayload, array $config): array
    {
        $rawPayload = is_array($requestPayload['payload'] ?? null) ? $requestPayload['payload'] : [];
        $headers = is_array($requestPayload['headers'] ?? null) ? $requestPayload['headers'] : [];
        $rawBody = (string) ($requestPayload['raw_body'] ?? '');

        return $this->client($config)->verifyCallback($rawPayload, $headers, $rawBody);
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
            // 与同域其余 4 个实名插件对齐：关闭收费时重试费必须为 0。
            // 扣费路径本身有 charge_enabled 门控（VerificationService），所以此前不会错扣钱，
            // 但 retry_fee 会随接口下发给前端展示，关闭收费时显示非零金额。
            'retry_fee' => $chargeEnabled ? $amount : 0.0,
            'free_times' => $freeTimes,
            'amount' => $amount,
            'charge_enabled' => $chargeEnabled,
        ];
    }
}
