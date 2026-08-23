<?php

declare(strict_types=1);

use App\Support\AdminPermissions;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * 工单传递设置权限独立化后的存量角色兼容补勾选。
 *
 * 背景：ticket.delivery_manage 原本挂在 ticket.manage 的隐含权限集下
 * （impliedPermissions），因此任何持 ticket.manage 的管理员即使角色未显式
 * 勾选也能访问工单传递设置。本次把 delivery_manage 从隐含集移除，改为
 * 必须显式授予，以支持"只管理工单、不配置传递"的权限隔离。
 *
 * 为避免升级后存量管理员失去既有访问能力（功能丢失），本迁移给所有已
 * 含 ticket.manage 的自定义角色补上 ticket.delivery_manage，等价于把
 * 旧隐含关系固化进角色显式权限。内置角色（super_admin/admin/visitor）
 * 由 BuiltinAdminRoleService::sync() 管理，不在此处理。
 *
 * 幂等：只对不含 delivery_manage 且含 ticket.manage 的角色插入，
 * 重复执行无副作用。
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('roles')) {
            return;
        }

        $builtInNames = AdminPermissions::builtInRoleNames();

        $roles = DB::table('roles')
            ->whereNotIn('name', $builtInNames)
            ->orderBy('id')
            ->get(['id', 'name', 'permissions']);

        foreach ($roles as $role) {
            $permissions = json_decode((string) $role->permissions, true);
            if (! is_array($permissions)) {
                continue;
            }

            $hasManage = in_array(AdminPermissions::TICKET_MANAGE, $permissions, true);
            $hasDelivery = in_array(AdminPermissions::TICKET_DELIVERY_MANAGE, $permissions, true);
            if (! $hasManage || $hasDelivery) {
                continue;
            }

            $permissions[] = AdminPermissions::TICKET_DELIVERY_MANAGE;
            DB::table('roles')->where('id', $role->id)->update([
                'permissions' => json_encode(array_values($permissions), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        // 不回滚：移除已固化的显式权限同样会造成功能丢失，且权限归属应保留。
    }
};
