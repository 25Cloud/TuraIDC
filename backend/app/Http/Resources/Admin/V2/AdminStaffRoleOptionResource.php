<?php

declare(strict_types=1);

namespace App\Http\Resources\Admin\V2;

use App\Models\Role;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Role */
class AdminStaffRoleOptionResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => (int) $this->id,
            'name' => (string) $this->name,
            'label' => (string) ($this->label ?? $this->name),
        ];
    }
}
