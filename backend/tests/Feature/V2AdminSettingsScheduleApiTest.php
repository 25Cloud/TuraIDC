<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\AdminUser;
use App\Models\Role;
use App\Models\Setting;
use App\Services\Automation\ScheduleTaskService;
use App\Support\AdminPermissions;
use Laravel\Sanctum\Sanctum;
use Mockery\MockInterface;
use Tests\TestCase;

class V2AdminSettingsScheduleApiTest extends TestCase
{
    public function test_settings_update_uses_manage_permission_and_compact_action_response(): void
    {
        Setting::setValue('system', 'provision_hostname_prefix', 'v2old');

        $this->postJson('/api/v2/admin/settings', [
            'group' => 'system',
            'settings' => ['provision_hostname_prefix' => 'v2saved'],
        ])
            ->assertUnauthorized()
            ->assertJsonPath('code', 40100);

        Sanctum::actingAs($this->createAdmin([AdminPermissions::SETTINGS_VIEW]));

        $this->postJson('/api/v2/admin/settings', [
            'group' => 'system',
            'settings' => ['provision_hostname_prefix' => 'v2saved'],
        ])
            ->assertForbidden()
            ->assertJsonPath('code', 40300);

        Sanctum::actingAs($this->createAdmin([AdminPermissions::SETTINGS_MANAGE]));

        $this->postJson('/api/v2/admin/settings?per_page=20', [
            'group' => 'system',
            'settings' => ['provision_hostname_prefix' => 'v2saved'],
            'pageSize' => 20,
        ])
            ->assertUnprocessable()
            ->assertJsonPath('code', 42200)
            ->assertJsonStructure(['data' => ['errors' => ['per_page', 'pageSize']]]);

        $response = $this->postJson('/api/v2/admin/settings', [
            'group' => 'system',
            'settings' => ['provision_hostname_prefix' => 'v2saved'],
        ])
            ->assertOk()
            ->assertJsonPath('code', 0)
            ->assertJsonPath('message', '配置已更新')
            ->assertJsonPath('data.id', 'settings')
            ->assertJsonPath('data.status', 'completed')
            ->assertJsonPath('data.detail.group', 'system');

        $this->assertSame('v2saved', Setting::getValue('system', 'provision_hostname_prefix'));
        $this->assertSame(['id', 'status', 'message', 'detail'], array_keys($response->json('data')));
        $this->assertNoSensitiveKeys($response->json());
        $this->assertLessThan(100 * 1024, strlen((string) $response->getContent()));
    }

    public function test_schedule_overview_uses_view_permission_and_removes_large_or_sensitive_fields(): void
    {
        $this->mock(ScheduleTaskService::class, function (MockInterface $mock): void {
            $mock->shouldReceive('overview')
                ->once()
                ->andReturn([
                    'environment' => [
                        'app_env' => 'testing',
                        'app_timezone' => 'Asia/Shanghai',
                        'php_binary' => 'C:\\php\\php.exe',
                        'artisan_path' => 'C:\\app\\artisan',
                        'schedule_source' => 'C:\\app\\routes\\console.php',
                        'queue_driver' => 'database',
                        'business_queue' => 'provision,referral,notification,coupon,default',
                        'automation_queue' => 'automation',
                        'jobs_table_ready' => true,
                        'failed_jobs_table_ready' => true,
                        'pending_jobs' => 2,
                        'failed_jobs' => 1,
                        'queue_runtime_mode' => 'database_queue_drain_command',
                        'schedule_mutex' => ['enabled' => true, 'cache_store' => 'array'],
                        'automation_config' => ['status' => 'loaded'],
                    ],
                    'commands' => [
                        ['command' => 'must-not-return'],
                    ],
                    'runs_summary' => [
                        'active' => 1,
                        'stale' => 0,
                        'failed_24h' => 0,
                        'success_24h' => 12,
                        'manual_retry_24h' => 0,
                    ],
                    'tasks' => [
                        [
                            'key' => 'billing-maintenance',
                            'title' => '账单自动化维护',
                            'category' => 'billing',
                            'description' => 'run maintenance',
                            'manual_triggerable' => true,
                            'expression' => '每小时',
                            'summary' => '每小时',
                            'timezone' => 'Asia/Shanghai',
                            'next_run_at' => '2026-07-05 10:00:00',
                            'without_overlapping' => true,
                            'run_in_background' => false,
                            'overlap_expires_minutes' => 5,
                            'last_log' => [
                                'time' => '2026-07-05 09:00:00',
                                'level' => 'INFO',
                                'message' => 'done',
                                'task_key' => 'billing-maintenance',
                                'status' => 'success',
                                'duration_ms' => 12,
                                'summary' => [
                                    'processed' => 1,
                                    'api_key' => 'must-not-leak',
                                    'raw_response' => 'must-not-leak',
                                ],
                                'error_msg' => 'token=must-not-leak',
                            ],
                        ],
                    ],
                    'recent_logs' => [
                        [
                            'time' => '2026-07-05 09:00:00',
                            'level' => 'ERROR',
                            'message' => 'failed',
                            'task_key' => 'billing-maintenance',
                            'status' => 'failed',
                            'duration_ms' => 14,
                            'summary' => [
                                'third_party_response' => 'must-not-leak',
                                'ok' => true,
                            ],
                            'error_msg' => 'api_key=must-not-leak',
                        ],
                    ],
                    'settings_snapshot' => [
                        ['label' => '到期自动暂停', 'value' => '已开启', 'note' => str_repeat('x', 2000)],
                    ],
                ]);
        });

        $this->getJson('/api/v2/admin/schedules/overview')
            ->assertUnauthorized()
            ->assertJsonPath('code', 40100);

        Sanctum::actingAs($this->createAdmin([AdminPermissions::SETTINGS_VIEW]));

        $this->getJson('/api/v2/admin/schedules/overview')
            ->assertForbidden()
            ->assertJsonPath('code', 40300);

        Sanctum::actingAs($this->createAdmin([AdminPermissions::SCHEDULE_VIEW]));

        $this->getJson('/api/v2/admin/schedules/overview?per_page=20&pageSize=20')
            ->assertUnprocessable()
            ->assertJsonPath('code', 42200)
            ->assertJsonStructure(['data' => ['errors' => ['per_page', 'pageSize']]]);

        $response = $this->getJson('/api/v2/admin/schedules/overview')
            ->assertOk()
            ->assertJsonPath('code', 0)
            ->assertJsonPath('data.environment.app_env', 'testing')
            ->assertJsonPath('data.environment.business_queue', 'provision,referral,notification,coupon,default')
            ->assertJsonPath('data.environment.automation_queue', 'automation')
            ->assertJsonPath('data.tasks.0.key', 'billing-maintenance')
            ->assertJsonPath('data.tasks.0.source_type', 'system')
            ->assertJsonPath('data.tasks.0.source_label', '系统任务')
            ->assertJsonPath('data.tasks.0.last_log.summary.processed', 1)
            ->assertJsonPath('data.recent_logs.0.summary.ok', true)
            ->assertJsonMissingPath('data.commands')
            ->assertJsonMissingPath('data.environment.php_binary')
            ->assertJsonMissingPath('data.environment.artisan_path')
            ->assertJsonMissingPath('data.environment.schedule_source')
            ->assertJsonMissingPath('data.tasks.0.last_log.summary.api_key')
            ->assertJsonMissingPath('data.tasks.0.last_log.summary.raw_response')
            ->assertJsonMissingPath('data.recent_logs.0.summary.third_party_response');

        $content = (string) $response->getContent();

        $this->assertSame(['environment', 'tasks', 'runs_summary', 'recent_logs', 'settings_snapshot'], array_keys($response->json('data')));
        $this->assertIsInt($response->json('data.runs_summary.active'));
        $this->assertIsInt($response->json('data.runs_summary.stale'));
        $this->assertNoSensitiveKeys($response->json());
        $this->assertStringNotContainsString('must-not-leak', $content);
        $this->assertLessThan(100 * 1024, strlen($content));
    }

    public function test_automation_schedule_rejects_unrepresentable_periods_and_times(): void
    {
        Sanctum::actingAs($this->createAdmin([AdminPermissions::SETTINGS_MANAGE]));

        $this->postJson('/api/v2/admin/settings', [
            'group' => 'automation',
            'settings' => [
                'billing_maintenance_schedule_mode' => 'every_five_minutes',
            ],
        ])
            ->assertUnprocessable()
            ->assertJsonPath('code', 42200);

        $this->postJson('/api/v2/admin/settings', [
            'group' => 'automation',
            'settings' => [
                'billing_maintenance_schedule_mode' => 'hourly',
                'billing_maintenance_schedule_time' => '00:05:00',
            ],
        ])
            ->assertUnprocessable()
            ->assertJsonPath('code', 42200);

        $this->postJson('/api/v2/admin/settings', [
            'group' => 'automation',
            'settings' => [
                'billing_maintenance_schedule_mode' => 'hourly',
                'billing_maintenance_schedule_time' => '00:15:00',
            ],
        ])
            ->assertOk()
            ->assertJsonPath('code', 0);
    }

    /**
     * @param  list<string>  $permissions
     */
    private function createAdmin(array $permissions): AdminUser
    {
        $suffix = bin2hex(random_bytes(4));
        $role = Role::query()->create([
            'name' => 'v2-settings-schedule-'.$suffix,
            'label' => 'V2 Settings Schedule',
            'permissions' => $permissions,
        ]);

        return AdminUser::query()->create([
            'username' => 'v2-settings-schedule-'.$suffix,
            'password' => 'Temp@123456',
            'role_id' => (int) $role->id,
            'nickname' => 'V2 Settings Schedule',
            'email' => 'v2-settings-schedule-'.$suffix.'@example.com',
            'status' => 1,
        ]);
    }

    private function assertNoSensitiveKeys(mixed $payload): void
    {
        if (! is_array($payload)) {
            return;
        }

        foreach ($payload as $key => $value) {
            if (is_string($key)) {
                foreach (['password', 'secret', 'api_key', 'raw_response', 'third_party_response'] as $needle) {
                    $this->assertStringNotContainsString($needle, strtolower($key));
                }
            }

            $this->assertNoSensitiveKeys($value);
        }
    }
}
