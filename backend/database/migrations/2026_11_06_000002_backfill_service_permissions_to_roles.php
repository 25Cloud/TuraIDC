<?php

declare(strict_types=1);

use App\Support\AdminPermissions;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * 服务实例权限拆分的存量回填（只新增）。
 *
 * 背景：`GET api/v2/admin/os-options` 与 `POST api/v2/admin/services/custom-hostnames/batch`
 * 原本挂在 `user.manage` 分组下——「改实例主机名」要连带整套用户管理权限（改资料、改余额、
 * 代登录）。现在改挂 `service.view` / `service.manage`，自定义角色若只带旧的 `user.manage`，
 * 升级后会静默失去这两条接口的访问，所以按「谁原来能过、谁就补上新码」回填：
 *
 * - 持有 user.manage → 补 service.manage（隐含关系展开出 service.view）；
 * - 持有通配 * 的角色跳过：本来就全通过；
 * - 内置角色（super_admin/admin/visitor）跳过：它们的权限集由代码解析
 *   （adminDefaultPermissions() 已同步加入 service.manage），写进 JSON 会被内置解析覆盖。
 *
 * 幂等：已持有目标码的角色原样跳过。只把码追加到数组末尾，不动、不删任何已有权限码。
 * 兼容 MySQL 5.7 与 8.x：json 列在 PHP 侧解码再写回，不用 JSON_ARRAY_APPEND 等版本相关函数。
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

            if (in_array(AdminPermissions::ALL, $permissions, true)) {
                continue;
            }

            if (! in_array(AdminPermissions::USER_MANAGE, $permissions, true)) {
                continue;
            }

            $next = $permissions;
            foreach ([AdminPermissions::SERVICE_VIEW, AdminPermissions::SERVICE_MANAGE] as $code) {
                if (! in_array($code, $next, true)) {
                    $next[] = $code;
                }
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
        // 只新增：回滚版本不摘已发的权限码，避免把管理员正在用的能力删掉。
        // 与 2026_11_03 的插件迁移同口径——数据修正不做有损推断。
    }
};
