<?php

declare(strict_types=1);

namespace App\Http\Resources\Admin\V2;

use App\Models\MemberLevel;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin MemberLevel */
class AdminMemberLevelDetailResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => (int) $this->id,
            'name' => (string) $this->name,
            'code' => (string) $this->code,
            'sales_amount_min' => number_format((float) $this->sales_amount_min, 2, '.', ''),
            'sales_amount_max' => $this->sales_amount_max !== null
                ? number_format((float) $this->sales_amount_max, 2, '.', '')
                : null,
            'reward_rate' => number_format((float) $this->reward_rate, 2, '.', ''),
            'status' => (int) $this->status,
            'sort_order' => (int) $this->sort_order,
            'remark' => $this->remark !== null ? (string) $this->remark : null,
            'created_at' => $this->created_at?->format('Y-m-d H:i:s'),
            'updated_at' => $this->updated_at?->format('Y-m-d H:i:s'),
        ];
    }
}
