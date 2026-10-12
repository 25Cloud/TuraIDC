<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\AdminUser;
use App\Models\Role;
use App\Services\System\RedisStatusService;
use App\Support\AdminPermissions;
use Laravel\Sanctum\Sanctum;
use Mockery\MockInterface;
use Tests\TestCase;

class V2AdminRedisStatusApiTest extends TestCase
{
    public function test_redis_status_requires_permission_rejects_per_page_and_returns_whitelist(): void
    {
        $this->mock(RedisStatusService::class, function (MockInterface $mock): void {
            $mock->shouldReceive('status')
                ->once()
                ->andReturn([
                    'available' => true,
                    'client' => 'phpredis',
                    'prefix' => 'turaidc_',
                    'cache_store' => 'redis',
                    'connections' => [
                        [
                            'name' => 'default',
                            'database' => 0,
                            'connected' => true,
                            'message' => '正常',
                        ],
                    ],
                    'server' => ['redis_version' => '7.2.5'],
                    'keyspace' => [
                        ['database' => '0', 'keys' => 128, 'expires' => 12, 'avg_ttl' => 0],
                    ],
                    'memory' => ['used_memory_human' => '12.5M', 'mem_fragmentation_ratio' => 1.02],
                    'stats' => ['uptime_in_days' => 3, 'connected_clients' => 5, 'hit_rate' => 0.95],
                    // 连接密码绝不能出现在响应里。
                    'password' => 'must-not-leak',
                    'raw_response' => 'must-not-leak',
                ]);
        });

        $this->getJson('/api/v2/admin/redis/status')
            ->assertUnauthorized()
            ->assertJsonPath('code', 40100);

        Sanctum::actingAs($this->createAdmin([AdminPermissions::SETTINGS_VIEW]));

        $this->getJson('/api/v2/admin/redis/status')
            ->assertForbidden()
            ->assertJsonPath('code', 40300);

        Sanctum::actingAs($this->createAdmin([AdminPermissions::DATABASE_VIEW]));

        $this->getJson('/api/v2/admin/redis/status?per_page=20')
            ->assertUnprocessable()
            ->assertJsonPath('code', 42200)
            ->assertJsonStructure(['data' => ['errors' => ['per_page']]]);

        $response = $this->getJson('/api/v2/admin/redis/status')
            ->assertOk()
            ->assertJsonPath('code', 0);

        $this->assertSame([
            'available',
            'client',
            'prefix',
            'cache_store',
            'connections',
            'server',
            'keyspace',
            'memory',
            'stats',
        ], array_keys($response->json('data')));

        $this->assertTrue($response->json('data.available'));
        $this->assertSame('phpredis', $response->json('data.client'));
        $this->assertNoSensitiveKeys($response->json());
    }

    public function test_redis_cache_flush_requires_manage_permission_and_returns_action_result(): void
    {
        $this->mock(RedisStatusService::class, function (MockInterface $mock): void {
            $mock->shouldReceive('flushCache')
                ->once()
                ->andReturn([
                    'id' => 'redis-cache-flush',
                    'status' => 'completed',
                    'message' => '缓存已清理',
                    'detail' => ['cleared' => true, 'database' => 1],
                ]);
        });

        Sanctum::actingAs($this->createAdmin([AdminPermissions::DATABASE_VIEW]));

        $this->postJson('/api/v2/admin/redis/cache/flush')
            ->assertForbidden()
            ->assertJsonPath('code', 40300);

        Sanctum::actingAs($this->createAdmin([AdminPermissions::DATABASE_MANAGE]));

        $this->postJson('/api/v2/admin/redis/cache/flush?per_page=20')
            ->assertUnprocessable()
            ->assertJsonPath('code', 42200)
            ->assertJsonStructure(['data' => ['errors' => ['per_page']]]);

        $this->postJson('/api/v2/admin/redis/cache/flush?database=1')
            ->assertUnprocessable()
            ->assertJsonPath('code', 42200)
            ->assertJsonStructure(['data' => ['errors' => ['database']]]);

        $this->postJson('/api/v2/admin/redis/cache/flush')
            ->assertOk()
            ->assertJsonPath('code', 0)
            ->assertJsonPath('data.status', 'completed');
    }

    /**
     * 生产验证发现：Laravel Connection::command() 第二个参数必须是数组。
     * 传字符串会在 phpredis 驱动下抛 TypeError，并被 catch 静默吞掉，
     * 导致版本、内存、键空间、命中率在页面上全部为空且没有任何报错。
     */
    public function test_it_passes_info_section_as_array_so_phpredis_driver_returns_metrics(): void
    {
        $connection = \Mockery::mock(\Illuminate\Redis\Connections\PhpRedisConnection::class);
        $connection->shouldReceive('command')->with('ping')->andReturn(true);
        $connection->shouldReceive('command')
            ->with('info', ['server'])
            ->once()
            ->andReturn(['redis_version' => '7.2.5', 'os' => 'Linux']);
        $connection->shouldReceive('command')
            ->with('info', ['keyspace'])
            ->once()
            ->andReturn(['db0' => 'keys=12,expires=3,avg_ttl=0']);
        $connection->shouldReceive('command')
            ->with('info', ['memory'])
            ->once()
            ->andReturn(['used_memory_human' => '12.5M', 'mem_fragmentation_ratio' => '1.02']);
        $connection->shouldReceive('command')
            ->with('info', ['stats'])
            ->once()
            ->andReturn([
                'uptime_in_days' => '3',
                'connected_clients' => '5',
                'keyspace_hits' => '95',
                'keyspace_misses' => '5',
            ]);

        \Illuminate\Support\Facades\Redis::shouldReceive('connection')->andReturn($connection);

        $status = app(RedisStatusService::class)->status();

        $this->assertSame('7.2.5', $status['server']['redis_version'] ?? null);
        $this->assertSame('12.5M', $status['memory']['used_memory_human'] ?? null);
        $this->assertSame(0.95, $status['stats']['hit_rate'] ?? null);
        $this->assertSame([[
            'database' => '0',
            'keys' => 12,
            'expires' => 3,
            'avg_ttl' => 0,
        ]], $status['keyspace']);
    }

    /**
     * @param  list<string>  $permissions
     */
    private function createAdmin(array $permissions): AdminUser
    {
        $suffix = bin2hex(random_bytes(4));
        $role = Role::query()->create([
            'name' => 'v2-redis-'.$suffix,
            'label' => 'V2 Redis',
            'permissions' => $permissions,
        ]);

        return AdminUser::query()->create([
            'username' => 'v2-redis-'.$suffix,
            'password' => 'Temp@123456',
            'role_id' => (int) $role->id,
            'nickname' => 'V2 Redis',
            'email' => 'v2-redis-'.$suffix.'@example.com',
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
