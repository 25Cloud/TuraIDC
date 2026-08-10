<?php

declare(strict_types=1);

namespace App\Http\Resources\Admin\V2;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AdminSupplierSummaryResource extends JsonResource
{
    /**
     * @return array{total: int, active: int, inactive: int}
     */
    public function toArray(Request $request): array
    {
        $item = is_array($this->resource) ? $this->resource : [];

        return [
            'total' => (int) ($item['total'] ?? 0),
            'active' => (int) ($item['active'] ?? 0),
            'inactive' => (int) ($item['inactive'] ?? 0),
        ];
    }
}
