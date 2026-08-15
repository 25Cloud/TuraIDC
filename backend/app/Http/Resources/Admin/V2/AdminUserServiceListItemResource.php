<?php

declare(strict_types=1);

namespace App\Http\Resources\Admin\V2;

use App\Http\Resources\Admin\V2\Concerns\StripsSensitiveResourceData;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AdminUserServiceListItemResource extends JsonResource
{
    use StripsSensitiveResourceData;

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $item = is_array($this->resource) ? $this->resource : [];

        return [
            'id' => (int) ($item['id'] ?? 0),
            'name' => (string) ($item['name'] ?? ''),
            'product_display_name' => (string) ($item['product_display_name'] ?? ''),
            'product_full_path' => (string) ($item['product_full_path'] ?? ''),
            'domain' => (string) ($item['domain'] ?? ''),
            'custom_hostname' => (string) ($item['custom_hostname'] ?? ''),
            'has_custom_hostname' => (bool) ($item['has_custom_hostname'] ?? false),
            'status' => (int) ($item['status'] ?? 0),
            'status_label' => (string) ($item['status_label'] ?? ''),
            'status_tone' => (string) ($item['status_tone'] ?? ''),
            'billing_cycle' => (string) ($item['billing_cycle'] ?? ''),
            'billing_cycle_label' => (string) ($item['billing_cycle_label'] ?? ''),
            'amount' => (string) ($item['amount'] ?? '0.00'),
            'expires_at' => $item['expires_at'] ?? null,
            'created_at' => $item['created_at'] ?? null,
            'product' => $this->product($item['product'] ?? null),
            'invoice' => $this->invoice($item['invoice'] ?? null),
            'custom_service_name' => (string) ($item['custom_service_name'] ?? ''),
            'has_custom_service_name' => (bool) ($item['has_custom_service_name'] ?? false),
            'upstream' => $this->upstream($item['upstream'] ?? null),
            'remark' => (string) ($item['remark'] ?? ''),
            'can_manage' => (bool) ($item['can_manage'] ?? false),
            'console_mode' => (string) ($item['console_mode'] ?? ''),
            'is_nat_console' => (bool) ($item['is_nat_console'] ?? false),
            'machine_category' => $this->stripSensitiveKeys((array) ($item['machine_category'] ?? [])),
            'specs' => $this->specs($item['specs'] ?? []),
        ];
    }

    private function product(mixed $product): array
    {
        $product = is_array($product) ? $product : [];

        return [
            'name' => (string) ($product['name'] ?? ''),
            'display_name' => (string) ($product['display_name'] ?? ''),
            'type' => (string) ($product['type'] ?? ''),
            'type_label' => (string) ($product['type_label'] ?? ''),
            'catalog_type' => (string) ($product['catalog_type'] ?? ''),
            'group_name' => (string) ($product['group_name'] ?? ''),
            'root_group_name' => (string) ($product['root_group_name'] ?? ''),
            'menu_name' => (string) ($product['menu_name'] ?? ''),
        ];
    }

    private function invoice(mixed $invoice): array
    {
        $invoice = is_array($invoice) ? $invoice : [];

        return [
            'id' => (int) ($invoice['id'] ?? 0),
            'invoice_no' => (string) ($invoice['invoice_no'] ?? ''),
        ];
    }

    private function upstream(mixed $upstream): array
    {
        $upstream = is_array($upstream) ? $upstream : [];

        return [
            'host_id' => (int) ($upstream['host_id'] ?? 0),
            'status' => (string) ($upstream['status'] ?? ''),
            'status_label' => (string) ($upstream['status_label'] ?? ''),
            'dedicated_ip' => (string) ($upstream['dedicated_ip'] ?? ''),
            'os' => (string) ($upstream['os'] ?? ''),
        ];
    }

    private function specs(mixed $specs): array
    {
        return collect(is_array($specs) ? $specs : [])
            ->filter(fn (mixed $item): bool => is_array($item))
            ->map(fn (array $item): array => [
                'key' => (string) ($item['key'] ?? ''),
                'label' => (string) ($item['label'] ?? ''),
                'value' => (string) ($item['value'] ?? ''),
            ])
            ->filter(fn (array $item): bool => $item['key'] !== '' || $item['label'] !== '')
            ->values()
            ->all();
    }
}
