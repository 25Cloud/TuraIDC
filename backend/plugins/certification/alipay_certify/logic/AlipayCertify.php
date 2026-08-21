<?php

declare(strict_types=1);

namespace TuraIDC\Plugins\Certification\AlipayCertify\Logic;

class AlipayCertify
{
    private ?AlipayCertifyClient $client = null;

    public function key(): string
    {
        return 'alipay_certify';
    }

    public function label(): string
    {
        return '支付宝身份认证';
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
            'certification.verify_callback' => $this->success($action, $client->verifyNotify(
                is_array($payload['payload'] ?? null) ? $payload['payload'] : []
            )),
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
    private function client(array $config): AlipayCertifyClient
    {
        if ($this->client === null) {
            $this->client = new AlipayCertifyClient($config);
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
            // 关闭收费时重试费必须为 0：该值会随接口下发给前端展示
            'retry_fee' => $chargeEnabled ? $amount : 0.0,
            'free_times' => $freeTimes,
            'amount' => $amount,
            'charge_enabled' => $chargeEnabled,
        ];
    }
}
