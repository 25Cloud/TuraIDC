<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\AdminUser;
use App\Models\Product;
use App\Models\Role;
use App\Models\Supplier;
use App\Services\System\AdminLogService;
use App\Support\AdminPermissions;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AdminSecurityBoundaryRegressionTest extends TestCase
{
    public function test_order_list_permission_does_not_allow_order_detail(): void
    {
        Sanctum::actingAs($this->createAdminUser([AdminPermissions::ORDER_LIST]));

        $this->getJson('/api/v2/admin/orders/1')
            ->assertForbidden()
            ->assertJsonPath('code', 40300);
    }

    public function test_log_list_permission_does_not_allow_log_cleanup(): void
    {
        Sanctum::actingAs($this->createAdminUser([AdminPermissions::LOG_LIST]));

        $this->postJson('/api/v2/admin/log-cleanups', [
            'type' => 'api',
            'keep_days' => 30,
            'confirm_text' => '立即清理',
        ])
            ->assertForbidden()
            ->assertJsonPath('code', 40300);
    }

    public function test_log_cleanup_accepts_schedule_run_type_from_overview(): void
    {
        Cache::flush();
        Sanctum::actingAs($this->createAdminUser([AdminPermissions::LOG_MANAGE]));

        $oldLogId = DB::table('schedule_run_logs')->insertGetId([
            'task_name' => 'cleanup-boundary-old-'.bin2hex(random_bytes(4)),
            'status' => 'success',
            'duration_ms' => 1,
            'summary' => json_encode([], JSON_THROW_ON_ERROR),
            'started_at' => now()->subYears(20),
            'finished_at' => now()->subYears(20),
            'created_at' => now()->subYears(20),
            'updated_at' => now()->subYears(20),
        ]);
        $recentLogId = DB::table('schedule_run_logs')->insertGetId([
            'task_name' => 'cleanup-boundary-recent-'.bin2hex(random_bytes(4)),
            'status' => 'success',
            'duration_ms' => 1,
            'summary' => json_encode([], JSON_THROW_ON_ERROR),
            'started_at' => now(),
            'finished_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $response = $this->postJson('/api/v2/admin/log-cleanups', [
            'type' => 'schedule_run',
            'keep_days' => 3650,
            'confirm_text' => '立即清理',
        ]);

        $response
            ->assertOk()
            ->assertJsonPath('code', 0);

        $this->assertGreaterThanOrEqual(1, (int) $response->json('data.detail.cleanup.affected.schedule_run'));

        $this->assertDatabaseMissing('schedule_run_logs', ['id' => (int) $oldLogId]);
        $this->assertDatabaseHas('schedule_run_logs', ['id' => (int) $recentLogId]);
    }

    public function test_log_cleanup_refreshes_overview_cache_after_database_cleanup(): void
    {
        Cache::flush();
        Sanctum::actingAs($this->createAdminUser([AdminPermissions::LOG_MANAGE]));

        $requestId = 'cleanup-overview-'.bin2hex(random_bytes(4));
        DB::table('message_logs')->insert([
            'channel' => 'sms',
            'recipient' => '13900000000',
            'template_code' => 'cleanup-test',
            'content' => 'cleanup test',
            'params_json' => json_encode([], JSON_THROW_ON_ERROR),
            'provider' => 'test',
            'request_id' => $requestId,
            'status' => 'success',
            'created_at' => now()->subYears(20),
            'updated_at' => now()->subYears(20),
        ]);

        $before = $this->getJson('/api/v2/admin/log-cleanups/overview')
            ->assertOk()
            ->json('data.database.sms');

        $this->postJson('/api/v2/admin/log-cleanups', [
            'type' => 'sms',
            'keep_days' => 3650,
            'confirm_text' => '立即清理',
        ])->assertOk();

        $after = $this->getJson('/api/v2/admin/log-cleanups/overview')
            ->assertOk()
            ->json('data.database.sms');

        $this->assertLessThan((int) $before, (int) $after);
        $this->assertDatabaseMissing('message_logs', ['request_id' => $requestId]);
    }

    public function test_file_log_cleanup_removes_multiline_entries_as_a_unit(): void
    {
        Cache::flush();
        $originalStoragePath = app()->storagePath();
        $tempStoragePath = $originalStoragePath.DIRECTORY_SEPARATOR.'framework'.DIRECTORY_SEPARATOR.'testing'.DIRECTORY_SEPARATOR.'log-cleanup-'.bin2hex(random_bytes(4));

        File::ensureDirectoryExists($tempStoragePath.DIRECTORY_SEPARATOR.'logs');
        app()->useStoragePath($tempStoragePath);

        try {
            file_put_contents(storage_path('logs/laravel.log'), implode("\n", [
                '['.now()->subYears(20)->format('Y-m-d H:i:s').'] local.ERROR: cleanup old failure',
                '[stacktrace]',
                '#0 /app/OldFailure.php(1): old()',
                '['.now()->format('Y-m-d H:i:s').'] local.INFO: cleanup recent message',
                '',
            ]));

            app(AdminLogService::class)->cleanup([
                'type' => 'system',
                'keep_days' => 3650,
                'confirm_text' => '立即清理',
            ]);

            $content = (string) file_get_contents(storage_path('logs/laravel.log'));

            $this->assertStringNotContainsString('cleanup old failure', $content);
            $this->assertStringNotContainsString('[stacktrace]', $content);
            $this->assertStringNotContainsString('OldFailure.php', $content);
            $this->assertStringContainsString('cleanup recent message', $content);
        } finally {
            app()->useStoragePath($originalStoragePath);
            File::deleteDirectory($tempStoragePath);
            Cache::flush();
        }
    }

    public function test_supplier_with_bound_products_cannot_be_deleted(): void
    {
        $suffix = bin2hex(random_bytes(4));
        $supplier = Supplier::query()->create([
            'name' => 'Delete Guard Supplier '.$suffix,
            'code' => 'delete_guard_'.$suffix,
            'status' => 1,
            'sort_order' => 1,
        ]);

        $product = Product::query()->create([
            'name' => 'Delete Guard Product '.$suffix,
            'product_type' => 'cloud',
            'remark' => 'Delete Guard Product',
            'pricing' => ['monthly' => '100.00'],
            'config_options' => [],
            'purchase_requires' => [],
            'stock' => 10,
            'status' => 1,
            'sort_order' => 1,
        ]);

        $pluginId = (int) DB::table('integration_plugins')->insertGetId([
            'domain' => 'upstream',
            'slug' => 'delete_guard_'.$suffix,
            'plugin_key' => 'hosting_panel_api_delete_guard_'.$suffix,
            'name' => 'Delete Guard',
            'version' => '1.0.0',
            'entry_class' => 'Tests\\Fixtures\\DeleteGuardPlugin',
            'status' => 1,
            'installed_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $supplierBindingId = (int) DB::table('supplier_plugin_bindings')->insertGetId([
            'supplier_id' => (int) $supplier->id,
            'plugin_id' => $pluginId,
            'provider_key' => 'hosting_panel_api',
            'environment' => 'production',
            'status' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('product_upstream_bindings')->insert([
            'product_id' => (int) $product->id,
            'supplier_plugin_binding_id' => $supplierBindingId,
            'plugin_id' => $pluginId,
            'provider_key' => 'hosting_panel_api',
            'upstream_product_id' => '10001',
            'status' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        Sanctum::actingAs($this->createAdminUser([AdminPermissions::SUPPLIER_MANAGE]));

        $this->deleteJson('/api/v2/admin/suppliers/'.$supplier->id)
            ->assertStatus(409)
            ->assertJsonPath('code', 40900);

        $this->assertDatabaseHas('suppliers', ['id' => (int) $supplier->id]);
    }

    private function createAdminUser(array $permissions): AdminUser
    {
        $suffix = bin2hex(random_bytes(4));
        $role = Role::query()->create([
            'name' => 'security-boundary-role-'.$suffix,
            'label' => 'Security Boundary Role',
            'permissions' => $permissions,
        ]);

        return AdminUser::query()->create([
            'username' => 'security-boundary-admin-'.$suffix,
            'password' => 'secret123',
            'nickname' => 'Security Boundary Admin',
            'role_id' => (int) $role->id,
            'status' => 1,
        ]);
    }
}
