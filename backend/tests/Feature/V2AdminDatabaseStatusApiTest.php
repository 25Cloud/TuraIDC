<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Exceptions\BusinessException;
use App\Models\AdminUser;
use App\Models\Role;
use App\Services\System\DatabaseStatusService;
use App\Support\AdminPermissions;
use Laravel\Sanctum\Sanctum;
use Mockery\MockInterface;
use Tests\TestCase;

class V2AdminDatabaseStatusApiTest extends TestCase
{
    public function test_database_status_requires_permission_rejects_per_page_and_returns_whitelist(): void
    {
        $this->mock(DatabaseStatusService::class, function (MockInterface $mock): void {
            $mock->shouldReceive('status')
                ->once()
                ->andReturn([
                    'database' => 'idc_test',
                    'list' => [
                        [
                            'name' => 'users',
                            'rows' => 12,
                            'size_mb' => 1.25,
                            'update_time' => '2026-07-17 10:00:00',
                            'secret' => 'must-not-leak',
                        ],
                    ],
                    'total_count' => 1,
                    'total_rows' => 12,
                    'total_size_mb' => 1.25,
                    'optimization' => [
                        'candidate_count' => 1,
                        'estimated_reclaimable_mb' => 8.5,
                        'candidates' => [
                            [
                                'name' => 'users',
                                'reclaimable_mb' => 8.5,
                                'fragmentation_ratio' => 0.25,
                            ],
                        ],
                        'cooldown_remaining_seconds' => 120,
                        'last_optimized_at' => '2026-07-20 10:00:00',
                    ],
                    'raw_response' => 'must-not-leak',
                ]);
        });

        $this->getJson('/api/v2/admin/database/status')
            ->assertUnauthorized()
            ->assertJsonPath('code', 40100);

        Sanctum::actingAs($this->createAdmin([AdminPermissions::SETTINGS_VIEW]));

        $this->getJson('/api/v2/admin/database/status')
            ->assertForbidden()
            ->assertJsonPath('code', 40300);

        Sanctum::actingAs($this->createAdmin([AdminPermissions::DATABASE_VIEW]));

        $this->getJson('/api/v2/admin/database/status?per_page=20')
            ->assertUnprocessable()
            ->assertJsonPath('code', 42200)
            ->assertJsonStructure(['data' => ['errors' => ['per_page']]]);

        $response = $this->getJson('/api/v2/admin/database/status')
            ->assertOk()
            ->assertJsonPath('code', 0)
            ->assertJsonPath('data.database', 'idc_test')
            ->assertJsonPath('data.list.0.name', 'users')
            ->assertJsonPath('data.optimization.candidate_count', 1)
            ->assertJsonPath('data.optimization.candidates.0.reclaimable_mb', 8.5)
            ->assertJsonMissingPath('data.raw_response')
            ->assertJsonMissingPath('data.list.0.secret');

        $this->assertSame(
            ['database', 'list', 'total_count', 'total_rows', 'total_size_mb', 'optimization'],
            array_keys($response->json('data'))
        );
        $this->assertSame(
            ['name', 'rows', 'size_mb', 'update_time'],
            array_keys($response->json('data.list.0'))
        );
        $this->assertSame(
            ['candidate_count', 'estimated_reclaimable_mb', 'candidates', 'cooldown_remaining_seconds', 'last_optimized_at'],
            array_keys($response->json('data.optimization'))
        );
        $this->assertSame(
            ['name', 'reclaimable_mb', 'fragmentation_ratio'],
            array_keys($response->json('data.optimization.candidates.0'))
        );
        $this->assertNoSensitiveKeys($response->json());
        $this->assertLessThan(100 * 1024, strlen((string) $response->getContent()));
    }

    public function test_database_optimize_requires_manage_permission_and_returns_action_result(): void
    {
        $this->mock(DatabaseStatusService::class, function (MockInterface $mock): void {
            $mock->shouldReceive('optimize')
                ->once()
                ->with(['users'], \Mockery::type('int'), \Mockery::any())
                ->andReturn([
                    'id' => 'database-optimize',
                    'status' => 'completed',
                    'message' => '已优化 1 张数据表',
                    'detail' => [
                        'optimized_count' => 1,
                        'failed_count' => 0,
                        'optimized_tables' => ['users'],
                        'failed_tables' => [],
                    ],
                ]);
        });

        Sanctum::actingAs($this->createAdmin([AdminPermissions::DATABASE_VIEW]));

        $this->postJson('/api/v2/admin/database/optimizations', ['tables' => ['users']])
            ->assertForbidden()
            ->assertJsonPath('code', 40300);

        Sanctum::actingAs($this->createAdmin([AdminPermissions::DATABASE_MANAGE]));

        $this->postJson('/api/v2/admin/database/optimizations?per_page=20', ['tables' => ['users']])
            ->assertUnprocessable()
            ->assertJsonPath('code', 42200)
            ->assertJsonStructure(['data' => ['errors' => ['per_page']]]);

        $response = $this->postJson('/api/v2/admin/database/optimizations', ['tables' => ['users']])
            ->assertOk()
            ->assertJsonPath('code', 0)
            ->assertJsonPath('data.id', 'database-optimize')
            ->assertJsonPath('data.status', 'completed')
            ->assertJsonPath('data.detail.optimized_count', 1);

        $this->assertSame(['id', 'status', 'message', 'detail'], array_keys($response->json('data')));
        $this->assertSame(
            ['optimized_count', 'failed_count', 'optimized_tables', 'failed_tables'],
            array_keys($response->json('data.detail'))
        );
        $this->assertNoSensitiveKeys($response->json());
        $this->assertLessThan(100 * 1024, strlen((string) $response->getContent()));
    }

    public function test_database_optimize_returns_a_conflict_when_another_maintenance_run_holds_the_lock(): void
    {
        $this->mock(DatabaseStatusService::class, function (MockInterface $mock): void {
            $mock->shouldReceive('optimize')
                ->once()
                ->andThrow(new BusinessException('数据库优化正在进行，请勿重复提交', 40900, 409));
        });

        Sanctum::actingAs($this->createAdmin([AdminPermissions::DATABASE_MANAGE]));

        $this->postJson('/api/v2/admin/database/optimizations')
            ->assertConflict()
            ->assertJsonPath('code', 40900)
            ->assertJsonPath('message', '数据库优化正在进行，请勿重复提交');
    }

    public function test_database_backup_requires_manage_permission_and_downloads_file(): void
    {
        $path = storage_path('app/private/database-backups/test_backup.sql');
        if (! is_dir(dirname($path))) {
            mkdir(dirname($path), 0750, true);
        }
        file_put_contents($path, "-- test backup\n");

        $this->mock(DatabaseStatusService::class, function (MockInterface $mock) use ($path): void {
            $mock->shouldReceive('createBackup')
                ->once()
                ->with(\Mockery::type('int'), \Mockery::any())
                ->andReturn([
                    'absolute_path' => $path,
                    'filename' => 'test_backup.sql',
                    'size_bytes' => filesize($path) ?: 0,
                ]);
        });

        Sanctum::actingAs($this->createAdmin([AdminPermissions::DATABASE_VIEW]));

        $this->postJson('/api/v2/admin/database/backups')
            ->assertForbidden()
            ->assertJsonPath('code', 40300);

        Sanctum::actingAs($this->createAdmin([AdminPermissions::DATABASE_MANAGE]));

        $this->postJson('/api/v2/admin/database/backups?per_page=20')
            ->assertUnprocessable()
            ->assertJsonPath('code', 42200)
            ->assertJsonStructure(['data' => ['errors' => ['per_page']]]);

        $response = $this->post('/api/v2/admin/database/backups')
            ->assertOk()
            ->assertHeader('content-disposition');

        $this->assertStringContainsString('test_backup.sql', (string) $response->headers->get('content-disposition'));
        $this->assertStringContainsString('-- test backup', $response->streamedContent());
    }

    /**
     * @param  list<string>  $permissions
     */
    private function createAdmin(array $permissions): AdminUser
    {
        $suffix = bin2hex(random_bytes(4));
        $role = Role::query()->create([
            'name' => 'v2-database-'.$suffix,
            'label' => 'V2 Database',
            'permissions' => $permissions,
        ]);

        return AdminUser::query()->create([
            'username' => 'v2-database-'.$suffix,
            'password' => 'Temp@123456',
            'role_id' => (int) $role->id,
            'nickname' => 'V2 Database',
            'email' => 'v2-database-'.$suffix.'@example.com',
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
                $normalized = strtolower($key);
                $this->assertFalse(
                    str_contains($normalized, 'password')
                    || str_contains($normalized, 'secret')
                    || str_contains($normalized, 'token')
                    || str_contains($normalized, 'api_key')
                    || str_contains($normalized, 'raw_response'),
                    "Unexpected sensitive key [{$key}] in response payload."
                );
            }

            $this->assertNoSensitiveKeys($value);
        }
    }
}
