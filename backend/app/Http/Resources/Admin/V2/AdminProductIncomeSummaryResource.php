<?php

declare(strict_types=1);

namespace App\Http\Resources\Admin\V2;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AdminProductIncomeSummaryResource extends JsonResource
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
     * @return array<string, mixed>
     */
    protected function summary(mixed $summary): array
    {
        $summary = is_array($summary) ? $summary : [];

        return [
            'new_income' => $this->money($summary['new_income'] ?? '0.00'),
            'new_quantity' => (int) ($summary['new_quantity'] ?? 0),
            'renew_income' => $this->money($summary['renew_income'] ?? '0.00'),
            'renew_quantity' => (int) ($summary['renew_quantity'] ?? 0),
            'total_amount' => $this->money($summary['total_amount'] ?? '0.00'),
            'total_quantity' => (int) ($summary['total_quantity'] ?? 0),
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
                'product_id' => (int) ($item['product_id'] ?? 0),
                'product_name' => (string) ($item['product_name'] ?? ''),
                'product_type' => (string) ($item['product_type'] ?? ''),
                'new_income' => $this->money($item['new_income'] ?? '0.00'),
                'new_quantity' => (int) ($item['new_quantity'] ?? 0),
                'renew_income' => $this->money($item['renew_income'] ?? '0.00'),
                'renew_quantity' => (int) ($item['renew_quantity'] ?? 0),
                'total_amount' => $this->money($item['total_amount'] ?? '0.00'),
                'total_quantity' => (int) ($item['total_quantity'] ?? 0),
            ])
            ->values()
            ->all();
    }

    private function money(mixed $value): string
    {
        return number_format((float) $value, 2, '.', '');
    }
}
