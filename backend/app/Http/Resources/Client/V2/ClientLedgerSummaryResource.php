<?php

declare(strict_types=1);

namespace App\Http\Resources\Client\V2;

use App\Http\Resources\Finance\FinanceLedgerResource;
use App\Models\AccountTransaction;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin AccountTransaction */
class ClientLedgerSummaryResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $payload = FinanceLedgerResource::make($this->resource)->resolve($request);

        return [
            'id' => (int) ($payload['id'] ?? 0),
            'ledger_id' => (int) ($payload['ledger_id'] ?? 0),
            'account_type' => (string) ($payload['account_type'] ?? 'cash'),
            'event_type' => (string) ($payload['event_type'] ?? ''),
            'event_type_label' => (string) ($payload['event_type_label'] ?? ''),
            'event_category' => (string) ($payload['event_category'] ?? ''),
            'direction' => (string) ($payload['direction'] ?? ''),
            'amount' => (string) ($payload['amount'] ?? '0.00'),
            'change_amount' => (string) ($payload['change_amount'] ?? '0.00'),
            'balance_after' => (string) ($payload['balance_after'] ?? '0.00'),
            'occurred_at' => $payload['occurred_at'] ?? null,
            'created_at' => $payload['created_at'] ?? null,
            'remark' => (string) ($payload['remark'] ?? ''),
            'business_scene' => (string) ($payload['business_scene'] ?? ''),
            'business_scene_label' => (string) ($payload['business_scene_label'] ?? ''),
            'invoice' => $this->invoiceSummary($payload['invoice'] ?? null),
            'payment' => $this->paymentSummary($payload['payment'] ?? null),
            'display' => $this->displaySummary($payload['display'] ?? []),
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function invoiceSummary(mixed $invoice): ?array
    {
        if (! is_array($invoice)) {
            return null;
        }

        return [
            'id' => (int) ($invoice['id'] ?? 0),
            'invoice_no' => (string) ($invoice['invoice_no'] ?? ''),
            'type' => (string) ($invoice['type'] ?? ''),
            'type_label' => (string) ($invoice['type_label'] ?? ''),
            'business_scene' => (string) ($invoice['business_scene'] ?? ''),
            'business_scene_label' => (string) ($invoice['business_scene_label'] ?? ''),
            'status' => (int) ($invoice['status'] ?? 0),
            'status_label' => (string) ($invoice['status_label'] ?? ''),
            'amount' => (string) ($invoice['amount'] ?? '0.00'),
            'paid_amount' => (string) ($invoice['paid_amount'] ?? '0.00'),
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
            'status' => (int) ($payment['status'] ?? 0),
            'status_label' => (string) ($payment['status_label'] ?? ''),
            'amount' => (string) ($payment['amount'] ?? '0.00'),
            'paid_at' => $payment['paid_at'] ?? null,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function displaySummary(mixed $display): array
    {
        $display = is_array($display) ? $display : [];

        return [
            'title' => (string) ($display['title'] ?? ''),
            'subtitle' => (string) ($display['subtitle'] ?? ''),
            'badge' => (string) ($display['badge'] ?? ''),
            'badge_type' => (string) ($display['badge_type'] ?? ''),
            'status' => $display['status'] ?? null,
            'status_label' => (string) ($display['status_label'] ?? ''),
            'channel_label' => (string) ($display['channel_label'] ?? ''),
            'scene_label' => (string) ($display['scene_label'] ?? ''),
            'business_scene_label' => (string) ($display['business_scene_label'] ?? ''),
        ];
    }
}
