<?php

declare(strict_types=1);

namespace App\Services\Admin\Rbac;

use App\Exceptions\BusinessException;
use App\Models\AdminUser;
use App\Models\Role;
use App\Services\System\OperationLogService;
use App\Services\User\AdminRoleBridgeService;
use App\Support\AdminPermissions;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

class AdminStaffService
{
    private const ROLE_OPTIONS_LIMIT = 100;

    public function __construct(
        private readonly AdminRoleBridgeService $adminRoleBridgeService,
        private readonly OperationLogService $operationLogService,
        private readonly BuiltinAdminRoleService $builtinAdminRoleService,
    ) {}

    /**
     * @param  array<string, mixed>  $filters
     */
    public function list(array $filters, int $perPage): LengthAwarePaginator
    {
        $keyword = trim((string) ($filters['keyword'] ?? ''));

        return AdminUser::query()
            ->withResolvedPermissionRelations()
            ->when($keyword !== '', function ($query) use ($keyword): void {
                $query->where(function ($inner) use ($keyword): void {
                    $inner
                        ->where('username', 'like', "%{$keyword}%")
                        ->orWhere('nickname', 'like', "%{$keyword}%")
                        ->orWhere('email', 'like', "%{$keyword}%");
                });
            })
            ->when(isset($filters['status']) && $filters['status'] !== '', fn ($query) => $query->where('status', (int) $filters['status']))
            ->when(isset($filters['role_id']) && (int) $filters['role_id'] > 0, fn ($query) => $query->where('role_id', (int) $filters['role_id']))
            ->orderByDesc('id')
            ->paginate($perPage);
    }

    /**
     * @return Collection<int, Role>
     */
    public function roleOptions(): Collection
    {
        $this->builtinAdminRoleService->sync();

        return Role::query()
            ->orderByDesc('id')
            ->limit(self::ROLE_OPTIONS_LIMIT)
            ->get(['id', 'name', 'label']);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function create(array $data, AdminUser $operator, ?string $ipAddress = null): AdminUser
    {
        $staff = DB::transaction(function () use ($data): AdminUser {
            $staff = AdminUser::query()->create([
                'username' => trim((string) $data['username']),
                'password' => (string) $data['password'],
                'nickname' => $this->nullableString($data['nickname'] ?? null),
                'email' => $this->nullableString($data['email'] ?? null),
                'role_id' => (int) $data['role_id'],
                'status' => (int) ($data['status'] ?? 1),
            ]);

            $this->adminRoleBridgeService->syncPrimaryRole($staff);

            return $staff->fresh(['role']) ?? $staff;
        });

        $this->writeLog($operator, 'admin.staff.create', $staff, [
            'username' => (string) $staff->username,
            'role_id' => (int) ($staff->role_id ?? 0),
        ], $ipAddress);

        return $staff;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(AdminUser $staff, array $data, AdminUser $operator, ?string $ipAddress = null): AdminUser
    {
        if (
            ! in_array(AdminPermissions::ALL, $operator->resolvedPermissions(), true)
            && (
                array_key_exists('username', $data)
                || array_key_exists('email', $data)
            )
        ) {
            throw new BusinessException('只有超级管理员可以修改员工账号和邮箱', 40300, 403);
        }

        $targetStatus = (int) ($data['status'] ?? $staff->status);
        if ((int) $staff->id === (int) $operator->id && $targetStatus !== 1) {
            throw new BusinessException('不能停用当前登录管理员');
        }

        if ((int) $staff->status === 1 && $targetStatus !== 1 && $this->isLastSuperAdmin($staff)) {
            throw new BusinessException('不能停用最后一个超级管理员');
        }

        if (
            (int) $staff->status === 1
            && $targetStatus === 1
            && (int) ($staff->role_id ?? 0) !== (int) $data['role_id']
            && $this->isLastSuperAdmin($staff)
            && ! $this->roleHasAllPermission((int) $data['role_id'])
        ) {
            throw new BusinessException('不能移除最后一个超级管理员的超级权限');
        }

        $updated = DB::transaction(function () use ($staff, $data, $targetStatus): AdminUser {
            $updates = [
                'nickname' => $this->nullableString($data['nickname'] ?? null),
                'role_id' => (int) $data['role_id'],
                'status' => $targetStatus,
            ];

            if (array_key_exists('username', $data)) {
                $updates['username'] = trim((string) $data['username']);
            }

            if (array_key_exists('email', $data)) {
                $updates['email'] = $this->nullableString($data['email'] ?? null);
            }

            $staff->update($updates);

            $this->adminRoleBridgeService->syncPrimaryRole($staff, (int) $data['role_id']);

            return $staff->fresh(['role']) ?? $staff;
        });

        $this->writeLog($operator, 'admin.staff.update', $updated, [
            'username' => (string) $updated->username,
            'role_id' => (int) ($updated->role_id ?? 0),
            'status' => (int) $updated->status,
        ], $ipAddress);

        return $updated;
    }

    public function toggleStatus(AdminUser $staff, AdminUser $operator, ?string $ipAddress = null): AdminUser
    {
        $targetStatus = (int) $staff->status === 1 ? 0 : 1;

        if ((int) $staff->id === (int) $operator->id && $targetStatus !== 1) {
            throw new BusinessException('不能停用当前登录管理员');
        }

        if ((int) $staff->status === 1 && $targetStatus !== 1 && $this->isLastSuperAdmin($staff)) {
            throw new BusinessException('不能停用最后一个超级管理员');
        }

        $staff->update(['status' => $targetStatus]);
        $staff->tokens()->delete();
        $staff = $staff->fresh(['role']) ?? $staff;

        $this->writeLog($operator, 'admin.staff.status', $staff, [
            'username' => (string) $staff->username,
            'status' => $targetStatus,
        ], $ipAddress);

        return $staff;
    }

    public function resetPassword(AdminUser $staff, string $password, AdminUser $operator, ?string $ipAddress = null): void
    {
        $this->ensureSuperAdmin($operator, '只有超级管理员可以重置员工密码');

        DB::transaction(function () use ($staff, $password): void {
            $staff->update(['password' => $password]);
            $staff->tokens()->delete();
        });

        $this->writeLog($operator, 'admin.staff.password_reset', $staff, [
            'username' => (string) $staff->username,
        ], $ipAddress);
    }

    public function updateOwnPassword(AdminUser $staff, string $currentPassword, string $password, ?string $ipAddress = null): void
    {
        if (! Hash::check($currentPassword, (string) $staff->password)) {
            throw new BusinessException('当前密码不正确');
        }

        DB::transaction(function () use ($staff, $password): void {
            $currentTokenId = $staff->currentAccessToken()?->id;

            $staff->update(['password' => $password]);

            $staff->tokens()
                ->when($currentTokenId !== null, fn ($query) => $query->where('id', '!=', $currentTokenId))
                ->delete();
        });

        $this->writeLog($staff, 'admin.auth.password_update', $staff, [
            'username' => (string) $staff->username,
        ], $ipAddress);
    }

    public function deleteDisabled(AdminUser $staff, AdminUser $operator, ?string $ipAddress = null): void
    {
        $this->ensureSuperAdmin($operator, '只有超级管理员可以删除员工');

        if ((int) $staff->id === (int) $operator->id) {
            throw new BusinessException('不能删除当前登录管理员');
        }

        if ((int) $staff->status === 1) {
            throw new BusinessException('只能删除已停用的员工');
        }

        if ($this->isLastSuperAdmin($staff)) {
            throw new BusinessException('不能删除最后一个超级管理员');
        }

        $detail = [
            'username' => (string) $staff->username,
            'role_id' => (int) ($staff->role_id ?? 0),
        ];

        DB::transaction(function () use ($staff): void {
            $staff->tokens()->delete();
            $staff->roles()->detach();
            $staff->delete();
        });

        $this->writeLog($operator, 'admin.staff.delete', $staff, $detail, $ipAddress);
    }

    private function isLastSuperAdmin(AdminUser $staff): bool
    {
        if (! in_array(AdminPermissions::ALL, $staff->resolvedPermissions(), true)) {
            return false;
        }

        return AdminUser::query()
            ->active()
            ->where('id', '!=', (int) $staff->id)
            ->get()
            ->filter(fn (AdminUser $admin) => in_array(AdminPermissions::ALL, $admin->resolvedPermissions(), true))
            ->isEmpty();
    }

    private function nullableString(mixed $value): ?string
    {
        $value = trim((string) $value);

        return $value !== '' ? $value : null;
    }

    private function roleHasAllPermission(int $roleId): bool
    {
        if ($roleId <= 0) {
            return false;
        }

        $role = Role::query()->find($roleId);
        if (! $role) {
            return false;
        }

        return in_array(AdminPermissions::ALL, $role->resolvedPermissions(), true);
    }

    private function ensureSuperAdmin(AdminUser $operator, string $message): void
    {
        if (! in_array(AdminPermissions::ALL, $operator->resolvedPermissions(), true)) {
            throw new BusinessException($message, 40300, 403);
        }
    }

    /**
     * @param  array<string, mixed>  $detail
     */
    private function writeLog(AdminUser $operator, string $action, AdminUser $staff, array $detail, ?string $ipAddress): void
    {
        $this->operationLogService->write(
            userId: (int) $operator->id,
            userType: 'admin',
            action: $action,
            module: 'staff',
            targetId: (int) $staff->id,
            detail: $detail,
            ipAddress: $ipAddress,
        );
    }
}
