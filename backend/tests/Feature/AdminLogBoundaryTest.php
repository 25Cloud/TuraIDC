<?php

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\AdminUser;
use App\Models\GatewayLog;
use App\Models\IntegrationPlugin;
use App\Models\IntegrationPluginRuntimeLog;
use App\Models\MessageLog;
use App\Models\Role;
use App\Models\User;
use App\Support\AdminPermissions;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AdminLogBoundaryTest extends TestCase
{
    public function test_gateway_log_route_applies_gateway_action_and_result_filters(): void
    {
        $admin = $this->createAdminUser([AdminPermissions::LOG_LIST]);
        Sanctum::actingAs($admin);

        $suffix = bin2hex(random_bytes(4));
        $plugin = $this->createLogPlugin('payment', 'ali_pay', 'alipay');

        GatewayLog::query()->create([
            'gateway' => 'alipay',
            'plugin_id' => (int) $plugin->id,
            'gateway_key' => 'alipay',
            'action' => 'refund',
            'out_trade_no' => 'boundary-'.$suffix.'-refund',
            'trade_no' => 'trade-'.$suffix.'-refund',
            'trace_id' => 'gateway-trace-'.$suffix,
            'result_status' => 'failed',
            'error_msg' => 'boundary-'.$suffix,
        ]);

        GatewayLog::query()->create([
            'gateway' => 'alipay',
            'gateway_key' => 'yipay',
            'action' => 'precreate',
            'out_trade_no' => 'boundary-'.$suffix.'-precreate',
            'trade_no' => 'trade-'.$suffix.'-precreate',
            'trace_id' => 'other-trace-'.$suffix,
            'result_status' => 'success',
            'error_msg' => null,
        ]);

        $this->getJson("/api/v2/admin/logs/gateway?keyword={$suffix}&gateway=alipay&gateway_key=alipay&plugin_id={$plugin->id}&trace_id=gateway-trace&action=refund&result_status=failed")
            ->assertOk()
            ->assertJsonPath('code', 0)
            ->assertJsonPath('data.total', 1)
            ->assertJsonPath('data.list.0.gateway', 'alipay')
            ->assertJsonPath('data.list.0.gateway_key', 'alipay')
            ->assertJsonPath('data.list.0.plugin_id', (int) $plugin->id)
            ->assertJsonPath('data.list.0.trace_id', 'gateway-trace-'.$suffix)
            ->assertJsonPath('data.list.0.action', 'refund')
            ->assertJsonPath('data.list.0.result_status', 'failed');
    }

    public function test_runtime_log_route_filters_plugin_runtime_logs(): void
    {
        $admin = $this->createAdminUser([AdminPermissions::LOG_LIST]);
        Sanctum::actingAs($admin);

        $suffix = bin2hex(random_bytes(4));
        $plugin = $this->createLogPlugin('payment', 'ali_pay', 'alipay');

        IntegrationPluginRuntimeLog::query()->create([
            'trace_id' => 'runtime-trace-'.$suffix,
            'domain' => 'payment',
            'plugin_id' => (int) $plugin->id,
            'plugin_key' => 'alipay',
            'slug' => 'ali_pay',
            'action' => 'precreate',
            'status' => 'success',
            'duration_ms' => 12,
            'created_at' => now(),
        ]);

        IntegrationPluginRuntimeLog::query()->create([
            'trace_id' => 'runtime-other-'.$suffix,
            'domain' => 'payment',
            'plugin_id' => null,
            'plugin_key' => 'yipay',
            'slug' => 'yi_pay',
            'action' => 'precreate',
            'status' => 'failed',
            'error_message' => 'other plugin',
            'created_at' => now(),
        ]);

        $this->getJson("/api/v2/admin/logs/runtime?plugin_id={$plugin->id}&gateway_key=alipay&trace_id=runtime-trace-{$suffix}")
            ->assertOk()
            ->assertJsonPath('code', 0)
            ->assertJsonPath('data.total', 1)
            ->assertJsonPath('data.list.0.source', 'integration_plugin_runtime_logs')
            ->assertJsonPath('data.list.0.plugin_id', (int) $plugin->id)
            ->assertJsonPath('data.list.0.plugin_key', 'alipay')
            ->assertJsonPath('data.list.0.trace_id', 'runtime-trace-'.$suffix);
    }

    public function test_notification_log_routes_filter_plugin_driver_and_trace_fields(): void
    {
        $admin = $this->createAdminUser([AdminPermissions::LOG_LIST]);
        Sanctum::actingAs($admin);

        $suffix = bin2hex(random_bytes(4));
        $plugin = $this->createLogPlugin('sms', 'aliyun_sms', 'aliyun_sms');

        MessageLog::query()->create([
            'channel' => 'sms',
            'plugin_id' => (int) $plugin->id,
            'driver_key' => 'aliyun_sms',
            'trace_id' => 'notify-trace-'.$suffix,
            'recipient' => '13900000000',
            'template_code' => 'sms-'.$suffix,
            'content' => 'sms boundary',
            'provider' => 'aliyun',
            'request_id' => 'request-'.$suffix,
            'status' => 'success',
            'sent_at' => now(),
        ]);

        MessageLog::query()->create([
            'channel' => 'sms',
            'plugin_id' => null,
            'driver_key' => 'other_sms',
            'trace_id' => 'notify-other-'.$suffix,
            'recipient' => '13900000001',
            'template_code' => 'sms-other-'.$suffix,
            'content' => 'other sms',
            'provider' => 'other',
            'request_id' => 'other-'.$suffix,
            'status' => 'failed',
            'sent_at' => now(),
        ]);

        $this->getJson("/api/v2/admin/logs/sms?plugin_id={$plugin->id}&driver_key=aliyun_sms&trace_id=notify-trace-{$suffix}")
            ->assertOk()
            ->assertJsonPath('code', 0)
            ->assertJsonPath('data.total', 1)
            ->assertJsonPath('data.list.0.plugin_id', (int) $plugin->id)
            ->assertJsonPath('data.list.0.driver_key', 'aliyun_sms')
            ->assertJsonPath('data.list.0.trace_id', 'notify-trace-'.$suffix);
    }

    public function test_system_log_route_returns_business_audit_logs(): void
    {
        $admin = $this->createAdminUser([AdminPermissions::LOG_LIST, AdminPermissions::PRIVACY_VIEW_RAW]);
        Sanctum::actingAs($admin);

        $suffix = bin2hex(random_bytes(4));

        ActivityLog::query()->create([
            'actor_type' => 'admin',
            'actor_id' => (int) $admin->id,
            'actor_name' => (string) $admin->username,
            'action' => 'invoice.audit.'.$suffix,
            'module' => 'invoice',
            'description' => 'Invoice ID:3001 audit '.$suffix,
            'subject_type' => 'invoice',
            'subject_id' => 3001,
            'context' => [
                'title' => 'Invoice ID:3001 audit '.$suffix,
            ],
            'ip_address' => '10.10.10.'.$admin->id,
        ]);

        $this->getJson('/api/v2/admin/logs/system?description_keyword='.rawurlencode($suffix).'&ip_address=10.10.10')
            ->assertOk()
            ->assertJsonPath('code', 0)
            ->assertJsonPath('data.total', 1)
            ->assertJsonPath('data.list.0.actor_type', 'admin')
            ->assertJsonPath('data.list.0.module', 'invoice')
            ->assertJsonPath('data.list.0.subject_id', 3001)
            ->assertJsonPath('data.list.0.source', 'activity_log');
    }

    public function test_runtime_log_route_reads_laravel_log_file(): void
    {
        Cache::flush();
        $admin = $this->createAdminUser([AdminPermissions::LOG_LIST, AdminPermissions::PRIVACY_VIEW_RAW]);
        Sanctum::actingAs($admin);

        $originalStoragePath = app()->storagePath();
        $tempStoragePath = $originalStoragePath.DIRECTORY_SEPARATOR.'framework'.DIRECTORY_SEPARATOR.'testing'.DIRECTORY_SEPARATOR.'runtime-log-'.bin2hex(random_bytes(4));

        File::ensureDirectoryExists($tempStoragePath.DIRECTORY_SEPARATOR.'logs');
        app()->useStoragePath($tempStoragePath);

        try {
            $suffix = 'runtime-boundary-'.bin2hex(random_bytes(4));
            file_put_contents(storage_path('logs/laravel.log'), '['.now()->format('Y-m-d H:i:s')."] local.ERROR: {$suffix}\n");

            $response = $this->getJson('/api/v2/admin/logs/runtime?keyword='.$suffix)
                ->assertOk()
                ->assertJsonPath('code', 0)
                ->assertJsonPath('data.total', 1)
                ->assertJsonPath('data.list.0.level', 'ERROR')
                ->assertJsonPath('data.list.0.message_excerpt', $suffix);

            $logId = (string) $response->json('data.list.0.id');

            $this->getJson('/api/v2/admin/logs/runtime/'.$logId)
                ->assertOk()
                ->assertJsonPath('code', 0)
                ->assertJsonPath('data.log.id', $logId)
                ->assertJsonPath('data.log.channel', 'runtime')
                ->assertJsonPath('data.log.source', 'laravel_log')
                ->assertJsonPath('data.log.message', $suffix);
        } finally {
            app()->useStoragePath($originalStoragePath);
            File::deleteDirectory($tempStoragePath);
            Cache::flush();
        }
    }

    public function test_runtime_log_route_id_is_stable_when_log_file_window_slides(): void
    {
        Cache::flush();
        $admin = $this->createAdminUser([AdminPermissions::LOG_LIST, AdminPermissions::PRIVACY_VIEW_RAW]);
        Sanctum::actingAs($admin);

        $originalStoragePath = app()->storagePath();
        $tempStoragePath = $originalStoragePath.DIRECTORY_SEPARATOR.'framework'.DIRECTORY_SEPARATOR.'testing'.DIRECTORY_SEPARATOR.'runtime-log-'.bin2hex(random_bytes(4));

        File::ensureDirectoryExists($tempStoragePath.DIRECTORY_SEPARATOR.'logs');
        app()->useStoragePath($tempStoragePath);

        try {
            // 写入超过 1600 行尾部窗口的日志，目标行位于最新位置
            $target = 'runtime-stable-'.bin2hex(random_bytes(4));
            $lines = [];
            for ($i = 0; $i < 1700; $i++) {
                $lines[] = '['.now()->subMinutes(1700 - $i)->format('Y-m-d H:i:s')."] local.INFO: runtime-line-{$i}\n";
            }
            $lines[1699] = '['.now()->format('Y-m-d H:i:s')."] local.INFO: {$target}\n";
            file_put_contents(storage_path('logs/laravel.log'), implode('', $lines));

            $listUrl = '/api/v2/admin/logs/runtime?keyword='.$target.'&include_summary=0&page_size=100';
            $targetId = (string) $this->getJson($listUrl)
                ->assertOk()
                ->assertJsonPath('code', 0)
                ->json('data.list.0.id');
            $this->assertNotEmpty($targetId);

            // 追加新日志行，模拟文件持续增长导致尾部窗口滑动；目标行的 ID 必须保持稳定。
            // 追加后需清空 stat 缓存，否则同一进程内 filesize() 返回旧值，服务端不会重新解析。
            $logPath = storage_path('logs/laravel.log');
            file_put_contents($logPath, '['.now()->addSecond()->format('Y-m-d H:i:s')."] local.INFO: newer-line\n", FILE_APPEND);
            clearstatcache(true, $logPath);

            $second = $this->getJson($listUrl)->assertOk()->assertJsonPath('code', 0);
            $second->assertJsonPath('data.list.0.id', $targetId);

            $this->getJson('/api/v2/admin/logs/runtime/'.$targetId)
                ->assertOk()
                ->assertJsonPath('code', 0)
                ->assertJsonPath('data.log.id', $targetId)
                ->assertJsonPath('data.log.channel', 'runtime')
                ->assertJsonPath('data.log.source', 'laravel_log')
                ->assertJsonPath('data.log.message', $target);
        } finally {
            app()->useStoragePath($originalStoragePath);
            File::deleteDirectory($tempStoragePath);
            Cache::flush();
        }
    }

    public function test_task_log_route_includes_schedule_run_logs(): void
    {
        $admin = $this->createAdminUser([AdminPermissions::LOG_LIST]);
        Sanctum::actingAs($admin);

        $taskName = 'boundary-task-'.bin2hex(random_bytes(4));
        DB::table('schedule_run_logs')->insert([
            'task_name' => $taskName,
            'status' => 'skipped',
            'duration_ms' => 15,
            'summary' => json_encode(['reason' => 'boundary'], JSON_THROW_ON_ERROR),
            'started_at' => now(),
            'finished_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->getJson('/api/v2/admin/logs/tasks?task_key='.$taskName.'&status=skipped')
            ->assertOk()
            ->assertJsonPath('code', 0)
            ->assertJsonPath('data.total', 1)
            ->assertJsonPath('data.list.0.source', 'schedule_run_logs')
            ->assertJsonPath('data.list.0.status', 'skipped')
            ->assertJsonPath('data.list.0.duration_ms', 15);
    }

    public function test_activity_log_route_falls_back_to_business_operation_logs_with_actor_filter(): void
    {
        $admin = $this->createAdminUser([AdminPermissions::LOG_LIST]);
        Sanctum::actingAs($admin);

        $suffix = bin2hex(random_bytes(4));

        ActivityLog::query()->create([
            'actor_type' => 'admin',
            'actor_id' => (int) $admin->id,
            'actor_name' => (string) $admin->username,
            'action' => 'order.boundary.'.$suffix,
            'module' => 'order',
            'description' => '订单边界测试 '.$suffix,
            'subject_type' => 'order',
            'subject_id' => 1001,
            'context' => ['message' => '管理员处理订单 '.$suffix],
            'ip_address' => '127.0.0.1',
        ]);

        ActivityLog::query()->create([
            'actor_type' => 'client',
            'actor_id' => null,
            'actor_name' => 'client boundary '.$suffix,
            'action' => 'order.boundary.'.$suffix.'.client',
            'module' => 'order',
            'description' => '客户订单边界测试 '.$suffix,
            'subject_type' => 'order',
            'subject_id' => 1002,
            'context' => ['message' => '客户处理订单 '.$suffix],
            'ip_address' => '127.0.0.1',
        ]);

        $this->getJson("/api/v2/admin/logs/activity?keyword={$suffix}&actor_type=admin&subject_type=order")
            ->assertOk()
            ->assertJsonPath('code', 0)
            ->assertJsonPath('data.total', 1)
            ->assertJsonPath('data.list.0.actor_type', 'admin')
            ->assertJsonPath('data.list.0.module', 'order')
            ->assertJsonPath('data.list.0.subject_type', 'order')
            ->assertJsonPath('data.list.0.source', 'activity_log');
    }

    public function test_activity_log_keyword_filters_business_operation_logs_by_user_identity(): void
    {
        $admin = $this->createAdminUser([AdminPermissions::LOG_LIST, AdminPermissions::PRIVACY_VIEW_RAW]);
        Sanctum::actingAs($admin);

        $suffix = bin2hex(random_bytes(4));
        $client = User::query()->create([
            'email' => 'activity-user-'.$suffix.'@example.com',
            'password' => 'secret123',
            'phone' => '139'.str_pad((string) random_int(0, 99999999), 8, '0', STR_PAD_LEFT),
            'nickname' => '活动用户'.$suffix,
            'status' => 1,
        ]);

        ActivityLog::query()->create([
            'actor_type' => 'client',
            'actor_id' => (int) $client->id,
            'actor_name' => (string) $client->email,
            'action' => 'service.user-filter.client',
            'module' => 'service',
            'description' => '客户服务操作',
            'subject_type' => 'service',
            'subject_id' => 2001,
            'context' => [],
            'ip_address' => '127.0.0.1',
        ]);

        ActivityLog::query()->create([
            'actor_type' => 'admin',
            'actor_id' => (int) $admin->id,
            'actor_name' => (string) $admin->username,
            'action' => 'service.user-filter.admin',
            'module' => 'service',
            'description' => '管理员服务操作',
            'subject_type' => 'service',
            'subject_id' => 2002,
            'context' => [],
            'ip_address' => '127.0.0.1',
        ]);

        $this->getJson('/api/v2/admin/logs/activity?keyword='.rawurlencode((string) $client->email))
            ->assertOk()
            ->assertJsonPath('code', 0)
            ->assertJsonPath('data.total', 1)
            ->assertJsonPath('data.list.0.actor_type', 'client')
            ->assertJsonPath('data.list.0.actor_id', (int) $client->id)
            ->assertJsonPath('data.list.0.source', 'activity_log');
    }

    private function createAdminUser(array $permissions): AdminUser
    {
        $suffix = bin2hex(random_bytes(4));
        $role = Role::query()->create([
            'name' => 'log-boundary-role-'.$suffix,
            'label' => '日志边界角色',
            'permissions' => $permissions,
        ]);

        return AdminUser::query()->create([
            'username' => 'log-boundary-admin-'.$suffix,
            'password' => 'secret123',
            'nickname' => '日志边界管理员',
            'role_id' => (int) $role->id,
            'status' => 1,
        ]);
    }

    private function createLogPlugin(string $domain, string $slug, string $pluginKey): IntegrationPlugin
    {
        return IntegrationPlugin::query()->create([
            'domain' => $domain,
            'slug' => $slug.'-'.bin2hex(random_bytes(3)),
            'plugin_key' => $pluginKey.'-'.bin2hex(random_bytes(3)),
            'name' => 'Log Plugin '.$pluginKey,
            'entry_class' => 'Tests\\Support\\LogPlugin',
            'status' => IntegrationPlugin::STATUS_ENABLED,
            'installed_at' => now(),
        ]);
    }
}
