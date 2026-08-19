<?php

declare(strict_types=1);

namespace TuraIDC\Plugins\Certification\Smapi\Logic;

class Smapi
{
    private ?SmapiClient $client = null;

    public function key(): string
    {
        return 'smapi';
    }

    public function label(): string
    {
        return '聚合实名认证';
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
                returnUrl: (string) ($payload['return_url'] ?? ''),
            )),
            'certification.scan_url' => $this->success($action, $client->scanUrl(
                (string) ($payload['certify_id'] ?? '')
            )),
            'certification.query_status' => $this->success($action, $client->queryStatus(
                (string) ($payload['certify_id'] ?? '')
            )),
            'certification.verify_callback' => $this->success($action, $this->verifyCallback()),
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
    private function client(array $config): SmapiClient
    {
        if ($this->client === null) {
            $this->client = new SmapiClient($config);
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
     * 聚合实名平台不提供服务端签名回调，认证结果通过轮询 query_status 获取。
     *
     * @return array{passed: bool, message: string, code: int, http_status: int}
     */
    private function verifyCallback(): array
    {
        return [
            'passed' => false,
            'message' => '聚合实名平台不支持服务端签名回调，请使用轮询查询认证结果',
            'code' => 40001,
            'http_status' => 501,
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
