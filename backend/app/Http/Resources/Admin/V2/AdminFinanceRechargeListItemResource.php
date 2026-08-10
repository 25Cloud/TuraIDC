<?php

declare(strict_types=1);

namespace App\Http\Resources\Admin\V2;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AdminFinanceRechargeListItemResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $item = is_array($this->resource) ? $this->resource : [];

        return [
            'id' => (int) ($item['id'] ?? 0),
            'payment_no' => (string) ($item['payment_no'] ?? ''),
            'gateway' => (string) ($item['gateway'] ?? ''),
            'gateway_key' => (string) ($item['gateway_key'] ?? ''),
            'gateway_label' => (string) ($item['gateway_label'] ?? ''),
            'trade_no' => (string) ($item['trade_no'] ?? ''),
            'user' => $this->user($item['user'] ?? null),
            'invoice_id' => $item['invoice_id'] ?? null,
            'invoice_no' => (string) ($item['invoice_no'] ?? ''),
            'invoice' => $this->invoice($item['invoice'] ?? null),
            'order' => $this->order($item['order'] ?? null),
            'amount' => (string) ($item['amount'] ?? '0.00'),
            'paid_amount' => (string) ($item['paid_amount'] ?? '0.00'),
            'status' => (int) ($item['status'] ?? 0),
            'status_label' => (string) ($item['status_label'] ?? ''),
            'payment' => $this->payment($item['payment'] ?? null),
            'paid_at' => $item['paid_at'] ?? null,
            'created_at' => $item['created_at'] ?? null,
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

    protected function invoice(mixed $invoice): ?array
    {
        if (! is_array($invoice)) {
            return null;
        }

        return [
            'id' => (int) ($invoice['id'] ?? 0),
            'invoice_no' => (string) ($invoice['invoice_no'] ?? ''),
            'type' => (string) ($invoice['type'] ?? ''),
            'status' => (int) ($invoice['status'] ?? 0),
            'status_label' => (string) ($invoice['status_label'] ?? ''),
            'amount' => (string) ($invoice['amount'] ?? '0.00'),
            'paid_amount' => (string) ($invoice['paid_amount'] ?? '0.00'),
            'paid_at' => $invoice['paid_at'] ?? null,
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
            'type' => (string) ($order['type'] ?? ''),
            'status' => (int) ($order['status'] ?? 0),
        ];
    }

    protected function payment(mixed $payment): ?array
    {
        if (! is_array($payment)) {
            return null;
        }

        return [
            'id' => (int) ($payment['id'] ?? 0),
            'payment_no' => (string) ($payment['payment_no'] ?? ''),
            'gateway' => (string) ($payment['gateway'] ?? ''),
            'gateway_key' => (string) ($payment['gateway_key'] ?? ''),
            'gateway_label' => (string) ($payment['gateway_label'] ?? ''),
            'trade_no' => (string) ($payment['trade_no'] ?? ''),
            'amount' => (string) ($payment['amount'] ?? '0.00'),
            'status' => (int) ($payment['status'] ?? 0),
            'paid_at' => $payment['paid_at'] ?? null,
        ];
    }
}
