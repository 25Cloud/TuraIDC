<?php

declare(strict_types=1);

namespace App\Http\Resources\Client\V2;

use App\Http\Resources\Admin\V2\AdminInvoiceSummaryResource;
use Illuminate\Http\Request;

class ClientInvoiceDetailResource extends AdminInvoiceSummaryResource
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
            'order' => $this->order($invoice['order'] ?? null),
            'product' => $this->productWithOptions($invoice['product'] ?? null),
            'service' => $this->service($invoice['service'] ?? null),
            'scene' => $this->stripSensitiveKeys((array) ($invoice['scene'] ?? [])),
            'configuration' => [
                'config_snapshot' => $this->stripSensitiveKeys((array) ($invoice['config_snapshot'] ?? [])),
                'config_pricing_snapshot' => $this->stripSensitiveKeys((array) ($invoice['config_pricing_snapshot'] ?? [])),
                'coupon' => is_array($invoice['coupon'] ?? null) ? $this->stripSensitiveKeys($invoice['coupon']) : null,
            ],
            'payment_chain' => [
                'payment_summary' => is_array($invoice['payment_summary'] ?? null)
                    ? $this->stripSensitiveKeys($invoice['payment_summary'])
                    : null,
                'payments' => $this->cleanList($invoice['payments'] ?? []),
            ],
            'payment_options' => [
                'pay_methods' => $this->cleanList($invoice['pay_methods'] ?? []),
                'payment_security' => is_array($invoice['payment_security'] ?? null)
                    ? $invoice['payment_security']
                    : null,
                'can_cancel' => (bool) ($invoice['can_cancel'] ?? false),
                'service_id' => (int) ($invoice['service_id'] ?? 0),
            ],
            'items' => $this->cleanList($invoice['items'] ?? []),
            'logs' => $this->cleanList($invoice['logs'] ?? []),
            'timestamps' => [
                'created_at' => $invoice['created_at'] ?? null,
                'updated_at' => $invoice['updated_at'] ?? null,
            ],
        ];
    }

    private function productWithOptions(mixed $product): ?array
    {
        if (! is_array($product)) {
            return null;
        }

        return [
            ...($this->product($product) ?? []),
            'config_options' => $this->cleanList($product['config_options'] ?? []),
        ];
    }
}
