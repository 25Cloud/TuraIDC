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
     * @return list<array<string, mixed>>
     */
    protected function payments(Order $order): array
    {
        $payments = $order->invoice?->payments ?? collect();

        return collect($payments)
            ->filter(fn (Payment $payment): bool => $payment->isThirdPartyGateway())
            ->map(fn (Payment $payment): array => [
                'id' => (int) $payment->id,
                'payment_no' => (string) $payment->payment_no,
                'gateway' => $payment->gatewayKey(),
                'gateway_key' => $payment->gatewayKey(),
                'trade_no' => (string) ($payment->trade_no ?? ''),
                'amount' => $this->money($payment->amount),
                'status' => (int) $payment->status,
                'paid_at' => $payment->paid_at?->format('Y-m-d H:i:s'),
                'trace_id' => (string) ($payment->trace_id ?? ''),
                'created_at' => $payment->created_at?->format('Y-m-d H:i:s'),
            ])
            ->values()
            ->all();
    }

    protected function displayResolver(): ProductDisplayNameResolver
    {
        return app(ProductDisplayNameResolver::class);
    }
}
