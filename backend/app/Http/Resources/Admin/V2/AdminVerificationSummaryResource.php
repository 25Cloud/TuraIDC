<?php

declare(strict_types=1);

namespace App\Http\Resources\Admin\V2;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AdminVerificationSummaryResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $payload = is_array($this->resource) ? $this->resource : [];
        $stats = is_array($payload['stats'] ?? null) ? $payload['stats'] : [];
        $config = is_array($payload['config'] ?? null) ? $payload['config'] : [];

        return [
            'stats' => [
                'total' => (int) ($stats['total'] ?? 0),
                'verified' => (int) ($stats['verified'] ?? 0),
                'pending' => (int) ($stats['pending'] ?? 0),
                'failed' => (int) ($stats['failed'] ?? 0),
                'unbound' => (int) ($stats['unbound'] ?? 0),
            ],
            'config' => [
                'verification_api_masked' => (string) ($config['verification_api_masked'] ?? ''),
                'verification_biz_code' => (string) ($config['verification_biz_code'] ?? ''),
                'configured' => (bool) ($config['configured'] ?? false),
                'driver_key' => (string) ($config['driver_key'] ?? ''),
                'plugin_id' => (int) ($config['plugin_id'] ?? 0),
                'free_attempts' => (int) ($config['free_attempts'] ?? 0),
                'retry_fee' => (float) ($config['retry_fee'] ?? 0),
                'charge_enabled' => (bool) ($config['charge_enabled'] ?? false),
                'amount' => (float) ($config['amount'] ?? 0),
            ],
        ];
    }
}
