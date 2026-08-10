<?php

declare(strict_types=1);

namespace App\Http\Resources\Admin\V2;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AdminDashboardStatsResource extends JsonResource
{
    /**
     * @return array<string, array<string, int|float>>
     */
    public function toArray(Request $request): array
    {
        $payload = is_array($this->resource) ? $this->resource : [];

        return [
            'counts' => [
                'total_users' => (int) data_get($payload, 'counts.total_users', 0),
                'total_invoices' => (int) data_get($payload, 'counts.total_invoices', 0),
                'active_services' => (int) data_get($payload, 'counts.active_services', 0),
                'open_tickets' => (int) data_get($payload, 'counts.open_tickets', 0),
            ],
            'today' => [
                'new_users' => (int) data_get($payload, 'today.new_users', 0),
                'new_invoices' => (int) data_get($payload, 'today.new_invoices', 0),
                'income' => (float) data_get($payload, 'today.income', 0),
            ],
            'month' => [
                'income' => (float) data_get($payload, 'month.income', 0),
                'new_users' => (int) data_get($payload, 'month.new_users', 0),
                'new_invoices' => (int) data_get($payload, 'month.new_invoices', 0),
            ],
        ];
    }
}
