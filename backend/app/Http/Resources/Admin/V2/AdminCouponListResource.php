<?php

declare(strict_types=1);

namespace App\Http\Resources\Admin\V2;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AdminCouponListResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $item = is_array($this->resource) ? $this->resource : [];

        return [
            'id' => (int) ($item['id'] ?? 0),
            'coupon_campaign_id' => (int) ($item['coupon_campaign_id'] ?? 0),
            'coupon_campaign_name' => (string) ($item['coupon_campaign_name'] ?? ''),
            'name' => (string) ($item['name'] ?? ''),
            'description' => (string) ($item['description'] ?? ''),
            'distribution_type' => (string) ($item['distribution_type'] ?? 'public'),
            'distribution_type_label' => (string) ($item['distribution_type_label'] ?? ''),
            'discount_scope' => (string) ($item['discount_scope'] ?? 'first_month'),
            'discount_scope_label' => (string) ($item['discount_scope_label'] ?? ''),
            'discount_type' => (string) ($item['discount_type'] ?? 'fixed'),
            'discount_type_label' => (string) ($item['discount_type_label'] ?? ''),
            'discount_value' => (string) ($item['discount_value'] ?? '0.00'),
            'discount_value_raw' => (float) ($item['discount_value_raw'] ?? 0),
            'discount_label' => (string) ($item['discount_label'] ?? ''),
            'min_amount' => (string) ($item['min_amount'] ?? '0.00'),
            'min_amount_raw' => (float) ($item['min_amount_raw'] ?? 0),
            'max_discount_amount' => $item['max_discount_amount'] ?? null,
            'max_discount_amount_raw' => $item['max_discount_amount_raw'] ?? null,
            'billing_cycles' => array_values((array) ($item['billing_cycles'] ?? [])),
            'billing_cycle_text' => (string) ($item['billing_cycle_text'] ?? ''),
            'product_ids' => array_values((array) ($item['product_ids'] ?? [])),
            'product_names' => array_values((array) ($item['product_names'] ?? [])),
            'product_scope_text' => (string) ($item['product_scope_text'] ?? ''),
            'first_order_only' => (bool) ($item['first_order_only'] ?? false),
            'total_usage_limit' => $item['total_usage_limit'] ?? null,
            'per_user_limit' => $item['per_user_limit'] ?? null,
            'used_count' => (int) ($item['used_count'] ?? 0),
            'recipient_count' => (int) ($item['recipient_count'] ?? 0),
            'user_ids' => array_values((array) ($item['user_ids'] ?? [])),
            'remaining_stock' => $item['remaining_stock'] ?? null,
            'status' => (int) ($item['status'] ?? 0),
            'status_label' => (string) ($item['status_label'] ?? ''),
            'display_status' => (string) ($item['display_status'] ?? ''),
            'display_status_label' => (string) ($item['display_status_label'] ?? ''),
            'display_status_reason' => (string) ($item['display_status_reason'] ?? ''),
            'sort_order' => (int) ($item['sort_order'] ?? 0),
            'starts_at' => $item['starts_at'] ?? null,
            'expires_at' => $item['expires_at'] ?? null,
            'validity_text' => (string) ($item['validity_text'] ?? ''),
            'remark' => (string) ($item['remark'] ?? ''),
            'operator' => (string) ($item['operator'] ?? ''),
            'trace_id' => (string) ($item['trace_id'] ?? ''),
            'created_at' => $item['created_at'] ?? null,
            'updated_at' => $item['updated_at'] ?? null,
            'can_update' => (bool) ($item['can_update'] ?? true),
            'can_delete' => (bool) ($item['can_delete'] ?? true),
            'lock_reason' => (string) ($item['lock_reason'] ?? ''),
            'locked_fields' => array_values((array) ($item['locked_fields'] ?? [])),
            'delete_reason' => (string) ($item['delete_reason'] ?? ''),
        ];
    }
}
