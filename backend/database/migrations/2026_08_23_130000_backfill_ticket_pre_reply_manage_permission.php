<?php

declare(strict_types=1);

use App\Support\AdminPermissions;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * 工单预回复设置权限的存量角色兼容补勾选。
 *
 * 背景：ticket.pre_reply_manage 是新独立权限点，不挂在 ticket.manage 的
 * 隐含权限集下（与 ticket.delivery_manage 一致），必须显式授予。为避免
 * 升级后「工单管理」角色的管理员失去预回复设置入口（功能丢失），本迁移给
 * 所有已含 ticket.manage 的自定义角色补上 ticket.pre_reply_manage。
 *
 * 内置角色（super_admin/admin/visitor）由 BuiltinAdminRoleService::sync()
 * 管理，不在此处理。
 *
 * 幂等：只对不含 pre_reply_manage 且含 ticket.manage 的角色插入，
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
            $hasPreReply = in_array(AdminPermissions::TICKET_PRE_REPLY_MANAGE, $permissions, true);
            if (! $hasManage || $hasPreReply) {
                continue;
            }

            $permissions[] = AdminPermissions::TICKET_PRE_REPLY_MANAGE;
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
