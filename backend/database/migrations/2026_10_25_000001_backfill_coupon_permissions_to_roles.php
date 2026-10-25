<?php

declare(strict_types=1);

use App\Support\AdminPermissions;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * 券权限拆分的存量回填：券路由从 product.list / product.manage 换组到
 * coupon.list / coupon.manage，自定义角色如果只带着旧的 product.* 码，
 * 升级后会静默失去券的菜单与接口权限。
 *
 * 回填口径（能力不变，不替站长收紧）：
 * - 持有 product.manage → 补 coupon.manage（隐含关系会展开出 coupon.list）；
 * - 仅持有 product.list → 补 coupon.list；
 * - 内置角色（admin/visitor/super_admin）跳过：它们的权限集由代码解析
 *   （adminDefaultPermissions / visitorPermissions 已同步加入 coupon.*），
 *   写进 JSON 反而会被内置解析覆盖，也可能在权限码演进时变成死数据。
 *
 * 幂等：已持有目标码的角色原样跳过，可重复执行。
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
            ->get(['id', 'permissions']);

        foreach ($roles as $role) {
            $permissions = json_decode((string) $role->permissions, true);
            if (! is_array($permissions)) {
                continue;
            }

            $hasManage = in_array(AdminPermissions::PRODUCT_MANAGE, $permissions, true)
                || in_array(AdminPermissions::COUPON_MANAGE, $permissions, true);
            $hasList = in_array(AdminPermissions::PRODUCT_LIST, $permissions, true)
                || in_array(AdminPermissions::COUPON_LIST, $permissions, true);

            $next = $permissions;
            if ($hasManage && ! in_array(AdminPermissions::COUPON_MANAGE, $next, true)) {
                $next[] = AdminPermissions::COUPON_MANAGE;
            }
            if (($hasList || $hasManage) && ! in_array(AdminPermissions::COUPON_LIST, $next, true)) {
                $next[] = AdminPermissions::COUPON_LIST;
            }

            if ($next === $permissions) {
                continue;
            }

            DB::table('roles')->where('id', $role->id)->update([
                'permissions' => json_encode(array_values($next), JSON_UNESCAPED_UNICODE),
                'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        // 回滚只移除本迁移引入的券权限码；持有它们的角色若确需保留，
        // 由管理员在角色管理里重新授予，这里不做有损推断。
        if (! Schema::hasTable('roles')) {
            return;
        }

        $builtInNames = AdminPermissions::builtInRoleNames();

        $roles = DB::table('roles')
            ->whereNotIn('name', $builtInNames)
            ->get(['id', 'permissions']);

        foreach ($roles as $role) {
            $permissions = json_decode((string) $role->permissions, true);
            if (! is_array($permissions)) {
                continue;
            }

            $next = array_values(array_filter(
                $permissions,
                fn ($permission) => ! in_array($permission, [AdminPermissions::COUPON_LIST, AdminPermissions::COUPON_MANAGE], true),
            ));

            if ($next === $permissions) {
                continue;
            }

            DB::table('roles')->where('id', $role->id)->update([
                'permissions' => json_encode($next, JSON_UNESCAPED_UNICODE),
                'updated_at' => now(),
            ]);
        }
    }
};
