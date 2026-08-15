<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Exceptions\BusinessException;
use App\Services\System\DatabaseEngineeringService;
use App\Services\System\DatabaseStatusService;
use App\Services\System\OperationLogService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Mockery;
use Tests\TestCase;

class DatabaseStatusServiceTest extends TestCase
{
    protected function tearDown(): void
    {
        Cache::forget('system:database:optimize:last_completed_at');

        parent::tearDown();
    }

    public function test_automatic_optimization_skips_tables_without_material_fragmentation(): void
    {
        $engineering = Mockery::mock(DatabaseEngineeringService::class);
        $operationLogs = Mockery::mock(OperationLogService::class);

        $engineering->shouldReceive('optimizableTables')
            ->once()
            ->with(8 * 1024 * 1024, 0.10)
            ->andReturn([]);
        DB::shouldReceive('statement')->never();
        $operationLogs->shouldReceive('write')
            ->once()
            ->with(
                7,
                'admin',
                'database.optimize',
                'database',
                null,
                Mockery::on(static fn (array $detail): bool => $detail['selection_mode'] === 'fragmentation'
                    && $detail['candidate_tables'] === []
                    && $detail['optimized_tables'] === []),
                '127.0.0.1',
            );

        $result = (new DatabaseStatusService($engineering, $operationLogs))->optimize([], 7, '127.0.0.1');

        $this->assertSame('completed', $result['status']);
        $this->assertSame('未发现需要优化的数据表', $result['message']);
        $this->assertSame('fragmentation', $result['detail']['selection_mode']);
        $this->assertSame(0, $result['detail']['optimized_count']);
    }

    public function test_manual_optimization_keeps_the_explicit_table_selection(): void
    {
        $engineering = Mockery::mock(DatabaseEngineeringService::class);
        $operationLogs = Mockery::mock(OperationLogService::class);

        $engineering->shouldReceive('baseTables')->once()->andReturn(['operation_logs']);
        $engineering->shouldNotReceive('optimizableTables');
        DB::shouldReceive('statement')->once()->with('OPTIMIZE TABLE `operation_logs`');
        $operationLogs->shouldReceive('write')
            ->once()
            ->with(
                null,
                'admin',
                'database.optimize',
                'database',
                null,
                Mockery::on(static fn (array $detail): bool => $detail['selection_mode'] === 'manual'
                    && $detail['candidate_tables'] === ['operation_logs']
                    && $detail['optimized_tables'] === ['operation_logs']),
                null,
            );

        $result = (new DatabaseStatusService($engineering, $operationLogs))->optimize(['operation_logs']);

        $this->assertSame('已优化 1 张数据表', $result['message']);
        $this->assertSame('manual', $result['detail']['selection_mode']);
        $this->assertSame(['operation_logs'], $result['detail']['optimized_tables']);
    }

    public function test_status_reports_candidates_estimated_reclaimable_space_and_cooldown(): void
    {
        Cache::put('system:database:optimize:last_completed_at', now()->timestamp, 30 * 60);

        $engineering = Mockery::mock(DatabaseEngineeringService::class);
        $operationLogs = Mockery::mock(OperationLogService::class);

        $engineering->shouldReceive('tableSizeMetrics')->once()->andReturn([
            ['table_name' => 'operation_logs', 'table_rows' => 10, 'size_mb' => 12.5, 'update_time' => null],
        ]);
        $engineering->shouldReceive('optimizationCandidates')
            ->once()
            ->with(8 * 1024 * 1024, 0.10)
            ->andReturn([
                [
                    'table_name' => 'operation_logs',
                    'reclaimable_bytes' => 12 * 1024 * 1024,
                    'reclaimable_mb' => 12.0,
                    'fragmentation_ratio' => 0.24,
                ],
            ]);
        DB::shouldReceive('getDatabaseName')->once()->andReturn('idc_test');

        $result = (new DatabaseStatusService($engineering, $operationLogs))->status();

        $this->assertSame('idc_test', $result['database']);
        $this->assertSame(1, $result['optimization']['candidate_count']);
        $this->assertSame(12.0, $result['optimization']['estimated_reclaimable_mb']);
        $this->assertSame('operation_logs', $result['optimization']['candidates'][0]['name']);
        $this->assertSame(12.0, $result['optimization']['candidates'][0]['reclaimable_mb']);
        $this->assertGreaterThan(0, $result['optimization']['cooldown_remaining_seconds']);
        $this->assertNotNull($result['optimization']['last_optimized_at']);
    }

    public function test_automatic_optimization_respects_the_cooldown_window(): void
    {
        Cache::put('system:database:optimize:last_completed_at', now()->timestamp, 30 * 60);

        $engineering = Mockery::mock(DatabaseEngineeringService::class);
        $operationLogs = Mockery::mock(OperationLogService::class);
        $engineering->shouldNotReceive('optimizableTables');
        $operationLogs->shouldNotReceive('write');

        try {
            (new DatabaseStatusService($engineering, $operationLogs))->optimize();
            $this->fail('Expected the optimization cooldown to prevent another automatic run.');
        } catch (BusinessException $exception) {
            $this->assertSame(42900, $exception->getErrorCode());
            $this->assertSame(429, $exception->getCode());
        }
    }

    public function test_optimization_lock_rejects_a_concurrent_request(): void
    {
        $lock = Cache::lock('system:database:optimize:lock', 60);
        $this->assertTrue($lock->get());

        $engineering = Mockery::mock(DatabaseEngineeringService::class);
        $operationLogs = Mockery::mock(OperationLogService::class);
        $engineering->shouldNotReceive('optimizableTables');
        $operationLogs->shouldNotReceive('write');

        try {
            (new DatabaseStatusService($engineering, $operationLogs))->optimize();
            $this->fail('Expected the database optimization lock to reject a concurrent run.');
        } catch (BusinessException $exception) {
            $this->assertSame(40900, $exception->getErrorCode());
            $this->assertSame(409, $exception->getCode());
        } finally {
            $lock->release();
        }
    }
}
