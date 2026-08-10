<?php

declare(strict_types=1);

namespace App\Http\Resources\Admin\V2;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AdminServiceListItemResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $item = is_array($this->resource) ? $this->resource : [];

        return [
            'id' => (int) ($item['id'] ?? 0),
            'service_id' => (int) ($item['service_id'] ?? $item['id'] ?? 0),
            'instance_id' => (int) ($item['instance_id'] ?? $item['id'] ?? 0),
            'name' => (string) ($item['name'] ?? ''),
            'product_id' => (int) data_get($item, 'product.id', 0),
            'product_display_name' => (string) ($item['product_display_name'] ?? ''),
            'domain' => (string) ($item['domain'] ?? ''),
            'requested_hostname' => (string) ($item['requested_hostname'] ?? ''),
            'custom_hostname' => (string) ($item['custom_hostname'] ?? ''),
            'has_custom_hostname' => (bool) ($item['has_custom_hostname'] ?? false),
            'status' => (int) ($item['status'] ?? 0),
            'status_label' => (string) ($item['status_label'] ?? ''),
            'billing_cycle' => (string) ($item['billing_cycle'] ?? ''),
            'amount' => (string) ($item['amount'] ?? ''),
            'expires_at' => $item['expires_at'] ?? null,
            'created_at' => $item['created_at'] ?? null,
            'auto_renew' => (bool) ($item['auto_renew'] ?? false),
            'upstream_host_id' => (int) ($item['upstream_host_id'] ?? 0),
            'upstream_host_id_text' => (string) ($item['upstream_host_id_text'] ?? ''),
            'upstream_host_ids' => $this->stringList($item['upstream_host_ids'] ?? []),
            'dedicated_ip' => (string) ($item['dedicated_ip'] ?? ''),
            'host_ips' => $this->stringList($item['host_ips'] ?? []),
            'internal_ip' => (string) ($item['internal_ip'] ?? ''),
            'host_username' => (string) ($item['host_username'] ?? ''),
            'connection' => $this->connection($item['connection'] ?? null),
            'os' => (string) ($item['os'] ?? ''),
            'user' => $this->user($item['user'] ?? null),
            'product' => $this->product($item['product'] ?? null),
            'order' => $this->order($item['order'] ?? null),
            'invoice' => $this->invoice($item['invoice'] ?? null),
        ];
    }

    /**
     * @return list<string>
     */
    private function stringList(mixed $value): array
    {
        return collect(is_array($value) ? $value : [])
            ->map(fn (mixed $item): string => trim((string) $item))
            ->filter(fn (string $item): bool => $item !== '')
            ->values()
            ->all();
    }

    /**
     * @return array<string, mixed>
     */
    private function connection(mixed $value): array
    {
        $connection = is_array($value) ? $value : [];

        return [
            'hostname' => (string) ($connection['hostname'] ?? ''),
            'username' => (string) ($connection['username'] ?? ''),
            'internal_ip' => (string) ($connection['internal_ip'] ?? ''),
            'port' => (int) ($connection['port'] ?? 0),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function user(mixed $value): array
    {
        $user = is_array($value) ? $value : [];

        return [
            'id' => (int) ($user['id'] ?? 0),
            'username' => (string) ($user['username'] ?? ''),
            'email' => (string) ($user['email'] ?? ''),
            'phone' => (string) ($user['phone'] ?? ''),
            'status' => (int) ($user['status'] ?? 0),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function product(mixed $value): array
    {
        $product = is_array($value) ? $value : [];

        return [
            'id' => (int) ($product['id'] ?? 0),
            'name' => (string) ($product['name'] ?? ''),
            'display_name' => (string) ($product['display_name'] ?? $product['name'] ?? ''),
            'type' => (string) ($product['type'] ?? ''),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function order(mixed $value): array
    {
        $order = is_array($value) ? $value : [];

        return [
            'id' => (int) ($order['id'] ?? 0),
            'order_no' => (string) ($order['order_no'] ?? ''),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function invoice(mixed $value): array
    {
        $invoice = is_array($value) ? $value : [];

        return [
            'id' => (int) ($invoice['id'] ?? 0),
            'invoice_no' => (string) ($invoice['invoice_no'] ?? ''),
            'status' => (int) ($invoice['status'] ?? 0),
            'paid_at' => $invoice['paid_at'] ?? null,
        ];
    }
}
