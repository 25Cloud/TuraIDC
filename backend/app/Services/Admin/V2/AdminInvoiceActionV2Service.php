<?php

declare(strict_types=1);

namespace App\Services\Admin\V2;

use App\Constants\InvoiceStatus;
use App\Models\Invoice;
use App\Services\Finance\CheckoutService;
use Illuminate\Http\Request;

class AdminInvoiceActionV2Service
{
    public function __construct(
        private readonly CheckoutService $checkout,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function cancel(Invoice $invoice, Request $request): array
    {
        if ((int) $invoice->status !== InvoiceStatus::CANCELLED) {
            $invoice = $this->checkout->cancel($invoice, [
                'actor_type' => 'admin',
                'actor_user_id' => (int) ($request->user()?->id ?? 0),
                'actor_name' => (string) ($request->user()?->username ?? $request->user()?->name ?? $request->user()?->email ?? 'admin'),
                'trace_id' => (string) $request->header('X-Request-Id', ''),
                'ip_address' => (string) $request->ip(),
                'reason' => 'admin_manual_cancel',
            ]);
        } else {
            $invoice = $invoice->fresh() ?? $invoice;
        }

        return [
            'id' => (int) $invoice->id,
            'status' => 'completed',
            'message' => '账单已取消',
            'detail' => [
                'type' => 'cancellation',
                'invoice' => [
                    'id' => (int) $invoice->id,
                    'invoice_no' => (string) $invoice->invoice_no,
                    'status' => (int) $invoice->status,
                ],
            ],
        ];
    }
}
