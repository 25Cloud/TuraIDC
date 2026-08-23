<?php

declare(strict_types=1);

namespace App\Http\Resources\Admin\V2;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AdminProductDiscountGroupListItemResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return ['id' => (int) $this->id, 'name' => (string) $this->name, 'code' => (string) $this->code, 'min_discount_rate' => number_format((float) $this->min_discount_rate, 2, '.', ''), 'cost_rate' => number_format((float) $this->cost_rate, 2, '.', ''), 'status' => (int) $this->status, 'sort_order' => (int) $this->sort_order, 'remark' => $this->remark, 'created_at' => $this->created_at?->format('Y-m-d H:i:s'), 'updated_at' => $this->updated_at?->format('Y-m-d H:i:s')];
    }
}
