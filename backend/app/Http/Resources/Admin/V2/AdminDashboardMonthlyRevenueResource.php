<?php

declare(strict_types=1);

namespace App\Http\Resources\Admin\V2;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AdminDashboardMonthlyRevenueResource extends JsonResource
{
    /**
     * @return array{revenue_by_product: list<array<string, mixed>>, daily_revenue: list<array<string, mixed>>, month_label: string}
     */
    public function toArray(Request $request): array
    {
        $payload = is_array($this->resource) ? $this->resource : [];

        return [
            'revenue_by_product' => $this->projectRevenueByProduct((array) ($payload['revenue_by_product'] ?? [])),
            'daily_revenue' => $this->projectDailyRevenue((array) ($payload['daily_revenue'] ?? [])),
            'month_label' => (string) ($payload['month_label'] ?? ''),
        ];
    }

    /**
     * @param  array<int, mixed>  $items
     * @return list<array<string, mixed>>
     */
    private function projectRevenueByProduct(array $items): array
    {
        return collect($items)
            ->filter(static fn (mixed $item): bool => is_array($item))
            ->take(9)
            ->map(static fn (array $item): array => [
                'label' => (string) ($item['label'] ?? ''),
                'amount' => (float) ($item['amount'] ?? 0),
                'count' => (int) ($item['count'] ?? 0),
            ])
            ->values()
            ->all();
    }

    /**
     * @param  array<int, mixed>  $items
     * @return list<array<string, mixed>>
     */
    private function projectDailyRevenue(array $items): array
    {
        return collect($items)
            ->filter(static fn (mixed $item): bool => is_array($item))
            ->take(31)
            ->map(static fn (array $item): array => [
                'date' => (string) ($item['date'] ?? ''),
                'day' => (int) ($item['day'] ?? 0),
                'amount' => (float) ($item['amount'] ?? 0),
                'count' => (int) ($item['count'] ?? 0),
            ])
            ->values()
            ->all();
    }
}
