<?php

declare(strict_types=1);

namespace App\Http\Resources\Service\V2;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ServiceDetailResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $detail = is_array($this->resource) ? $this->resource : [];

        return [
            'id' => (int) ($detail['id'] ?? 0),
            'name' => (string) ($detail['name'] ?? ''),
            'product_display_name' => (string) ($detail['product_display_name'] ?? ''),
            'combined_display_name' => (string) ($detail['combined_display_name'] ?? ''),
            'domain' => (string) ($detail['domain'] ?? ''),
            'status' => (int) ($detail['status'] ?? 0),
            'status_label' => (string) ($detail['status_label'] ?? ''),
            'status_tone' => (string) ($detail['status_tone'] ?? ''),
            'billing_cycle' => (string) ($detail['billing_cycle'] ?? ''),
            'billing_cycle_label' => (string) ($detail['billing_cycle_label'] ?? ''),
            'amount' => (string) ($detail['amount'] ?? '0.00'),
            'expires_at' => $detail['expires_at'] ?? null,
            'created_at' => $detail['created_at'] ?? null,
            'auto_renew' => (int) ($detail['auto_renew'] ?? 0),
            'suspended_reason' => $detail['suspended_reason'] ?? null,
            'remark' => (string) ($detail['remark'] ?? ''),
            'custom_service_name' => (string) ($detail['custom_service_name'] ?? ''),
            'has_custom_service_name' => (bool) ($detail['has_custom_service_name'] ?? false),
            'custom_hostname' => (string) ($detail['custom_hostname'] ?? ''),
            'has_custom_hostname' => (bool) ($detail['has_custom_hostname'] ?? false),
            'console_mode' => (string) ($detail['console_mode'] ?? ''),
            'is_nat_console' => (bool) ($detail['is_nat_console'] ?? false),
            'product' => $this->product((array) ($detail['product'] ?? [])),
            'invoice' => $this->invoice((array) ($detail['invoice'] ?? [])),
            'upstream' => $this->upstream((array) ($detail['upstream'] ?? [])),
            'runtime' => $this->runtime((array) ($detail['runtime'] ?? [])),
            'specs' => $this->specs((array) ($detail['specs'] ?? [])),
            'traffic' => $this->traffic((array) ($detail['traffic'] ?? [])),
            'renewal' => [
                'has_custom_renew_pricing' => (bool) ($detail['has_custom_renew_pricing'] ?? false),
                'has_locked_pricing' => (bool) ($detail['has_locked_pricing'] ?? false),
                'cycles' => $this->renewalCycles((array) ($detail['renew_pricing_cycles'] ?? [])),
            ],
            'actions' => $this->actions((array) ($detail['actions'] ?? [])),
        ];
    }

    /**
     * @param  array<string, mixed>  $product
     * @return array<string, mixed>
     */
    private function product(array $product): array
    {
        return [
            'id' => (int) ($product['id'] ?? 0),
            'name' => (string) ($product['name'] ?? ''),
            'display_name' => (string) ($product['display_name'] ?? ''),
            'type' => (string) ($product['type'] ?? ''),
            'type_label' => (string) ($product['type_label'] ?? ''),
            'catalog_type' => (string) ($product['catalog_type'] ?? ''),
        ];
    }

    /**
     * @param  array<string, mixed>  $invoice
     * @return array<string, mixed>
     */
    private function invoice(array $invoice): array
    {
        return [
            'id' => (int) ($invoice['id'] ?? 0),
            'invoice_no' => (string) ($invoice['invoice_no'] ?? ''),
            'order_no' => (string) ($invoice['order_no'] ?? ''),
            'status' => (int) ($invoice['status'] ?? 0),
            'paid_at' => $invoice['paid_at'] ?? null,
        ];
    }

    /**
     * @param  array<string, mixed>  $upstream
     * @return array<string, mixed>
     */
    private function upstream(array $upstream): array
    {
        return [
            'provider_key' => (string) ($upstream['provider_key'] ?? ''),
            'supplier_id' => (int) ($upstream['supplier_id'] ?? 0),
            'upstream_product_id' => (string) ($upstream['upstream_product_id'] ?? ''),
            'host_id' => (int) ($upstream['host_id'] ?? 0),
            'status' => (string) ($upstream['status'] ?? ''),
            'status_label' => (string) ($upstream['status_label'] ?? ''),
            'remote_error' => (string) ($upstream['remote_error'] ?? ''),
            'dedicated_ip' => (string) ($upstream['dedicated_ip'] ?? ''),
            'os' => (string) ($upstream['os'] ?? ''),
        ];
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
     * @param  array<int, mixed>  $cycles
     * @return list<array<string, mixed>>
     */
    private function renewalCycles(array $cycles): array
    {
        return collect($cycles)
            ->filter(fn (mixed $item): bool => is_array($item))
            ->map(fn (array $item): array => [
                'billing_cycle' => (string) ($item['billing_cycle'] ?? ''),
                'billing_cycle_label' => (string) ($item['billing_cycle_label'] ?? ''),
                'enabled' => (bool) ($item['enabled'] ?? false),
                'base_amount' => $item['base_amount'] ?? null,
                'manual_amount' => $item['manual_amount'] ?? null,
                'effective_amount' => $item['effective_amount'] ?? null,
            ])
            ->filter(fn (array $item): bool => $item['billing_cycle'] !== '')
            ->values()
            ->all();
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
}
