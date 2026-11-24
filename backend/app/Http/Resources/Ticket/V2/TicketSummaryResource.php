<?php

declare(strict_types=1);

namespace App\Http\Resources\Ticket\V2;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class TicketSummaryResource extends JsonResource
{
    /**
     * @return array<string, int>
     */
    public function toArray(Request $request): array
    {
        $summary = is_array($this->resource) ? $this->resource : [];

        return [
            'open' => (int) ($summary['open'] ?? 0),
            'client_reply' => (int) ($summary['client_reply'] ?? 0),
            'staff_reply' => (int) ($summary['staff_reply'] ?? 0),
            // 管理端三个 tab 的角标：pending = open + client_reply（客户在等回复）
            'pending' => (int) ($summary['pending'] ?? 0),
            'handled' => (int) ($summary['handled'] ?? 0),
            'closed' => (int) ($summary['closed'] ?? 0),
            'closed_today' => (int) ($summary['closed_today'] ?? 0),
            'total' => (int) ($summary['total'] ?? 0),
        ];
    }
}
