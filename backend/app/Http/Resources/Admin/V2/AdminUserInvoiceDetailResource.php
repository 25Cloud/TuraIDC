<?php

declare(strict_types=1);

namespace App\Http\Resources\Admin\V2;

use App\Http\Resources\Admin\V2\Concerns\StripsSensitiveResourceData;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AdminUserInvoiceDetailResource extends JsonResource
{
    use StripsSensitiveResourceData;

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $invoice = is_array($this->resource) ? $this->resource : [];

        return [
            'invoice' => [
                'id' => (int) ($invoice['id'] ?? 0),
                'invoice_no' => (string) ($invoice['invoice_no'] ?? ''),
                'type' => (string) ($invoice['type'] ?? ''),
                'type_label' => (string) ($invoice['type_label'] ?? ''),
                'status' => (int) ($invoice['status'] ?? 0),
                'status_label' => (string) ($invoice['status_label'] ?? ''),
                'raw_status' => (int) ($invoice['raw_status'] ?? $invoice['status'] ?? 0),
                'raw_status_label' => (string) ($invoice['raw_status_label'] ?? $invoice['status_label'] ?? ''),
                'amount' => (string) ($invoice['amount'] ?? '0.00'),
                'discount' => (string) ($invoice['discount'] ?? '0.00'),
                'paid_amount' => (string) ($invoice['paid_amount'] ?? '0.00'),
                'payable_amount' => (string) ($invoice['payable_amount'] ?? '0.00'),
                'billing_cycle' => (string) ($invoice['billing_cycle'] ?? ''),
                'quantity' => (int) ($invoice['quantity'] ?? 1),
                'due_date' => $invoice['due_date'] ?? null,
                'paid_at' => $invoice['paid_at'] ?? null,
                'created_at' => $invoice['created_at'] ?? null,
                'updated_at' => $invoice['updated_at'] ?? null,
                'product_spec_display' => (string) ($invoice['product_spec_display'] ?? ''),
                'product_display_name' => (string) ($invoice['product_display_name'] ?? ''),
                'combined_display_name' => (string) ($invoice['combined_display_name'] ?? ''),
                'summary' => $this->stripSensitiveKeys((array) ($invoice['summary'] ?? [])),
                'user' => $this->stripSensitiveKeys((array) ($invoice['user'] ?? [])),
                'order' => $this->stripSensitiveKeys((array) ($invoice['order'] ?? [])),
                'product' => $this->stripSensitiveKeys((array) ($invoice['product'] ?? [])),
                'service' => $this->stripSensitiveKeys((array) ($invoice['service'] ?? [])),
                'scene' => $this->stripSensitiveKeys((array) ($invoice['scene'] ?? [])),
                'payment_summary' => is_array($invoice['payment_summary'] ?? null)
                    ? $this->stripSensitiveKeys($invoice['payment_summary'])
                    : null,
                'refund_actions' => $this->stripSensitiveKeys((array) ($invoice['refund_actions'] ?? [])),
                'can_cancel' => (bool) ($invoice['can_cancel'] ?? false),
            ],
            'payments' => $this->cleanList($invoice['payments'] ?? []),
            'items' => $this->cleanList($invoice['items'] ?? []),
            'logs' => $this->cleanList($invoice['logs'] ?? []),
        ];
    }

    private function cleanList(mixed $items): array
    {
        return collect(is_array($items) ? $items : [])
            ->filter(fn (mixed $item): bool => is_array($item))
            ->map(fn (array $item): array => $this->stripSensitiveKeys($item))
            ->values()
            ->all();
    }
}
