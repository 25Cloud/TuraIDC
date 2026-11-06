<?php

declare(strict_types=1);

namespace App\Http\Resources\Admin\V2;

use App\Constants\OrderStatus;
use App\Constants\OrderType;
use App\Models\Order;
use App\Models\Payment;
use App\Services\ProductCatalog\ProductDisplayNameResolver;
use App\Services\ProductCatalog\ProductFullPathResolver;
use App\Support\AdminPrivacy;
use App\Support\PaymentChannelProjection;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AdminOrderSummaryResource extends JsonResource
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
            'order_no' => (string) $order->order_no,
            'user_id' => (int) $order->user_id,
            'user' => $this->user($order),
            'invoice_id' => $order->invoice ? (int) $order->invoice->id : null,
            'invoice' => $this->invoice($order),
            'product_id' => (int) ($order->product_id ?? 0),
            'product_name' => $this->productName($order),
            'product_full_path' => $this->productPath($order),
            'product_type' => (string) ($order->product_type_snapshot ?? $order->product?->product_type ?? ''),
            'service' => $this->service($order),
            'type' => (string) $order->type,
            'type_label' => OrderType::label((string) $order->type),
            'status' => (int) $order->status,
            'status_label' => OrderStatus::$labels[(int) $order->status] ?? (string) $order->status,
            'amount' => $this->money($order->amount),
            'discount' => $this->money($order->discount),
            'paid_amount' => $this->money($order->paid_amount),
            'billing_cycle' => (string) ($order->billing_cycle ?? ''),
            'quantity' => (int) ($order->quantity ?? 1),
            'paid_at' => $order->paid_at?->format('Y-m-d H:i:s'),
            'created_at' => $order->created_at?->format('Y-m-d H:i:s'),
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    protected function user(Order $order): ?array
    {
        if (! $order->user) {
            return null;
        }

        $privacy = AdminPrivacy::current();

        return [
            'id' => (int) $order->user->id,
            'email' => $privacy->email($order->user->email),
            'nickname' => (string) ($order->user->nickname ?? ''),
            'phone' => $privacy->phone($order->user->phone),
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    protected function invoice(Order $order): ?array
    {
        if (! $order->invoice) {
            return null;
        }

        return [
            'id' => (int) $order->invoice->id,
            'invoice_no' => (string) $order->invoice->invoice_no,
            'status' => (int) $order->invoice->status,
            'amount' => $this->money($order->invoice->amount),
            'paid_amount' => $this->money($order->invoice->paid_amount),
            'paid_at' => $order->invoice->paid_at?->format('Y-m-d H:i:s'),
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    protected function service(Order $order): ?array
    {
        if (! $order->service) {
            return null;
        }

        return [
            'id' => (int) $order->service->id,
            'name' => (string) $order->service->name,
            'domain' => (string) ($order->service->domain ?? ''),
            'status' => (int) $order->service->status,
            'expires_at' => $order->service->expires_at?->format('Y-m-d H:i:s'),
        ];
    }

    protected function productName(Order $order): string
    {
        return app(ProductFullPathResolver::class)->nameForOrder($order);
    }

    protected function productPath(Order $order): string
    {
        return app(ProductFullPathResolver::class)->pathForOrder($order);
    }

    protected function money(mixed $value): string
    {
        return number_format((float) ($value ?? 0), 2, '.', '');
    }

    /**
     * 支付流水：第三方网关的 Payment 行。
     *
     * 余额支付与 0 元收口都不产生 Payment 记录，只看流水会让这两类已付订单显示
     * 「没有支付方式」。此时按订单/账单状态补一条推导出的渠道（`derived = true`），
     * 字段口径与真实流水一致，前端不需要分支。
     *
     * @return list<array<string, mixed>>
     */
    protected function payments(Order $order): array
    {
        $payments = $order->invoice?->payments ?? collect();

        $rows = collect($payments)
            ->filter(fn (Payment $payment): bool => $payment->isThirdPartyGateway())
            ->map(fn (Payment $payment): array => array_merge(
                PaymentChannelProjection::fromPayment($payment),
                [
                    'trace_id' => (string) ($payment->trace_id ?? ''),
                    'created_at' => $payment->created_at?->format('Y-m-d H:i:s'),
                ],
            ))
            ->values()
            ->all();

        if ($rows !== []) {
            return $rows;
        }

        $derived = $this->derivedPayment($order);

        return $derived === null ? [] : [$derived];
    }

    /**
     * 没有第三方流水时，按订单/账单状态推导支付渠道。
     *
     * 推导规则与账单侧同源（`PaymentChannelProjection`）：0 元 → 免费开通，
     * 其余已付 → 余额支付；这里只补订单流水视图独有的 trace_id/created_at。
     *
     * @return array<string, mixed>|null
     */
    protected function derivedPayment(Order $order): ?array
    {
        $projection = PaymentChannelProjection::forOrder($order);

        if (! is_array($projection)) {
            return null;
        }

        return array_merge($projection, [
            'trace_id' => '',
            'created_at' => $order->created_at?->format('Y-m-d H:i:s'),
        ]);
    }

    protected function displayResolver(): ProductDisplayNameResolver
    {
        return app(ProductDisplayNameResolver::class);
    }
}
