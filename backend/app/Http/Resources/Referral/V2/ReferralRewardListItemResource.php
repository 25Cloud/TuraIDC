<?php

declare(strict_types=1);

namespace App\Http\Resources\Referral\V2;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ReferralRewardListItemResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $item = is_array($this->resource) ? $this->resource : [];

        return [
            'id' => (int) ($item['id'] ?? 0),
            'status' => (int) ($item['status'] ?? 0),
            'order_amount' => $this->money($item['order_amount'] ?? 0),
            'reward_rate' => $this->money($item['reward_rate'] ?? 0),
            'reward_amount' => $this->money($item['reward_amount'] ?? 0),
            'available_at' => $item['available_at'] ?? null,
            'released_at' => $item['released_at'] ?? null,
            'rewarded_at' => $item['rewarded_at'] ?? null,
            'remark' => $item['remark'] ?? null,
            'referrer' => $this->user($item['referrer'] ?? null),
            'referred_user' => $this->user($item['referred_user'] ?? null),
            'order' => $this->order($item['order'] ?? null),
            'product' => $this->product($item['product'] ?? null),
        ];
    }

    private function user(mixed $user): ?array
    {
        if (! is_array($user)) {
            return null;
        }

        return [
            'id' => (int) ($user['id'] ?? 0),
            'email' => (string) ($user['email'] ?? ''),
            'nickname' => (string) ($user['nickname'] ?? ''),
            'display_name' => (string) ($user['display_name'] ?? ''),
        ];
    }

    private function order(mixed $order): ?array
    {
        if (! is_array($order)) {
            return null;
        }

        return [
            'id' => (int) ($order['id'] ?? 0),
            'order_no' => (string) ($order['order_no'] ?? ''),
            'product_display_name' => (string) ($order['product_display_name'] ?? ''),
        ];
    }

    private function product(mixed $product): ?array
    {
        if (! is_array($product)) {
            return null;
        }

        return [
            'id' => (int) ($product['id'] ?? 0),
            'name' => (string) ($product['name'] ?? ''),
            'display_name' => (string) ($product['display_name'] ?? ''),
        ];
    }

    private function money(mixed $value): string
    {
        return number_format((float) $value, 2, '.', '');
    }
}
