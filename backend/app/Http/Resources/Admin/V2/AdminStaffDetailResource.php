<?php

declare(strict_types=1);

namespace App\Http\Resources\Admin\V2;

use App\Models\AdminUser;
use App\Support\AdminPrivacy;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin AdminUser */
class AdminStaffDetailResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $privacy = AdminPrivacy::fromRequest($request);
        $role = $this->resource->relationLoaded('role') ? $this->resource->getRelation('role') : null;

        return [
            'id' => (int) $this->id,
            'username' => (string) $this->username,
            'nickname' => (string) ($this->nickname ?? ''),
            'email' => $privacy->email($this->email),
            'status' => (int) ($this->status ?? 0),
            'role_id' => $this->role_id !== null ? (int) $this->role_id : null,
            'role' => $role ? [
                'id' => (int) $role->id,
                'name' => (string) ($role->name ?? ''),
                'label' => (string) ($role->label ?? $role->name ?? ''),
            ] : null,
            'role_label' => $this->resolvedRoleLabel(),
            'permissions' => $this->resolvedPermissions(),
            'last_login_at' => $this->last_login_at?->format('Y-m-d H:i:s'),
            'last_login_ip' => $privacy->ip($this->last_login_ip),
            'created_at' => $this->created_at?->format('Y-m-d H:i:s'),
            'updated_at' => $this->updated_at?->format('Y-m-d H:i:s'),
        ];
    }
}
