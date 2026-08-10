<?php

declare(strict_types=1);

namespace App\Http\Resources\Admin\V2;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AdminCouponCampaignListResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $item = is_array($this->resource) ? $this->resource : [];

        return [
            'id' => (int) ($item['id'] ?? 0),
            'name' => (string) ($item['name'] ?? ''),
            'description' => (string) ($item['description'] ?? ''),
            'weekdays' => array_values((array) ($item['weekdays'] ?? [])),
            'weekdays_text' => (string) ($item['weekdays_text'] ?? ''),
            'trigger_time' => (string) ($item['trigger_time'] ?? ''),
            'trigger_time_text' => (string) ($item['trigger_time_text'] ?? ''),
            'schedule_text' => (string) ($item['schedule_text'] ?? ''),
            'issue_quantity' => (int) ($item['issue_quantity'] ?? 0),
            'valid_duration_hours' => $item['valid_duration_hours'] ?? null,
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
            'product_scope_text' => (string) ($item['product_scope_text'] ?? ''),
            'first_order_only' => (bool) ($item['first_order_only'] ?? false),
            'per_user_limit' => $item['per_user_limit'] ?? null,
            'status' => (int) ($item['status'] ?? 0),
            'status_label' => (string) ($item['status_label'] ?? ''),
            'display_status' => (string) ($item['display_status'] ?? ''),
            'display_status_label' => (string) ($item['display_status_label'] ?? ''),
            'sort_order' => (int) ($item['sort_order'] ?? 0),
            'generated_coupon_count' => (int) ($item['generated_coupon_count'] ?? 0),
            'next_run_at' => $item['next_run_at'] ?? null,
            'last_dispatched_at' => $item['last_dispatched_at'] ?? null,
            'last_coupon_id' => (int) ($item['last_coupon_id'] ?? 0),
            'last_coupon_name' => (string) ($item['last_coupon_name'] ?? ''),
            'last_coupon_code' => (string) ($item['last_coupon_code'] ?? ''),
            'remark' => (string) ($item['remark'] ?? ''),
            'operator' => (string) ($item['operator'] ?? ''),
            'trace_id' => (string) ($item['trace_id'] ?? ''),
            'created_at' => $item['created_at'] ?? null,
            'updated_at' => $item['updated_at'] ?? null,
            'can_update' => (bool) ($item['can_update'] ?? true),
            'can_delete' => (bool) ($item['can_delete'] ?? true),
            'lock_reason' => (string) ($item['lock_reason'] ?? ''),
        ];
    }
}
