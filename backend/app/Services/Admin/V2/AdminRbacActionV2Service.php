<?php

declare(strict_types=1);

namespace App\Services\Admin\V2;

use App\Models\AdminUser;
use App\Models\Role;
use App\Services\Admin\Rbac\AdminRoleService;
use App\Services\Admin\Rbac\AdminStaffService;

class AdminRbacActionV2Service
{
    public function __construct(
        private readonly AdminStaffService $staffService,
        private readonly AdminRoleService $roleService,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function updateStaffStatus(AdminUser $staff, bool $enabled, AdminUser $operator, ?string $ipAddress = null): array
    {
        $targetStatus = $enabled ? 1 : 0;

        if ((int) $staff->status !== $targetStatus) {
            $staff = $this->staffService->toggleStatus($staff, $operator, $ipAddress);
        } else {
            $staff = $staff->fresh(['role']) ?? $staff;
        }

        return [
            'id' => (int) $staff->id,
            'status' => 'completed',
            'message' => '员工状态已更新',
            'detail' => [
                'type' => 'status',
                'target' => 'staff',
                'enabled' => $enabled,
                'staff' => [
                    'id' => (int) $staff->id,
                    'status' => (int) $staff->status,
                    'role_id' => $staff->role_id !== null ? (int) $staff->role_id : null,
                ],
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function resetStaffPassword(AdminUser $staff, string $password, AdminUser $operator, ?string $ipAddress = null): array
    {
        $this->staffService->resetPassword($staff, $password, $operator, $ipAddress);

        return [
            'id' => (int) $staff->id,
            'status' => 'completed',
            'message' => '员工密码已重置',
            'detail' => [
                'type' => 'password_reset',
                'staff' => [
                    'id' => (int) $staff->id,
                ],
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function copyRole(Role $role): array
    {
        $newRole = $this->roleService->copy($role);

        return [
            'id' => (int) $newRole->id,
            'status' => 'completed',
            'message' => '角色复制成功',
            'detail' => [
                'type' => 'copy',
                'role' => [
                    'id' => (int) $newRole->id,
                    'name' => (string) $newRole->name,
                    'label' => (string) ($newRole->label ?? $newRole->name),
                    'permissions_count' => count($newRole->resolvedPermissions()),
                ],
            ],
        ];
    }
}
