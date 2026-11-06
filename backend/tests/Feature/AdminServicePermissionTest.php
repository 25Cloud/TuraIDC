<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\AdminUser;
use App\Models\Role;
use App\Support\AdminPermissions;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * 服务实例权限拆分（service.view / service.manage）。
 *
 * 这两条路由原本借 `user.manage`：
 * - `GET  api/v2/admin/os-options`（只读下拉数据）
 * - `POST api/v2/admin/services/custom-hostnames/batch`（写实例主机名）
 *
 * 拆开之后要保证三件事：只给 user.manage 的角色不能再碰服务实例；给了 service.* 能进；
 * 存量角色由回填迁移补码，能力不变。另外顺带锁住「admin 路由权限审计 0 缺口」。
 */
class AdminServicePermissionTest extends TestCase
{
    use DatabaseTransactions;

    public function test_service_manage_guards_custom_hostname_batch_and_user_manage_no_longer_does(): void
    {
        // 只持 service.manage：过了鉴权，剩下的是入参校验（空 body → 422）
        Sanctum::actingAs($this->createAdmin('service-manage', [AdminPermissions::SERVICE_MANAGE]));
        $this->postJson('/api/v2/admin/services/custom-hostnames/batch', [])
            ->assertStatus(422);

        // 只持 user.manage（拆分前的旧口径）：必须被挡在 403
        Sanctum::actingAs($this->createAdmin('legacy-user-manage', [AdminPermissions::USER_MANAGE]));
        $this->postJson('/api/v2/admin/services/custom-hostnames/batch', [])
            ->assertForbidden()
            ->assertJsonPath('code', 40300);
    }

    public function test_service_view_guards_os_options_and_user_manage_no_longer_does(): void
    {
        Sanctum::actingAs($this->createAdmin('service-view', [AdminPermissions::SERVICE_VIEW]));
        $this->getJson('/api/v2/admin/os-options')
            ->assertOk()
            ->assertJsonPath('code', 0);

        Sanctum::actingAs($this->createAdmin('legacy-user-manage-view', [AdminPermissions::USER_MANAGE]));
        $this->getJson('/api/v2/admin/os-options')
            ->assertForbidden()
            ->assertJsonPath('code', 40300);
    }

    public function test_service_manage_implies_service_view_and_builtin_admin_keeps_access(): void
    {
        $this->assertContains(AdminPermissions::SERVICE_VIEW, AdminPermissions::impliedPermissions(AdminPermissions::SERVICE_MANAGE));

        // 内置 admin 角色按 adminDefaultPermissions() 解析：拆码后不能丢能力
        $adminDefaults = AdminPermissions::adminDefaultPermissions();
        $this->assertContains(AdminPermissions::SERVICE_MANAGE, $adminDefaults);
        $this->assertContains(AdminPermissions::SERVICE_VIEW, AdminPermissions::resolveRolePermissions('admin', $adminDefaults));

        // 只读访客不该因此拿到写权限
        $this->assertNotContains(AdminPermissions::SERVICE_MANAGE, AdminPermissions::visitorPermissions());
    }

    public function test_backfill_migration_grants_service_permissions_to_roles_that_had_user_manage(): void
    {
        $suffix = bin2hex(random_bytes(6));

        $withUserManage = $this->insertRole('backfill-service-with-user-manage-'.$suffix, [
            AdminPermissions::USER_MANAGE,
            AdminPermissions::USER_LIST,
        ]);
        $withoutUserManage = $this->insertRole('backfill-service-without-user-manage-'.$suffix, [
            AdminPermissions::USER_LIST,
        ]);
        $alreadyGranted = $this->insertRole('backfill-service-already-granted-'.$suffix, [
            AdminPermissions::USER_MANAGE,
            AdminPermissions::SERVICE_VIEW,
            AdminPermissions::SERVICE_MANAGE,
        ]);
        $wildcard = $this->insertRole('backfill-service-wildcard-'.$suffix, [AdminPermissions::ALL]);

        $migration = require database_path('migrations/2026_11_06_000002_backfill_service_permissions_to_roles.php');
        $migration->up();

        $granted = $this->rolePermissions($withUserManage);
        $this->assertContains(AdminPermissions::SERVICE_VIEW, $granted);
        $this->assertContains(AdminPermissions::SERVICE_MANAGE, $granted);
        // 只追加、不删已有码
        $this->assertContains(AdminPermissions::USER_MANAGE, $granted);
        $this->assertContains(AdminPermissions::USER_LIST, $granted);
        $this->assertCount(4, $granted);

        $untouched = $this->rolePermissions($withoutUserManage);
        $this->assertNotContains(AdminPermissions::SERVICE_VIEW, $untouched);
        $this->assertNotContains(AdminPermissions::SERVICE_MANAGE, $untouched);

        // 幂等：已有码不重复追加
        $this->assertCount(3, $this->rolePermissions($alreadyGranted));

        // 通配角色本来就全通过，不该被塞进一堆具体码
        $this->assertSame([AdminPermissions::ALL], $this->rolePermissions($wildcard));

        // 再跑一次仍然不重复
        $migration->up();
        $this->assertCount(4, $this->rolePermissions($withUserManage));
    }

    public function test_admin_route_permission_audit_reports_no_gaps(): void
    {
        $exitCode = Artisan::call('audit:admin-routes', ['--json' => true]);
        $report = json_decode(trim(Artisan::output()), true);

        $this->assertIsArray($report, '--json 的输出必须是合法 JSON');
        $this->assertSame([], $report['missing'], '不允许有缺权限的 admin 路由');
        $this->assertSame([], $report['unknown'], '不允许有不在权限表里的权限码');
        $this->assertSame(0, $exitCode);

        // 两条被拆分的路由确实挂上了新码
        $covered = collect((array) $report['whitelisted_routes'])->pluck('uri')->all();
        $this->assertNotContains('api/v2/admin/os-options', $covered);
        $this->assertNotContains('api/v2/admin/services/custom-hostnames/batch', $covered);
    }

    /**
     * @param  list<string>  $permissions
     */
    private function insertRole(string $name, array $permissions): int
    {
        return (int) DB::table('roles')->insertGetId([
            'name' => $name,
            'label' => $name,
            'permissions' => json_encode($permissions, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /**
     * @return array<int, string>
     */
    private function rolePermissions(int $roleId): array
    {
        $decoded = json_decode((string) DB::table('roles')->where('id', $roleId)->value('permissions'), true);

        return is_array($decoded) ? array_values($decoded) : [];
    }

    /**
     * @param  list<string>  $permissions
     */
    private function createAdmin(string $prefix, array $permissions): AdminUser
    {
        $suffix = bin2hex(random_bytes(4));
        $role = Role::query()->create([
            'name' => $prefix.'-'.$suffix,
            'label' => $prefix,
            'permissions' => $permissions,
        ]);

        return AdminUser::query()->create([
            'username' => $prefix.'-'.$suffix,
            'password' => 'Temp@123456',
            'role_id' => (int) $role->id,
            'nickname' => $prefix,
            'email' => $prefix.'-'.$suffix.'@example.com',
            'status' => 1,
        ]);
    }
}
