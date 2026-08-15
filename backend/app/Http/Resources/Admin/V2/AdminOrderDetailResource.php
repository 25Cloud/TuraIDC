<?php

declare(strict_types=1);

namespace App\Http\Resources\Admin\V2;

use App\Constants\OrderStatus;
use App\Constants\OrderType;
use App\Models\Order;
use Illuminate\Http\Request;

class AdminOrderDetailResource extends AdminOrderSummaryResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var Order $order */
        $order = $this->resource;

        return [
            'id' => (int) $order->id,
            'basic' => [
                'order_no' => (string) $order->order_no,
                'type' => (string) $order->type,
                'type_label' => OrderType::label((string) $order->type),
                'status' => (int) $order->status,
                'status_label' => OrderStatus::$labels[(int) $order->status] ?? (string) $order->status,
                'billing_cycle' => (string) ($order->billing_cycle ?? ''),
                'quantity' => (int) ($order->quantity ?? 1),
                'remark' => (string) ($order->remark ?? ''),
            ],
            'financial' => [
                'amount' => $this->money($order->amount),
                'discount' => $this->money($order->discount),
                'paid_amount' => $this->money($order->paid_amount),
                'paid_at' => $order->paid_at?->format('Y-m-d H:i:s'),
            ],
            'user' => $this->user($order),
            'invoice' => $this->invoice($order),
            'product' => [
                'id' => (int) ($order->product_id ?? 0),
                'name' => $this->productName($order),
                'full_path' => $this->productPath($order),
                'type' => (string) ($order->product_type_snapshot ?? $order->product?->product_type ?? ''),
            ],
            'service' => $this->service($order),
            'coupon' => $this->coupon($order),
            'configuration' => [
                'config_snapshot' => $this->stripSensitiveKeys((array) ($order->config_snapshot ?? [])),
                'config_pricing_snapshot' => $this->stripSensitiveKeys((array) ($order->config_pricing_snapshot ?? [])),
                'service_snapshot' => $order->type === OrderType::NEW
                    ? $this->stripSensitiveKeys((array) ($order->service_snapshot ?? []))
                    : null,
            ],
            'payment_chain' => [
                'payments' => $this->payments($order),
            ],
            'audit' => [
                'trace_id' => (string) ($order->trace_id ?? ''),
            ],
            'timestamps' => [
                'created_at' => $order->created_at?->format('Y-m-d H:i:s'),
                'updated_at' => $order->updated_at?->format('Y-m-d H:i:s'),
            ],
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function coupon(Order $order): ?array
    {
        $snapshot = $this->stripSensitiveKeys((array) ($order->coupon_snapshot ?? []));
        $coupon = $order->coupon;
        $code = trim((string) ($order->coupon_code ?? $coupon?->code ?? ''));

        if (! $coupon && $code === '' && $snapshot === []) {
            return null;
        }

        return [
            'id' => $coupon ? (int) $coupon->id : null,
            'code' => $code,
            'name' => (string) ($coupon?->name ?? ($snapshot['name'] ?? '')),
            'type' => (string) ($coupon?->type ?? ($snapshot['type'] ?? '')),
            'value' => (string) ($coupon?->value ?? ($snapshot['value'] ?? '')),
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function stripSensitiveKeys(array $payload): array
    {
        $clean = [];

        foreach ($payload as $key => $value) {
            $normalized = strtolower((string) $key);
            if ($this->isSensitiveKey($normalized)) {
                continue;
            }

            if (is_array($value)) {
                $clean[$key] = $this->stripSensitiveKeys($value);

                continue;
            }

            $clean[$key] = $value;
        }

        return $clean;
    }

    private function isSensitiveKey(string $key): bool
    {
        foreach (['password', 'secret', 'api_key', 'raw_response', 'third_party_response', 'token'] as $needle) {
            if (str_contains($key, $needle)) {
                return true;
            }
        }

        return false;
    }
}
