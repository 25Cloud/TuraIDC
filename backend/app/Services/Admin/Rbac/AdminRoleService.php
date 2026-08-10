<?php

declare(strict_types=1);

namespace App\Services\Admin\Rbac;

use App\Exceptions\BusinessException;
use App\Models\Role;
use App\Support\AdminPermissions;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

class AdminRoleService
{
    public function __construct(
        private readonly PermissionCatalogService $permissionCatalogService,
        private readonly BuiltinAdminRoleService $builtinAdminRoleService,
    ) {}

    /**
     * @param  array<string, mixed>  $filters
     * @return Collection<int, Role>
     */
    public function list(array $filters = []): Collection
    {
        $this->builtinAdminRoleService->sync();

        $keyword = trim((string) ($filters['keyword'] ?? ''));

        return Role::query()
            ->withCount('adminUsers')
            ->when($keyword !== '', function ($query) use ($keyword): void {
                $query->where(function ($inner) use ($keyword): void {
                    $inner
                        ->where('name', 'like', "%{$keyword}%")
                        ->orWhere('label', 'like', "%{$keyword}%");
                });
            })
            ->orderBy('id')
            ->get();
    }

    public function detail(Role $role): Role
    {
        $this->builtinAdminRoleService->sync();

        return $role->loadCount('adminUsers');
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function create(array $data): Role
    {
        return DB::transaction(function () use ($data): Role {
            $name = $this->normalizeName($data['name'] ?? '');
            if (AdminPermissions::isBuiltInRoleName($name)) {
                throw new BusinessException('系统默认角色编码不可用于自定义角色');
            }

            $role = Role::query()->create([
                'name' => $name,
                'label' => $this->normalizeLabel($data['label'] ?? ''),
                'permissions' => $this->normalizePermissions((array) ($data['permissions'] ?? [])),
            ]);

            return $role->loadCount('adminUsers');
        });
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(Role $role, array $data): Role
    {
        if ($role->isLocked()) {
            throw new BusinessException('系统默认角色不可直接编辑，请复制后自定义');
        }

        $name = $this->normalizeName($data['name'] ?? '');
        if (AdminPermissions::isBuiltInRoleName($name)) {
            throw new BusinessException('系统默认角色编码不可用于自定义角色');
        }

        $permissions = $this->normalizePermissions((array) ($data['permissions'] ?? []));
        if ($this->roleHasAllPermission($role) && ! in_array(AdminPermissions::ALL, $permissions, true)) {
            throw new BusinessException('不能移除超级角色的全部权限');
        }

        return DB::transaction(function () use ($role, $data, $name, $permissions): Role {
            $role->update([
                'name' => $name,
                'label' => $this->normalizeLabel($data['label'] ?? ''),
                'permissions' => $permissions,
            ]);

            return $role->refresh()->loadCount('adminUsers');
        });
    }

    public function copy(Role $role): Role
    {
        $baseName = $this->normalizeName($role->name.'_copy');
        $name = $this->uniqueRoleName($baseName);
        $permissions = $role->resolvedPermissions();

        return DB::transaction(function () use ($role, $name, $permissions): Role {
            $newRole = Role::query()->create([
                'name' => $name,
                'label' => trim((string) ($role->label ?? $role->name)).' 副本',
                'permissions' => $permissions,
            ]);

            return $newRole->loadCount('adminUsers');
        });
    }

    public function delete(Role $role): void
    {
        if ($role->isLocked()) {
            throw new BusinessException('系统默认角色不可删除');
        }

        $adminCount = $this->assignedAdminCount($role);
        if ($adminCount > 0) {
            throw new BusinessException('当前角色仍有员工使用，无法删除');
        }

        if ($this->roleHasAllPermission($role)) {
            throw new BusinessException('不能删除超级角色');
        }

        $role->delete();
    }

    /**
     * @param  string[]  $permissions
     * @return string[]
     */
    private function normalizePermissions(array $permissions): array
    {
        $validKeys = $this->permissionCatalogService->validKeys();
        $normalized = array_values(array_unique(array_filter(array_map(
            static fn (mixed $permission) => is_string($permission) ? trim($permission) : '',
            $permissions
        ))));

        $invalid = array_values(array_diff($normalized, $validKeys));
        if ($invalid !== []) {
            throw new BusinessException('包含无效权限码：'.implode(', ', $invalid));
        }

        if (in_array(AdminPermissions::ALL, $normalized, true)) {
            return [AdminPermissions::ALL];
        }

        return $normalized;
    }

    private function normalizeName(mixed $value): string
    {
        $name = trim((string) $value);
        throw_if($name === '', new BusinessException('角色编码不能为空'));

        return $name;
    }

    private function normalizeLabel(mixed $value): string
    {
        $label = trim((string) $value);
        throw_if($label === '', new BusinessException('角色名称不能为空'));

        return $label;
    }

    private function roleHasAllPermission(Role $role): bool
    {
        return in_array(AdminPermissions::ALL, $role->resolvedPermissions(), true);
    }

    private function assignedAdminCount(Role $role): int
    {
        $primaryCount = (int) $role->adminUsers()->count();
        if (! Schema::hasTable('admin_user_roles')) {
            return $primaryCount;
        }

        $pivotCount = (int) DB::table('admin_user_roles')
            ->where('role_id', (int) $role->id)
            ->distinct()
            ->count('admin_user_id');

        return max($primaryCount, $pivotCount);
    }

    private function uniqueRoleName(string $baseName): string
    {
        $baseName = Str::limit($baseName, 42, '');
        $name = $baseName;
        $index = 1;

        while (Role::query()->where('name', $name)->exists()) {
            $name = $baseName.'_'.$index;
            $index++;
        }

        return $name;
    }
}
