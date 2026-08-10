<?php

declare(strict_types=1);

namespace App\Http\Resources\Admin\V2;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AdminNewCustomerDailySummaryResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $summary = is_array($this->resource) ? $this->resource : [];

        return [
            'month' => $summary['month'] ?? null,
            'start_date' => (string) ($summary['start_date'] ?? ''),
            'end_date' => (string) ($summary['end_date'] ?? ''),
            'summary' => $this->summary($summary['summary'] ?? null),
            'list' => $this->items($summary['list'] ?? null),
        ];
    }

    /**
     * @return array<string, int>
     */
    protected function summary(mixed $summary): array
    {
        $summary = is_array($summary) ? $summary : [];

        return [
            'new_customers' => (int) ($summary['new_customers'] ?? 0),
            'new_orders' => (int) ($summary['new_orders'] ?? 0),
            'completed_orders' => (int) ($summary['completed_orders'] ?? 0),
            'new_tickets' => (int) ($summary['new_tickets'] ?? 0),
            'ticket_replies' => (int) ($summary['ticket_replies'] ?? 0),
            'cancel_requests' => (int) ($summary['cancel_requests'] ?? 0),
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    protected function items(mixed $items): array
    {
        return collect(is_array($items) ? $items : [])
            ->filter(fn (mixed $item): bool => is_array($item))
            ->map(fn (array $item): array => [
                'date' => (string) ($item['date'] ?? ''),
                'new_customers' => (int) ($item['new_customers'] ?? 0),
                'new_orders' => (int) ($item['new_orders'] ?? 0),
                'completed_orders' => (int) ($item['completed_orders'] ?? 0),
                'new_tickets' => (int) ($item['new_tickets'] ?? 0),
                'ticket_replies' => (int) ($item['ticket_replies'] ?? 0),
                'cancel_requests' => (int) ($item['cancel_requests'] ?? 0),
            ])
            ->values()
            ->all();
    }
}
