<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Support\AdminPermissions;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class BackfillTicketDeliveryManagePermissionMigrationTest extends TestCase
{
    use DatabaseTransactions;

    public function test_it_backfills_delivery_manage_for_custom_roles_with_ticket_manage(): void
    {
        $this->assertTrue(Schema::hasTable('roles'));

        $suffix = bin2hex(random_bytes(6));
        $roleWithManage = DB::table('roles')->insertGetId([
            'name' => 'migration-with-manage-'.$suffix,
            'label' => 'Migration With Manage',
            'permissions' => json_encode([
                AdminPermissions::TICKET_MANAGE,
                AdminPermissions::USER_LIST,
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $roleWithoutManage = DB::table('roles')->insertGetId([
            'name' => 'migration-without-manage-'.$suffix,
            'label' => 'Migration Without Manage',
            'permissions' => json_encode([AdminPermissions::USER_LIST], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $roleAlreadyGranted = DB::table('roles')->insertGetId([
            'name' => 'migration-already-granted-'.$suffix,
            'label' => 'Migration Already Granted',
            'permissions' => json_encode([
                AdminPermissions::TICKET_MANAGE,
                AdminPermissions::TICKET_DELIVERY_MANAGE,
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $builtInLikeRoleId = DB::table('roles')->insertGetId([
            'name' => 'builtin-like-'.$suffix,
            'label' => 'Builtin Like',
            'permissions' => json_encode([AdminPermissions::TICKET_MANAGE], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $migration = require database_path('migrations/2026_08_23_120000_backfill_ticket_delivery_manage_permission.php');
        $migration->up();

        $withManage = json_decode((string) DB::table('roles')->where('id', $roleWithManage)->value('permissions'), true);
        $this->assertContains(AdminPermissions::TICKET_DELIVERY_MANAGE, $withManage);
        $this->assertCount(3, $withManage);

        $withoutManage = json_decode((string) DB::table('roles')->where('id', $roleWithoutManage)->value('permissions'), true);
        $this->assertNotContains(AdminPermissions::TICKET_DELIVERY_MANAGE, $withoutManage);

        $alreadyGranted = json_decode((string) DB::table('roles')->where('id', $roleAlreadyGranted)->value('permissions'), true);
        $this->assertCount(2, $alreadyGranted);

        // 内置角色由 BuiltinAdminRoleService 管理，迁移只按名称精确跳过内置名，
        // 不按名字相似度猜测，因此自定义角色即使只含 ticket.manage 也正常补勾选。
        $builtInLike = json_decode((string) DB::table('roles')->where('id', $builtInLikeRoleId)->value('permissions'), true);
        $this->assertContains(AdminPermissions::TICKET_DELIVERY_MANAGE, $builtInLike);
    }

    public function test_it_is_idempotent_when_run_twice(): void
    {
        $this->assertTrue(Schema::hasTable('roles'));

        $suffix = bin2hex(random_bytes(6));
        $roleId = DB::table('roles')->insertGetId([
            'name' => 'migration-idempotent-'.$suffix,
            'label' => 'Migration Idempotent',
            'permissions' => json_encode([AdminPermissions::TICKET_MANAGE], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $migration = require database_path('migrations/2026_08_23_120000_backfill_ticket_delivery_manage_permission.php');
        $migration->up();
        $migration->up();

        $permissions = json_decode((string) DB::table('roles')->where('id', $roleId)->value('permissions'), true);
        $this->assertSame(
            [AdminPermissions::TICKET_MANAGE, AdminPermissions::TICKET_DELIVERY_MANAGE],
            $permissions
        );
    }
}
