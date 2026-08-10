<?php

declare(strict_types=1);

namespace App\Http\Resources\Admin\V2;

use App\Models\Role;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Role */
class AdminRoleListItemResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => (int) $this->id,
            'name' => (string) $this->name,
            'label' => (string) ($this->label ?? $this->name),
            'permissions' => $this->resolvedPermissions(),
            'stored_permissions' => array_values((array) ($this->permissions ?? [])),
            'admin_count' => (int) ($this->admin_users_count ?? 0),
            'is_builtin' => $this->isBuiltIn(),
            'is_locked' => $this->isLocked(),
            'can_edit_permissions' => ! $this->isLocked(),
            'can_delete' => ! $this->isLocked(),
            'created_at' => $this->created_at?->format('Y-m-d H:i:s'),
            'updated_at' => $this->updated_at?->format('Y-m-d H:i:s'),
        ];
    }
}
