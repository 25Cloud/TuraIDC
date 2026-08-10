<?php

declare(strict_types=1);

namespace App\Http\Resources\Admin\V2;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AdminFinanceOrderListItemResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $item = is_array($this->resource) ? $this->resource : [];

        $payload = [
            'id' => (int) ($item['id'] ?? 0),
            'order_no' => (string) ($item['order_no'] ?? ''),
            'user_id' => (int) ($item['user_id'] ?? 0),
            'user' => $this->user($item['user'] ?? null),
            'invoice' => $this->invoice($item['invoice'] ?? null),
            'product_id' => (int) ($item['product_id'] ?? 0),
            'product_name' => (string) ($item['product_name'] ?? ''),
            'product_full_path' => (string) ($item['product_full_path'] ?? ''),
            'product_type' => (string) ($item['product_type'] ?? ''),
            'service' => $this->service($item['service'] ?? null),
            'type' => (string) ($item['type'] ?? ''),
            'type_label' => (string) ($item['type_label'] ?? ''),
            'status' => (int) ($item['status'] ?? 0),
            'status_label' => (string) ($item['status_label'] ?? ''),
            'amount' => (string) ($item['amount'] ?? '0.00'),
            'discount' => (string) ($item['discount'] ?? '0.00'),
            'paid_amount' => (string) ($item['paid_amount'] ?? '0.00'),
            'billing_cycle' => (string) ($item['billing_cycle'] ?? ''),
            'quantity' => (int) ($item['quantity'] ?? 1),
            'paid_at' => $item['paid_at'] ?? null,
            'created_at' => $item['created_at'] ?? null,
            'updated_at' => $item['updated_at'] ?? null,
        ];

        if (array_key_exists('upgrade_kind', $item)) {
            $payload['upgrade_kind'] = (string) ($item['upgrade_kind'] ?? '');
            $payload['upgrade_kind_label'] = (string) ($item['upgrade_kind_label'] ?? '');
            $payload['upgrade_target_label'] = (string) ($item['upgrade_target_label'] ?? '');
            $payload['upgrade_mode'] = (string) ($item['upgrade_mode'] ?? '');
        }

        return $payload;
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

    protected function invoice(mixed $invoice): ?array
    {
        if (! is_array($invoice)) {
            return null;
        }

        return [
            'id' => (int) ($invoice['id'] ?? 0),
            'invoice_no' => (string) ($invoice['invoice_no'] ?? ''),
            'status' => (int) ($invoice['status'] ?? 0),
            'amount' => (string) ($invoice['amount'] ?? '0.00'),
            'paid_amount' => (string) ($invoice['paid_amount'] ?? '0.00'),
            'paid_at' => $invoice['paid_at'] ?? null,
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
            'domain' => (string) ($service['domain'] ?? ''),
            'status' => (int) ($service['status'] ?? 0),
            'expires_at' => $service['expires_at'] ?? null,
        ];
    }
}
