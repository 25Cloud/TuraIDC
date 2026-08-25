<?php

declare(strict_types=1);

namespace App\Http\Resources\Service\V2;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ServiceRuntimeResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $detail = is_array($this->resource) ? $this->resource : [];

        return [
            'id' => (int) ($detail['id'] ?? 0),
            'status' => (int) ($detail['status'] ?? 0),
            'status_label' => (string) ($detail['status_label'] ?? ''),
            'status_tone' => (string) ($detail['status_tone'] ?? ''),
            'expires_at' => $detail['expires_at'] ?? null,
            'upstream' => $this->upstream((array) ($detail['upstream'] ?? []), $request),
            'runtime' => $this->runtime((array) ($detail['runtime'] ?? [])),
            'traffic' => $this->traffic((array) ($detail['traffic'] ?? [])),
            'actions' => $this->actions((array) ($detail['actions'] ?? [])),
            'specs' => $this->specs((array) ($detail['specs'] ?? [])),
            '_sync' => $this->syncMarker((array) ($detail['_sync'] ?? [])),
        ];
    }

    /**
     * 后台异步同步完成后写入的变更标记，前端据此提示信息已更新。
     *
     * @param  array<string, mixed>  $marker
     * @return array<string, mixed>|null
     */
    private function syncMarker(array $marker): ?array
    {
        if (! ($marker['changed'] ?? false)) {
            return null;
        }

        return [
            'changed' => true,
            'changed_at' => (string) ($marker['changed_at'] ?? ''),
        ];
    }

    /**
     * @param  array<string, mixed>  $upstream
     * @return array<string, mixed>
     */
    private function upstream(array $upstream, Request $request): array
    {
        $payload = [
            'host_id' => (int) ($upstream['host_id'] ?? 0),
            'status' => (string) ($upstream['status'] ?? ''),
            'status_label' => (string) ($upstream['status_label'] ?? ''),
            'remote_error' => (string) ($upstream['remote_error'] ?? ''),
            'dedicated_ip' => (string) ($upstream['dedicated_ip'] ?? ''),
            'os' => (string) ($upstream['os'] ?? ''),
        ];

        // 用户端不输出供应商身份字段，管理端仍保留。
        if (! $request->is('api/v2/client/*')) {
            $payload = [
                'provider_key' => (string) ($upstream['provider_key'] ?? ''),
                'supplier_id' => (int) ($upstream['supplier_id'] ?? 0),
                'upstream_product_id' => (string) ($upstream['upstream_product_id'] ?? ''),
                ...$payload,
            ];
        }

        return $payload;
    }

    /**
     * @param  array<string, mixed>  $runtime
     * @return array<string, mixed>
     */
    private function runtime(array $runtime): array
    {
        return [
            'power_state' => (string) ($runtime['power_state'] ?? ''),
            'power_label' => (string) ($runtime['power_label'] ?? ''),
            'description' => (string) ($runtime['description'] ?? ''),
        ];
    }

    /**
     * @param  array<string, mixed>  $traffic
     * @return array<string, mixed>
     */
    private function traffic(array $traffic): array
    {
        return [
            'usage' => (string) ($traffic['usage'] ?? '0.00'),
            'limit' => (int) ($traffic['limit'] ?? 0),
            'remaining' => (string) ($traffic['remaining'] ?? ''),
            'usage_label' => (string) ($traffic['usage_label'] ?? ''),
            'limit_label' => (string) ($traffic['limit_label'] ?? ''),
            'remaining_label' => (string) ($traffic['remaining_label'] ?? ''),
            'usage_percent' => $traffic['usage_percent'] ?? null,
            'limited' => (bool) ($traffic['limited'] ?? false),
            'button_text' => (string) ($traffic['button_text'] ?? ''),
        ];
    }

    /**
     * @param  array<string, mixed>  $actions
     * @return array<string, mixed>
     */
    private function actions(array $actions): array
    {
        return [
            'refresh' => (bool) ($actions['refresh'] ?? false),
            'power' => (bool) ($actions['power'] ?? false),
            'module_status' => (bool) ($actions['module_status'] ?? false),
            'manual_provision' => (bool) ($actions['manual_provision'] ?? false),
            'password_reset' => (bool) ($actions['password_reset'] ?? false),
            'reinstall' => (bool) ($actions['reinstall'] ?? false),
            'traffic_package' => (bool) ($actions['traffic_package'] ?? false),
            'available' => array_values(array_filter(
                array_map('strval', (array) ($actions['available'] ?? [])),
                fn (string $action): bool => $action !== ''
            )),
        ];
    }

    /**
     * @param  array<int, mixed>  $specs
     * @return list<array<string, string>>
     */
    private function specs(array $specs): array
    {
        return collect($specs)
            ->filter(fn (mixed $item): bool => is_array($item))
            ->map(fn (array $item): array => [
                'key' => (string) ($item['key'] ?? ''),
                'label' => (string) ($item['label'] ?? ''),
                'value' => (string) ($item['value'] ?? ''),
            ])
            ->filter(fn (array $item): bool => $item['key'] !== '' && $item['label'] !== '')
            ->values()
            ->all();
    }
}
