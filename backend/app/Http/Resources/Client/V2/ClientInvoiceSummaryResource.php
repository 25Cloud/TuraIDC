<?php

declare(strict_types=1);

namespace App\Http\Resources\Client\V2;

use App\Http\Resources\Admin\V2\AdminInvoiceSummaryResource;
use Illuminate\Http\Request;

class ClientInvoiceSummaryResource extends AdminInvoiceSummaryResource
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
            'summary' => $this->summary($invoice['summary'] ?? null),
            'payment_summary' => $this->paymentSummary($invoice['payment_summary'] ?? null),
            'due_date' => $invoice['due_date'] ?? null,
            'created_at' => $invoice['created_at'] ?? null,
            'paid_at' => $invoice['paid_at'] ?? null,
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function paymentSummary(mixed $payment): ?array
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
            'amount' => (string) ($payment['amount'] ?? '0.00'),
            'status' => (int) ($payment['status'] ?? 0),
            'status_label' => (string) ($payment['status_label'] ?? ''),
            'paid_at' => $payment['paid_at'] ?? null,
        ];
    }
}
