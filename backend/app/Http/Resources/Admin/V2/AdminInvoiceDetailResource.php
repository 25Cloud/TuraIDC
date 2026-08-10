<?php

declare(strict_types=1);

namespace App\Http\Resources\Admin\V2;

use Illuminate\Http\Request;

class AdminInvoiceDetailResource extends AdminInvoiceSummaryResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $invoice = is_array($this->resource) ? $this->resource : [];

        return [
            'id' => (int) ($invoice['id'] ?? 0),
            'basic' => [
                'invoice_no' => (string) ($invoice['invoice_no'] ?? ''),
                'type' => (string) ($invoice['type'] ?? ''),
                'type_label' => (string) ($invoice['type_label'] ?? ''),
                'status' => (int) ($invoice['status'] ?? 0),
                'status_label' => (string) ($invoice['status_label'] ?? ''),
                'raw_status' => (int) ($invoice['raw_status'] ?? $invoice['status'] ?? 0),
                'raw_status_label' => (string) ($invoice['raw_status_label'] ?? $invoice['status_label'] ?? ''),
                'billing_cycle' => (string) ($invoice['billing_cycle'] ?? ''),
                'quantity' => (int) ($invoice['quantity'] ?? 1),
                'due_date' => $invoice['due_date'] ?? null,
            ],
            'display' => [
                'product_spec_snapshot' => (string) ($invoice['product_spec_snapshot'] ?? ''),
                'product_spec_display' => (string) ($invoice['product_spec_display'] ?? ''),
                'product_display_name' => (string) ($invoice['product_display_name'] ?? ''),
                'combined_display_name' => (string) ($invoice['combined_display_name'] ?? ''),
                'summary' => $this->summary($invoice['summary'] ?? null),
            ],
            'financial' => [
                'amount' => (string) ($invoice['amount'] ?? '0.00'),
                'discount' => (string) ($invoice['discount'] ?? '0.00'),
                'paid_amount' => (string) ($invoice['paid_amount'] ?? '0.00'),
                'payable_amount' => (string) ($invoice['payable_amount'] ?? '0.00'),
                'paid_at' => $invoice['paid_at'] ?? null,
            ],
            'user' => $this->user($invoice['user'] ?? null),
            'order' => $this->order($invoice['order'] ?? null),
            'product' => $this->product($invoice['product'] ?? null),
            'service' => $this->service($invoice['service'] ?? null),
            'scene' => $this->stripSensitiveKeys((array) ($invoice['scene'] ?? [])),
            'configuration' => [
                'config_snapshot' => $this->stripSensitiveKeys((array) ($invoice['config_snapshot'] ?? [])),
                'config_pricing_snapshot' => $this->stripSensitiveKeys((array) ($invoice['config_pricing_snapshot'] ?? [])),
                'coupon_snapshot' => $this->stripSensitiveKeys((array) ($invoice['coupon_snapshot'] ?? [])),
            ],
            'payment_chain' => [
                'payment_summary' => is_array($invoice['payment_summary'] ?? null)
                    ? $this->stripSensitiveKeys($invoice['payment_summary'])
                    : null,
                'payments' => $this->cleanList($invoice['payments'] ?? []),
            ],
            'items' => $this->cleanList($invoice['items'] ?? []),
            'logs' => $this->cleanList($invoice['logs'] ?? []),
            'audit' => [
                'trace_id' => (string) ($invoice['trace_id'] ?? ''),
                'refund_trace_id' => (string) ($invoice['refund_trace_id'] ?? ''),
            ],
            'actions' => [
                'can_cancel' => (bool) ($invoice['can_cancel'] ?? false),
            ],
            'timestamps' => [
                'created_at' => $invoice['created_at'] ?? null,
                'updated_at' => $invoice['updated_at'] ?? null,
            ],
        ];
    }
}
