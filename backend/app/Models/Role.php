<?php

namespace App\Models;

use App\Support\AdminPermissions;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Role extends Model
{
    protected $fillable = ['name', 'label', 'permissions'];

    protected function casts(): array
    {
        return [
            'permissions' => 'array',
        ];
    }

    public function adminUsers(): HasMany
    {
        return $this->hasMany(AdminUser::class);
    }

    public function resolvedPermissions(): array
    {
        return AdminPermissions::resolveRolePermissions(
            roleName: (string) $this->name,
            storedPermissions: (array) ($this->permissions ?? [])
        );
    }

    public function isBuiltIn(): bool
    {
        return AdminPermissions::isBuiltInRoleName((string) $this->name);
    }

    public function isLocked(): bool
    {
        return $this->isBuiltIn();
    }
}
