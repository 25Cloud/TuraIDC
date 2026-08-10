<?php

declare(strict_types=1);

namespace App\Http\Resources\Admin\V2;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AdminDashboardRecentInvoicesResource extends JsonResource
{
    /**
     * @return array{recent_invoices: list<array<string, mixed>>}
     */
    public function toArray(Request $request): array
    {
        $payload = is_array($this->resource) ? $this->resource : [];

        return [
            'recent_invoices' => collect((array) ($payload['recent_invoices'] ?? []))
                ->filter(static fn (mixed $item): bool => is_array($item))
                ->take(10)
                ->map(static fn (array $item): array => [
                    'id' => (int) ($item['id'] ?? 0),
                    'invoice_no' => (string) ($item['invoice_no'] ?? ''),
                    'amount' => number_format((float) ($item['amount'] ?? 0), 2, '.', ''),
                    'status' => (int) ($item['status'] ?? 0),
                    'status_label' => (string) ($item['status_label'] ?? ''),
                    'type' => (string) ($item['type'] ?? ''),
                    'created_at' => (string) ($item['created_at'] ?? ''),
                    'user' => [
                        'nickname' => (string) data_get($item, 'user.nickname', ''),
                        'email' => (string) data_get($item, 'user.email', ''),
                    ],
                ])
                ->values()
                ->all(),
        ];
    }
}
