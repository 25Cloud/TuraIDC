<?php

declare(strict_types=1);

namespace App\Http\Resources\Admin\V2;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AdminInvoiceSummaryResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $invoice = is_array($this->resource) ? $this->resource : [];

        return [
            'id' => (int) ($invoice['id'] ?? 0),
            'invoice_no' => (string) ($invoice['invoice_no'] ?? ''),
            'user_id' => (int) ($invoice['user']['id'] ?? $invoice['user_id'] ?? 0),
            'user' => $this->user($invoice['user'] ?? null),
            'order_id' => (int) ($invoice['order_id'] ?? 0),
            'order' => $this->order($invoice['order'] ?? null),
            'product_id' => (int) ($invoice['product_id'] ?? 0),
            'product' => $this->product($invoice['product'] ?? null),
            'product_spec_display' => (string) ($invoice['product_spec_display'] ?? ''),
            'product_display_name' => (string) ($invoice['product_display_name'] ?? ''),
            'combined_display_name' => (string) ($invoice['combined_display_name'] ?? ''),
            'type' => (string) ($invoice['type'] ?? ''),
            'type_label' => (string) ($invoice['type_label'] ?? ''),
            'amount' => (string) ($invoice['amount'] ?? '0.00'),
            'discount' => (string) ($invoice['discount'] ?? '0.00'),
            'paid_amount' => (string) ($invoice['paid_amount'] ?? '0.00'),
            'payable_amount' => (string) ($invoice['payable_amount'] ?? '0.00'),
            'status' => (int) ($invoice['status'] ?? 0),
            'status_label' => (string) ($invoice['status_label'] ?? ''),
            'billing_cycle' => (string) ($invoice['billing_cycle'] ?? ''),
            'quantity' => (int) ($invoice['quantity'] ?? 1),
            'summary' => $this->summary($invoice['summary'] ?? null),
            'due_date' => $invoice['due_date'] ?? null,
            'paid_at' => $invoice['paid_at'] ?? null,
            'created_at' => $invoice['created_at'] ?? null,
        ];
    }

    protected function user(mixed $user): ?array
    {
        if (! is_array($user)) {
            return null;
        }

        return [
            'id' => (int) ($user['id'] ?? 0),
            'email' => (string) ($user['email'] ?? ''),
            'nickname' => (string) ($user['nickname'] ?? ''),
            'phone' => (string) ($user['phone'] ?? ''),
        ];
    }

    protected function order(mixed $order): ?array
    {
        if (! is_array($order)) {
            return null;
        }

        return [
            'id' => (int) ($order['id'] ?? 0),
            'order_no' => (string) ($order['order_no'] ?? ''),
            'status' => (int) ($order['status'] ?? 0),
            'type' => (string) ($order['type'] ?? ''),
        ];
    }

    protected function product(mixed $product): ?array
    {
        if (! is_array($product)) {
            return null;
        }

        return [
            'id' => (int) ($product['id'] ?? 0),
            'name' => (string) ($product['name'] ?? ''),
            'product_type' => (string) ($product['product_type'] ?? ''),
        ];
    }

    protected function service(mixed $service): ?array
    {
        if (! is_array($service)) {
            return null;
        }

        return [
            'id' => (int) ($service['id'] ?? 0),
            'name' => (string) ($service['name'] ?? ''),
            'status' => (int) ($service['status'] ?? 0),
            'expires_at' => $service['expires_at'] ?? null,
        ];
    }

    protected function summary(mixed $summary): array
    {
        $summary = is_array($summary) ? $summary : [];

        return [
            'headline' => (string) ($summary['headline'] ?? ''),
            'subheadline' => (string) ($summary['subheadline'] ?? ''),
            'badge' => (string) ($summary['badge'] ?? ''),
            'highlight' => (string) ($summary['highlight'] ?? ''),
            'remark' => (string) ($summary['remark'] ?? ''),
        ];
    }

    protected function cleanList(mixed $items): array
    {
        return collect(is_array($items) ? $items : [])
            ->filter(fn (mixed $item): bool => is_array($item))
            ->map(fn (array $item): array => $this->stripSensitiveKeys($item))
            ->values()
            ->all();
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    protected function stripSensitiveKeys(array $payload): array
    {
        $clean = [];

        foreach ($payload as $key => $value) {
            $normalized = strtolower((string) $key);
            if ($this->isSensitiveKey($normalized)) {
                continue;
            }

            $clean[$key] = is_array($value) ? $this->stripSensitiveKeys($value) : $value;
        }

        return $clean;
    }

    protected function isSensitiveKey(string $key): bool
    {
        foreach (['password', 'secret', 'api_key', 'raw_response', 'third_party_response'] as $needle) {
            if (str_contains($key, $needle)) {
                return true;
            }
        }

        return false;
    }
}
