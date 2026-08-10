<?php

namespace App\Models;

use App\Support\AdminPermissions;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Support\Facades\Schema;
use Laravel\Sanctum\HasApiTokens;

class AdminUser extends Authenticatable
{
    use HasApiTokens;

    private static ?bool $adminUserRolesTableAvailable = null;

    protected $table = 'admin_users';

    protected $fillable = [
        'username', 'password', 'role_id', 'nickname', 'email',
        'status', 'last_login_at', 'last_login_ip',
    ];

    protected $hidden = ['password'];

    protected function casts(): array
    {
        return [
            'last_login_at' => 'datetime',
            'password' => 'hashed',
        ];
    }

    public function role(): BelongsTo
    {
        return $this->belongsTo(Role::class);
    }

    public function roles(): BelongsToMany
    {
        return $this->belongsToMany(Role::class, 'admin_user_roles');
    }

    public function getDisplayNameAttribute(): string
    {
        $nickname = trim((string) $this->nickname);
        if ($nickname !== '') {
            return $nickname;
        }

        $username = trim((string) $this->username);
        if ($username !== '') {
            return $username;
        }

        return trim((string) ($this->email ?? ''));
    }

    public function hasPermission(string $permission): bool
    {
        $permissions = $this->resolvedPermissions();
        if ($permissions === []) {
            return false;
        }

        return in_array(AdminPermissions::ALL, $permissions, true)
            || in_array($permission, $permissions, true);
    }

    public function scopeActive($query)
    {
        return $query->where('status', 1);
    }

    public function scopeWithResolvedPermissionRelations(Builder $query): Builder
    {
        $relations = self::hasAdminPermissionTables()
            ? ['roles', 'role']
            : ['role'];

        return $query->with($relations);
    }

    public function resolvedPermissions(): array
    {
        $primaryRole = $this->resolvePrimaryRoleForRead();
        if ($primaryRole instanceof Role) {
            return $primaryRole->resolvedPermissions();
        }

        if (self::hasAdminPermissionTables()) {
            $roles = $this->resolveRolesForRead();
            if ($roles !== []) {
                $permissions = collect($roles)
                    ->flatMap(fn (Role $role) => $role->resolvedPermissions())
                    ->filter(fn ($permission) => is_string($permission) && trim($permission) !== '')
                    ->unique()
                    ->values()
                    ->all();

                if ($permissions !== []) {
                    return $permissions;
                }
            }
        }

        return $this->role?->resolvedPermissions() ?? [];
    }

    public function resolvedRoleLabel(): string
    {
        $primaryRole = $this->resolvePrimaryRoleForRead();
        if ($primaryRole instanceof Role) {
            return trim((string) ($primaryRole->label ?? $primaryRole->name ?? ''));
        }

        $roles = $this->resolveRolesForRead();
        if ($roles !== []) {
            $primary = $roles[0];

            return trim((string) ($primary->label ?? $primary->name ?? ''));
        }

        return trim((string) ($this->role?->label ?? $this->role?->name ?? ''));
    }

    private function resolvePrimaryRoleForRead(): ?Role
    {
        $roleId = (int) ($this->role_id ?? 0);
        if ($roleId <= 0) {
            return null;
        }

        if ($this->relationLoaded('role')) {
            $role = $this->getRelation('role');

            return $role instanceof Role && (int) $role->id === $roleId
                ? $role
                : null;
        }

        $query = $this->role();

        return $query->first();
    }

    /**
     * @return Role[]
     */
    private function resolveRolesForRead(): array
    {
        if (! self::adminUserRolesTableAvailable()) {
            return [];
        }

        if ($this->relationLoaded('roles')) {
            /** @var Collection<int, Role> $roles */
            $roles = $this->getRelation('roles');

            return $roles->values()->all();
        }

        return $this->roles()
            ->orderBy('roles.id')
            ->get()
            ->all();
    }

    private static function hasAdminPermissionTables(): bool
    {
        return self::adminUserRolesTableAvailable();
    }

    private static function adminUserRolesTableAvailable(): bool
    {
        if (self::$adminUserRolesTableAvailable === null) {
            self::$adminUserRolesTableAvailable = Schema::hasTable('admin_user_roles');
        }

        return self::$adminUserRolesTableAvailable;
    }
}
