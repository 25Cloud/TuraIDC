<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\AdminUser;
use App\Models\Role;
use App\Services\Automation\ScheduleTaskTriggerService;
use App\Services\Content\MediaFileService;
use App\Services\System\AdminLogService;
use App\Support\AdminPermissions;
use Laravel\Sanctum\Sanctum;
use Mockery\MockInterface;
use RuntimeException;
use Tests\TestCase;

class V2AdminOperationalActionApiTest extends TestCase
{
    public function test_log_cleanup_overview_requires_log_list_and_rejects_per_page(): void
    {
        $this->mock(AdminLogService::class, function (MockInterface $mock): void {
            $mock->shouldReceive('getCleanupOverview')
                ->once()
                ->andReturn([
                    'database' => ['api' => 10, 'sms' => 2],
                    'file' => [
                        'path' => 'storage/logs/laravel.log',
                        'exists' => true,
                        'size_bytes' => 128,
                    ],
                    'supported_cleanup_types' => [
                        ['value' => 'api', 'label' => 'API日志'],
                    ],
                ]);
        });

        $this->getJson('/api/v2/admin/log-cleanups/overview')
            ->assertUnauthorized()
            ->assertJsonPath('code', 40100);

        Sanctum::actingAs($this->createAdmin([AdminPermissions::CONTENT_LIST]));

        $this->getJson('/api/v2/admin/log-cleanups/overview')
            ->assertForbidden()
            ->assertJsonPath('code', 40300);

        Sanctum::actingAs($this->createAdmin([AdminPermissions::LOG_LIST]));

        $this->getJson('/api/v2/admin/log-cleanups/overview?per_page=20')
            ->assertUnprocessable()
            ->assertJsonPath('code', 42200)
            ->assertJsonStructure(['data' => ['errors' => ['per_page']]]);

        $response = $this->getJson('/api/v2/admin/log-cleanups/overview')
            ->assertOk()
            ->assertJsonPath('code', 0)
            ->assertJsonPath('data.database.api', 10)
            ->assertJsonPath('data.file.path', 'storage/logs/laravel.log');

        $this->assertNoSensitiveKeys($response->json());
        $this->assertLessThan(100 * 1024, strlen((string) $response->getContent()));
    }

    public function test_log_cleanup_action_validates_payload_and_returns_compact_result(): void
    {
        $this->mock(AdminLogService::class, function (MockInterface $mock): void {
            $mock->shouldReceive('cleanup')
                ->once()
                ->withArgs(fn (array $payload): bool => ($payload['type'] ?? null) === 'api'
                    && (int) ($payload['keep_days'] ?? 0) === 30
                    && ($payload['confirm_text'] ?? null) === '立即清理')
                ->andReturn([
                    'type' => 'api',
                    'keep_days' => 30,
                    'cutoff_at' => '2026-07-05 00:00:00',
                    'affected' => ['api' => 3],
                    'raw_response' => 'must-not-leak',
                ]);
        });

        Sanctum::actingAs($this->createAdmin([AdminPermissions::LOG_LIST]));

        $this->postJson('/api/v2/admin/log-cleanups', [
            'type' => 'api',
            'keep_days' => 30,
            'confirm_text' => '立即清理',
        ])
            ->assertForbidden()
            ->assertJsonPath('code', 40300);

        Sanctum::actingAs($this->createAdmin([AdminPermissions::LOG_MANAGE]));

        $this->postJson('/api/v2/admin/log-cleanups', [
            'type' => 'api',
            'keep_days' => 30,
            'confirm_text' => '立即清理',
            'per_page' => 20,
        ])
            ->assertUnprocessable()
            ->assertJsonPath('code', 42200)
            ->assertJsonStructure(['data' => ['errors' => ['per_page']]]);

        $response = $this->postJson('/api/v2/admin/log-cleanups', [
            'type' => 'api',
            'keep_days' => 30,
            'confirm_text' => '立即清理',
        ])
            ->assertOk()
            ->assertJsonPath('code', 0)
            ->assertJsonPath('data.status', 'completed')
            ->assertJsonPath('data.detail.type', 'log_cleanup')
            ->assertJsonPath('data.detail.cleanup.target', 'api')
            ->assertJsonPath('data.detail.cleanup.affected.api', 3)
            ->assertJsonMissingPath('data.detail.raw_response');

        $this->assertActionResponse($response->json());
    }

    public function test_schedule_trigger_action_validates_payload_and_returns_compact_result(): void
    {
        $this->mock(ScheduleTaskTriggerService::class, function (MockInterface $mock): void {
            $mock->shouldReceive('dispatch')
                ->once()
                ->withArgs(fn (string $task, ?int $adminUserId): bool => $task === 'billing-maintenance' && $adminUserId !== null)
                ->andReturn([
                    'task' => 'billing-maintenance',
                    'title' => '账单自动化维护',
                    'execution_mode' => 'queue',
                    'secret' => 'must-not-leak',
                ]);
        });

        Sanctum::actingAs($this->createAdmin([AdminPermissions::SCHEDULE_VIEW]));

        $this->postJson('/api/v2/admin/schedule-triggers', ['task' => 'billing-maintenance'])
            ->assertForbidden()
            ->assertJsonPath('code', 40300);

        Sanctum::actingAs($this->createAdmin([AdminPermissions::SCHEDULE_TRIGGER]));

        $this->postJson('/api/v2/admin/schedule-triggers', ['per_page' => 20])
            ->assertUnprocessable()
            ->assertJsonPath('code', 42200)
            ->assertJsonStructure(['data' => ['errors' => ['task', 'per_page']]]);

        $response = $this->postJson('/api/v2/admin/schedule-triggers', [
            'task' => 'billing-maintenance',
        ])
            ->assertOk()
            ->assertJsonPath('code', 0)
            ->assertJsonPath('data.id', 'billing-maintenance')
            ->assertJsonPath('data.status', 'queued')
            ->assertJsonPath('data.detail.task.execution_mode', 'queue')
            ->assertJsonMissingPath('data.detail.secret');

        $this->assertActionResponse($response->json());
    }

    public function test_schedule_trigger_maps_runtime_failures_to_a_safe_api_error(): void
    {
        $this->mock(ScheduleTaskTriggerService::class, function (MockInterface $mock): void {
            $mock->shouldReceive('dispatch')
                ->once()
                ->andThrow(new RuntimeException('queue password must not leak'));
        });

        Sanctum::actingAs($this->createAdmin([AdminPermissions::SCHEDULE_TRIGGER]));

        $this->postJson('/api/v2/admin/schedule-triggers', [
            'task' => 'billing-maintenance',
        ])
            ->assertStatus(500)
            ->assertJsonPath('code', 50000)
            ->assertJsonPath('message', '定时任务暂时不可触发，请稍后重试')
            ->assertJsonMissing(['message' => 'queue password must not leak']);
    }

    public function test_schedule_trigger_maps_errors_from_plugin_extensions_to_a_safe_api_error(): void
    {
        $this->mock(ScheduleTaskTriggerService::class, function (MockInterface $mock): void {
            $mock->shouldReceive('dispatch')
                ->once()
                ->andThrow(new \Error('plugin path and token must not leak'));
        });

        Sanctum::actingAs($this->createAdmin([AdminPermissions::SCHEDULE_TRIGGER]));

        $this->postJson('/api/v2/admin/schedule-triggers', [
            'task' => 'billing-maintenance',
        ])
            ->assertStatus(500)
            ->assertJsonPath('code', 50000)
            ->assertJsonPath('message', '定时任务暂时不可触发，请稍后重试')
            ->assertJsonMissing(['message' => 'plugin path and token must not leak']);
    }

    public function test_media_reindex_action_requires_content_manage_and_returns_compact_result(): void
    {
        $this->mock(MediaFileService::class, function (MockInterface $mock): void {
            $mock->shouldReceive('reindexMediaDirectory')
                ->once()
                ->withArgs(fn (int $adminUserId): bool => $adminUserId > 0)
                ->andReturn([
                    'created' => 2,
                    'skipped' => 5,
                    'total' => 7,
                    'api_key' => 'must-not-leak',
                ]);
        });

        Sanctum::actingAs($this->createAdmin([AdminPermissions::CONTENT_LIST]));

        $this->postJson('/api/v2/admin/media-file-reindexes')
            ->assertForbidden()
            ->assertJsonPath('code', 40300);

        Sanctum::actingAs($this->createAdmin([AdminPermissions::CONTENT_MANAGE]));

        $this->postJson('/api/v2/admin/media-file-reindexes', ['per_page' => 20])
            ->assertUnprocessable()
            ->assertJsonPath('code', 42200)
            ->assertJsonStructure(['data' => ['errors' => ['per_page']]]);

        $response = $this->postJson('/api/v2/admin/media-file-reindexes')
            ->assertOk()
            ->assertJsonPath('code', 0)
            ->assertJsonPath('data.status', 'completed')
            ->assertJsonPath('data.detail.media.created', 2)
            ->assertJsonPath('data.detail.media.total', 7)
            ->assertJsonMissingPath('data.detail.api_key');

        $this->assertActionResponse($response->json());
    }

    /**
     * @param  list<string>  $permissions
     */
    private function createAdmin(array $permissions): AdminUser
    {
        $suffix = bin2hex(random_bytes(4));
        $role = Role::query()->create([
            'name' => 'v2-operational-action-'.$suffix,
            'label' => 'V2 Operational Action',
            'permissions' => $permissions,
        ]);

        return AdminUser::query()->create([
            'username' => 'v2-operational-action-'.$suffix,
            'password' => 'Temp@123456',
            'role_id' => (int) $role->id,
            'nickname' => 'V2 Operational Action',
            'email' => 'v2-operational-action-'.$suffix.'@example.com',
            'status' => 1,
        ]);
    }

    private function assertActionResponse(array $payload): void
    {
        $this->assertSame(['id', 'status', 'message', 'detail'], array_keys($payload['data']));
        $this->assertNoSensitiveKeys($payload);
        $this->assertLessThan(100 * 1024, strlen((string) json_encode($payload, JSON_UNESCAPED_UNICODE)));
    }

    private function assertNoSensitiveKeys(mixed $payload): void
    {
        if (! is_array($payload)) {
            return;
        }

        foreach ($payload as $key => $value) {
            if (is_string($key)) {
                foreach (['password', 'secret', 'api_key', 'raw_response', 'third_party_response', 'token'] as $needle) {
                    $this->assertStringNotContainsString($needle, strtolower($key));
                }
            }

            $this->assertNoSensitiveKeys($value);
        }
    }
}
