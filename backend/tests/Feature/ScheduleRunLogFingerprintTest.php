<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Services\Automation\Heartbeat\ScheduleTaskRunRepository;
use App\Services\System\ScheduleRunLogService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use Tests\TestCase;

/**
 * 定时任务运行日志：指纹去重、并发一致性、失败与跳过语义。
 *
 * 关注点不是「日志写得漂亮」，而是三件事故级的事：
 * 1. 同一次「任务 + 槽位」不能被记成多条运行；
 * 2. 并发触发不能出现两条「进行中」；
 * 3. 「上一轮还在跑」不能记成失败（否则失败率与告警全部失真）。
 */
class ScheduleRunLogFingerprintTest extends TestCase
{
    use DatabaseTransactions;

    private const TASK_KEY = 'fingerprint-test-task';

    private const SLOT = '2026-11-06 10:00:00';

    protected function setUp(): void
    {
        parent::setUp();

        if (! Schema::hasColumn('schedule_run_logs', 'fingerprint')) {
            $this->markTestSkipped('schedule_run_logs 缺少 fingerprint 列，请先执行迁移');
        }
    }

    public function test_same_task_and_slot_reuses_one_row_and_counts_attempts(): void
    {
        $service = app(ScheduleRunLogService::class);

        $service->record('指纹测试任务', fn (): array => ['ok' => 1], $this->context());
        $service->record('指纹测试任务', fn (): array => ['ok' => 2], $this->context());

        $rows = DB::table('schedule_run_logs')
            ->where('task_key', self::TASK_KEY)
            ->get();

        // 指纹去重：两次执行只留一行，第二次是复用（attempts=2）
        $this->assertCount(1, $rows);

        $row = $rows->first();
        $this->assertSame('success', (string) $row->status);
        $this->assertSame(self::fingerprint(), (string) $row->fingerprint);

        $summary = json_decode((string) $row->summary, true);
        $this->assertSame(2, (int) ($summary['attempts'] ?? 0));
    }

    public function test_different_slots_get_their_own_row(): void
    {
        $service = app(ScheduleRunLogService::class);

        $service->record('指纹测试任务', fn (): array => ['ok' => 1], $this->context());
        $service->record('指纹测试任务', fn (): array => ['ok' => 2], $this->context(['tick_slot' => '2026-11-06 10:15:00']));

        $this->assertSame(
            2,
            DB::table('schedule_run_logs')->where('task_key', self::TASK_KEY)->count(),
        );
    }

    public function test_running_row_blocks_a_second_execution_as_skipped_not_failed(): void
    {
        // 模拟「上一轮仍在跑」：同指纹已有 running 行
        DB::table('schedule_run_logs')->insert([
            'task_name' => '指纹测试任务',
            'task_key' => self::TASK_KEY,
            'fingerprint' => self::fingerprint(),
            'status' => 'running',
            'duration_ms' => 0,
            'summary' => json_encode(['attempts' => 1]),
            'started_at' => now()->subMinute()->toDateTimeString(),
            'created_at' => now()->subMinute()->toDateTimeString(),
            'updated_at' => now()->subMinute()->toDateTimeString(),
        ]);

        $executed = false;
        $result = app(ScheduleRunLogService::class)->record(
            '指纹测试任务',
            function () use (&$executed): array {
                $executed = true;

                return ['ok' => 1];
            },
            $this->context(),
        );

        // 没有执行第二次，也没有抛错；结果是 skipped 且原因可诊断
        $this->assertFalse($executed);
        $this->assertIsArray($result);
        $this->assertSame(ScheduleRunLogService::STATUS_SKIPPED, $result['status']);
        $this->assertSame(ScheduleRunLogService::SKIP_PREVIOUS_RUN_IN_PROGRESS, $result['reason']);

        $rows = DB::table('schedule_run_logs')->where('task_key', self::TASK_KEY)->get();
        $this->assertCount(1, $rows);
        // 仍是进行中，不会被并发触发改写成 failed
        $this->assertSame('running', (string) $rows->first()->status);
        $this->assertNull($rows->first()->error_msg);

        $summary = json_decode((string) $rows->first()->summary, true);
        $this->assertNotEmpty($summary['overlaps'] ?? null);
    }

    public function test_failure_is_recorded_with_diagnosable_reason(): void
    {
        $service = app(ScheduleRunLogService::class);

        try {
            $service->record(
                '指纹测试任务',
                static function (): void {
                    throw new RuntimeException('业务炸了');
                },
                $this->context(['attempt' => 2]),
            );

            $this->fail('失败应当原样抛出，不能被日志层吞掉');
        } catch (RuntimeException $exception) {
            $this->assertSame('业务炸了', $exception->getMessage());
        }

        $row = DB::table('schedule_run_logs')->where('task_key', self::TASK_KEY)->first();
        $this->assertNotNull($row);
        $this->assertSame('failed', (string) $row->status);

        $error = (string) $row->error_msg;
        $this->assertStringContainsString('RuntimeException', $error);
        $this->assertStringContainsString('业务炸了', $error);
        $this->assertStringContainsString(self::TASK_KEY, $error);
        $this->assertStringContainsString('第 2 次尝试', $error);

        $summary = json_decode((string) $row->summary, true);
        $this->assertSame('RuntimeException', $summary['failure_history'][0]['exception'] ?? null);
    }

    public function test_skipped_rows_are_deduplicated_and_never_counted_as_failure(): void
    {
        $service = app(ScheduleRunLogService::class);

        $service->recordSkipped('指纹测试任务', ScheduleRunLogService::SKIP_RUN_NOT_ACTIVE, $this->context());
        $service->recordSkipped('指纹测试任务', ScheduleRunLogService::SKIP_RUN_NOT_ACTIVE, $this->context());

        $rows = DB::table('schedule_run_logs')->where('task_key', self::TASK_KEY)->get();
        $this->assertCount(1, $rows);

        $row = $rows->first();
        $this->assertSame('skipped', (string) $row->status);
        $this->assertStringContainsString('schedule_run_not_active', (string) $row->error_msg);

        $summary = json_decode((string) $row->summary, true);
        $this->assertSame(2, count($summary['skips'] ?? []));

        // 健康总览里 skipped 单独计数，不进失败数
        $health = collect($service->getHealthOverview())->firstWhere('task_name', '指纹测试任务');
        $this->assertNotNull($health);
        $this->assertSame(0, (int) $health['failed_count_24h']);
        $this->assertSame(1, (int) $health['skipped_count_24h']);
    }

    public function test_admin_run_list_uses_a_constant_number_of_queries(): void
    {
        $repository = app(ScheduleTaskRunRepository::class);

        // 预热：首次调用会做一次表存在性探测，不计入稳态查询数
        $repository->paginateRuns([], 1, 5);

        $insert = function (int $index): void {
            DB::table('schedule_task_runs')->insert([
                'task_key' => 'perf-task-'.$index,
                'task_name' => '性能任务 '.$index,
                'rule_description' => 'every 15 minutes',
                'source' => 'heartbeat',
                'queue' => 'automation',
                'status' => 'success',
                'attempt' => 1,
                'duration_ms' => 10,
                'summary' => json_encode(['index' => $index]),
                'queued_at' => now(),
                'started_at' => now(),
                'finished_at' => now(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        };

        foreach (range(1, 5) as $index) {
            $insert($index);
        }

        DB::flushQueryLog();
        DB::enableQueryLog();
        $first = $repository->paginateRuns([], 1, 5);
        $queriesForFive = count(DB::getQueryLog());

        foreach (range(6, 30) as $index) {
            $insert($index);
        }

        DB::flushQueryLog();
        $second = $repository->paginateRuns([], 1, 5);
        $queriesForTwentyFive = count(DB::getQueryLog());
        DB::disableQueryLog();

        $this->assertSame(5, count($first->items()));
        $this->assertSame(5, count($second->items()));
        // 每页固定两条查询（count + 分页查询）；行数变化不影响查询条数
        $this->assertSame($queriesForFive, $queriesForTwentyFive);
        $this->assertLessThanOrEqual(3, $queriesForFive);
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function context(array $overrides = []): array
    {
        return array_merge([
            'task_key' => self::TASK_KEY,
            'source' => 'heartbeat',
            'tick_slot' => self::SLOT,
            'tick_id' => 0,
            'attempt' => 1,
        ], $overrides);
    }

    private static function fingerprint(): string
    {
        return sha1(self::TASK_KEY.'|slot:'.self::SLOT);
    }
}
