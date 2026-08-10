<?php

declare(strict_types=1);

namespace EwyFinance\Plugins\Certification\DemoVerification;

use EwyFinance\Plugins\Certification\DemoVerification\Logic\DemoVerification;

class DemoVerificationPlugin extends DemoVerification
{
    public function execute(array $request): array
    {
        $action = trim((string) ($request['action'] ?? ''));
        $payload = is_array($request['payload'] ?? null) ? $request['payload'] : [];
        $config = is_array($request['config'] ?? null) ? $request['config'] : [];

        return match ($action) {
            'certification.initialize' => $this->success($action, $this->initialize(
                (string) ($payload['real_name'] ?? ''),
                (string) ($payload['id_card'] ?? ''),
            )),
            'certification.scan_url' => $this->success($action, $this->generateScanUrl((string) ($payload['certify_id'] ?? ''))),
            'certification.query_status' => $this->success($action, $this->queryStatus((string) ($payload['certify_id'] ?? ''))),
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

    private function success(string $action, array $data): array
    {
        return [
            'success' => true,
            'action' => $action,
            'data' => $data,
        ];
    }
}
