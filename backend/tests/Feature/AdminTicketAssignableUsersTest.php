<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\AdminUser;
use App\Models\Role;
use App\Support\AdminPermissions;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class AdminTicketAssignableUsersTest extends TestCase
{
    public function test_admin_ticket_assignable_users_only_returns_reply_capable_admins_without_permission_n_plus_one(): void
    {
        $suffix = bin2hex(random_bytes(4));

        $replyRole = Role::query()->create([
            'name' => 'ticket-reply-'.$suffix,
            'label' => '工单处理',
            'permissions' => [AdminPermissions::TICKET_REPLY],
        ]);
        $readonlyRole = Role::query()->create([
            'name' => 'ticket-read-'.$suffix,
            'label' => '只读角色',
            'permissions' => [],
        ]);

        AdminUser::query()->create([
            'username' => 'reply-admin-'.$suffix,
            'password' => 'secret123',
            'role_id' => (int) $replyRole->id,
            'nickname' => '可回复管理员',
            'email' => "reply-admin-{$suffix}@example.com",
            'status' => 1,
        ]);
        AdminUser::query()->create([
            'username' => 'readonly-admin-'.$suffix,
            'password' => 'secret123',
            'role_id' => (int) $readonlyRole->id,
            'nickname' => '只读管理员',
            'email' => "readonly-admin-{$suffix}@example.com",
            'status' => 1,
        ]);

        $replyAdminId = (int) AdminUser::query()->where('username', 'reply-admin-'.$suffix)->value('id');
        $readonlyAdminId = (int) AdminUser::query()->where('username', 'readonly-admin-'.$suffix)->value('id');

        DB::flushQueryLog();
        DB::enableQueryLog();

        $admins = AdminUser::query()
            ->withResolvedPermissionRelations()
            ->whereIn('id', [$replyAdminId, $readonlyAdminId])
            ->orderBy('id')
            ->get(['id', 'username', 'nickname', 'role_id', 'email'])
            ->filter(fn (AdminUser $admin) => $admin->hasPermission(AdminPermissions::TICKET_REPLY))
            ->values();

        $queries = DB::getQueryLog();
        DB::disableQueryLog();

        $nicknames = $admins->map(fn (AdminUser $admin) => $admin->display_name)->all();

        $this->assertContains('可回复管理员', $nicknames);
        $this->assertNotContains('只读管理员', $nicknames);
        $this->assertLessThanOrEqual(10, count($queries));
    }
}
