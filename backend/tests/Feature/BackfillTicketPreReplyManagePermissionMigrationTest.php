<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Support\AdminPermissions;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class BackfillTicketPreReplyManagePermissionMigrationTest extends TestCase
{
    use DatabaseTransactions;

    public function test_it_backfills_pre_reply_manage_for_custom_roles_with_ticket_manage(): void
    {
        $this->assertTrue(Schema::hasTable('roles'));

        $suffix = bin2hex(random_bytes(6));
        $roleWithManage = DB::table('roles')->insertGetId([
            'name' => 'pre-reply-migration-with-manage-'.$suffix,
            'label' => 'Pre Reply Migration With Manage',
            'permissions' => json_encode([
                AdminPermissions::TICKET_MANAGE,
                AdminPermissions::USER_LIST,
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $roleWithoutManage = DB::table('roles')->insertGetId([
            'name' => 'pre-reply-migration-without-manage-'.$suffix,
            'label' => 'Pre Reply Migration Without Manage',
            'permissions' => json_encode([AdminPermissions::USER_LIST], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $roleAlreadyGranted = DB::table('roles')->insertGetId([
            'name' => 'pre-reply-migration-already-granted-'.$suffix,
            'label' => 'Pre Reply Migration Already Granted',
            'permissions' => json_encode([
                AdminPermissions::TICKET_MANAGE,
                AdminPermissions::TICKET_PRE_REPLY_MANAGE,
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $migration = require database_path('migrations/2026_08_23_130000_backfill_ticket_pre_reply_manage_permission.php');
        $migration->up();

        $withManage = json_decode((string) DB::table('roles')->where('id', $roleWithManage)->value('permissions'), true);
        $this->assertContains(AdminPermissions::TICKET_PRE_REPLY_MANAGE, $withManage);
        $this->assertCount(3, $withManage);

        $withoutManage = json_decode((string) DB::table('roles')->where('id', $roleWithoutManage)->value('permissions'), true);
        $this->assertNotContains(AdminPermissions::TICKET_PRE_REPLY_MANAGE, $withoutManage);

        $alreadyGranted = json_decode((string) DB::table('roles')->where('id', $roleAlreadyGranted)->value('permissions'), true);
        $this->assertCount(2, $alreadyGranted);
    }

    public function test_it_is_idempotent_when_run_twice(): void
    {
        $this->assertTrue(Schema::hasTable('roles'));

        $suffix = bin2hex(random_bytes(6));
        $roleId = DB::table('roles')->insertGetId([
            'name' => 'pre-reply-migration-idempotent-'.$suffix,
            'label' => 'Pre Reply Migration Idempotent',
            'permissions' => json_encode([AdminPermissions::TICKET_MANAGE], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $migration = require database_path('migrations/2026_08_23_130000_backfill_ticket_pre_reply_manage_permission.php');
        $migration->up();
        $migration->up();

        $permissions = json_decode((string) DB::table('roles')->where('id', $roleId)->value('permissions'), true);
        $this->assertContains(AdminPermissions::TICKET_PRE_REPLY_MANAGE, $permissions);
        $this->assertCount(2, $permissions);
    }
}
