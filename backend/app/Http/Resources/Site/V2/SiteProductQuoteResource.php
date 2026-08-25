<?php

declare(strict_types=1);

namespace App\Http\Resources\Site\V2;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class SiteProductQuoteResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $payload = is_array($this->resource) ? $this->resource : [];

        return [
            'product_id' => (int) ($payload['product_id'] ?? 0),
            'billing_cycle' => (string) ($payload['billing_cycle'] ?? ''),
            'base_amount' => $this->money($payload['base_amount'] ?? 0),
            'config_amount' => $this->money($payload['config_amount'] ?? 0),
            'setup_fee' => $this->money($payload['setup_fee'] ?? 0),
            'subtotal_amount' => $this->money($payload['subtotal_amount'] ?? $payload['total_amount'] ?? 0),
            'discount_amount' => $this->money($payload['discount_amount'] ?? 0),
            'total_amount' => $this->money($payload['total_amount'] ?? 0),
            'original_total_amount' => $this->money($payload['original_total_amount'] ?? 0),
            'agent_discount_rate' => $this->money($payload['agent_discount_rate'] ?? 100),
            'agent_discount_amount' => $this->money($payload['agent_discount_amount'] ?? 0),
            'agent_amount' => $this->money($payload['agent_amount'] ?? 0),
            'agent_group_id' => $payload['agent_group_id'] ?? null,
            'agent_group_name' => (string) ($payload['agent_group_name'] ?? ''),
            'product_discount_group_id' => $payload['product_discount_group_id'] ?? null,
            'cost_amount' => $this->money($payload['cost_amount'] ?? 0),
            'quantity' => (int) ($payload['quantity'] ?? 1),
            'coupon' => $this->coupon($payload['coupon'] ?? null),
            'user_coupon_id' => (int) ($payload['user_coupon_id'] ?? 0),
            'available_coupons' => $this->couponList((array) ($payload['available_coupons'] ?? [])),
            'quote_token' => (string) ($payload['quote_token'] ?? ''),
            'quote_expires_at' => $payload['quote_expires_at'] ?? null,
            'items' => $this->items((array) ($payload['items'] ?? [])),
        ];
    }

    private function money(mixed $value): string
    {
        return is_numeric($value) ? number_format((float) $value, 2, '.', '') : '0.00';
    }

    /**
     * @return array<string, mixed>|null
     */
    private function coupon(mixed $coupon): ?array
    {
        if (! is_array($coupon)) {
            return null;
        }

        return [
            'user_coupon_id' => (int) ($coupon['user_coupon_id'] ?? 0),
            'coupon_id' => (int) ($coupon['coupon_id'] ?? $coupon['id'] ?? 0),
            'code' => (string) ($coupon['code'] ?? ''),
            'name' => (string) ($coupon['name'] ?? ''),
            'discount_label' => (string) ($coupon['discount_label'] ?? ''),
            'discount_amount' => $this->money($coupon['discount_amount'] ?? 0),
        ];
    }

    /**
     * @param  array<int, mixed>  $coupons
     * @return list<array<string, mixed>>
     */
    private function couponList(array $coupons): array
    {
        return collect($coupons)
            ->map(fn (mixed $coupon): ?array => $this->coupon($coupon))
            ->filter()
            ->values()
            ->all();
    }

    /**
     * @param  array<int, array<string, mixed>>  $items
     * @return list<array<string, mixed>>
     */
    private function items(array $items): array
    {
        return collect($items)
            ->map(fn (array $item): array => [
                'field' => (string) ($item['field'] ?? ''),
                'label' => (string) ($item['label'] ?? ''),
                'amount' => $this->money($item['amount'] ?? 0),
            ])
            ->values()
            ->all();
    }
}
