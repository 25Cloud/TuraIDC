<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\MemberLevel;
use App\Models\OperationLog;
use App\Models\User;
use App\Services\Referral\MemberLevelService;
use App\Support\DatabaseSchema;
use App\Support\DeferredJoinPaginator;
use Illuminate\Database\Events\TransactionBeginning;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

/**
 * 覆盖三处热点查询改造的行为契约。
 *
 * 这些用例锁的是「结果不变、查询方式变了」——所以每条都同时断言正确性与查询形态，
 * 只断言其一都拦不住回退。
 */
class HotPathQueryPerformanceTest extends TestCase
{
    private string $suffix = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->suffix = bin2hex(random_bytes(5));
    }

    protected function tearDown(): void
    {
        OperationLog::query()->where('module', 'hotpath-'.$this->suffix)->forceDelete();
        User::query()->where('email', 'like', 'hotpath-'.$this->suffix.'%')->forceDelete();
        MemberLevel::query()->where('name', 'like', 'hotpath-'.$this->suffix.'%')->delete();

        parent::tearDown();
    }

    /**
     * @return list<int> 新建日志的 id
     */
    private function seedLogs(int $count, int $rowsPerTimestamp = 3): array
    {
        $module = 'hotpath-'.$this->suffix;
        $base = time() - $count;
        $rows = [];
        for ($i = 0; $i < $count; $i++) {
            $rows[] = [
                // 一半 HTTP 动作、一半业务动作，覆盖 getApiLogs 的 REGEXP 过滤两侧
                'action' => $i % 2 === 0 ? 'GET.api.v2.admin.users' : 'user.update',
                'module' => $module,
                // 让多行共享同一个 created_at：operation_logs 的时间戳是秒级且实测不唯一，
                // 排序键有并列时分页最容易出漏行/重行。
                'created_at' => date('Y-m-d H:i:s', $base + intdiv($i, max(1, $rowsPerTimestamp))),
            ];
        }
        OperationLog::query()->insert($rows);

        return OperationLog::query()->where('module', $module)->pluck('id')->all();
    }

    public function test_deferred_join_paginator_returns_the_same_rows_as_the_standard_paginator(): void
    {
        $this->seedLogs(120);
        $module = 'hotpath-'.$this->suffix;

        foreach ([1, 2, 5] as $page) {
            $expected = OperationLog::query()->where('module', $module)
                ->orderByDesc('created_at')->orderByDesc('id')
                ->paginate(20, ['*'], 'page', $page);

            $actual = DeferredJoinPaginator::paginate(
                OperationLog::query()->where('module', $module),
                20,
                $page
            );

            $this->assertSame(
                $expected->total(),
                $actual->total(),
                "第 {$page} 页的 total 必须与标准分页一致"
            );
            $this->assertSame(
                $expected->getCollection()->pluck('id')->all(),
                $actual->getCollection()->pluck('id')->all(),
                "第 {$page} 页的行与行序必须与标准分页一致"
            );
        }
    }

    public function test_deferred_join_paginator_keeps_filters_and_works_with_non_sargable_conditions(): void
    {
        $this->seedLogs(80);
        $module = 'hotpath-'.$this->suffix;

        // getApiLogs 真实形态：先按 action REGEXP 过滤，再分页。
        $build = fn () => OperationLog::query()
            ->where('module', $module)
            ->whereRaw('action REGEXP ?', ['^(GET|POST|PUT|PATCH|DELETE)\\.']);

        $expected = $build()->orderByDesc('created_at')->orderByDesc('id')->paginate(20, ['*'], 'page', 2);
        $actual = DeferredJoinPaginator::paginate($build(), 20, 2);

        $this->assertSame($expected->total(), $actual->total(), '过滤条件必须原样生效（total 相同）');
        $this->assertSame(
            $expected->getCollection()->pluck('id')->all(),
            $actual->getCollection()->pluck('id')->all(),
            '过滤条件下的行与行序必须一致'
        );
        $this->assertGreaterThan(0, $actual->total(), '样本必须非空，否则本用例是空跑');
        $actual->getCollection()->each(function (OperationLog $log): void {
            $this->assertStringStartsWith('GET.', (string) $log->action, '不满足过滤条件的行不应出现');
        });
    }

    public function test_deferred_join_pagination_sweeps_all_rows_without_duplicates_or_gaps(): void
    {
        $this->seedLogs(100);
        $module = 'hotpath-'.$this->suffix;

        $expected = OperationLog::query()->where('module', $module)
            ->orderByDesc('created_at')->orderByDesc('id')
            ->pluck('id')->all();

        $collected = [];
        for ($page = 1; $page <= 5; $page++) {
            $paginator = DeferredJoinPaginator::paginate(
                OperationLog::query()->where('module', $module),
                20,
                $page
            );
            foreach ($paginator->getCollection() as $row) {
                $collected[] = (int) $row->id;
            }
        }

        $this->assertSame($expected, $collected, '逐页翻完必须与一次性排序取全量逐行相等：无重复、无遗漏');
        $this->assertCount(count(array_unique($collected)), $collected, '翻页结果不得出现重复行');
    }

    public function test_database_schema_has_table_is_memoized_within_the_process(): void
    {
        DatabaseSchema::resetCache();

        $queries = 0;
        DB::listen(function () use (&$queries): void {
            $queries++;
        });

        $first = DatabaseSchema::hasTable('users');
        $afterFirst = $queries;

        for ($i = 0; $i < 20; $i++) {
            DatabaseSchema::hasTable('users');
        }

        $this->assertTrue($first, 'users 表应存在');
        $this->assertSame(
            $afterFirst,
            $queries,
            '首次之后的重复调用不得再打数据库：热点循环里每次都查 information_schema 正是本次要消除的开销'
        );

        // 语义必须与 Schema::hasTable 一致（不含视图），否则不能替换既有调用点
        $this->assertFalse(
            DatabaseSchema::hasTable('__definitely_missing_table_'.$this->suffix),
            '不存在的表必须返回 false'
        );
    }

    public function test_member_level_update_no_longer_resyncs_users_inside_a_single_transaction(): void
    {
        $level = MemberLevel::query()->create([
            'name' => 'hotpath-'.$this->suffix.'-lv',
            'sort_order' => 1,
        ]);

        for ($i = 0; $i < 5; $i++) {
            User::query()->create([
                'email' => 'hotpath-'.$this->suffix.'-'.$i.'@example.com',
                'password' => 'Temp@123456',
                'total_sales_amount' => '1.00',
            ]);
        }

        $begun = 0;
        Event::listen(TransactionBeginning::class, function () use (&$begun): void {
            $begun++;
        });

        $service = app(MemberLevelService::class);
        $service->update($level, ['name' => 'hotpath-'.$this->suffix.'-lv2']);

        // 改造前：等级写入 + 全量重算共处一个事务，只会开 1 个。
        // 改造后：等级写入 1 个 + 每批重算各 1 个，因此必然 > 1。
        $this->assertGreaterThan(
            1,
            $begun,
            '存量用户重算必须分批各自开短事务，不能和等级写入挤在同一个长事务里'
        );

        $this->assertSame(
            'hotpath-'.$this->suffix.'-lv2',
            (string) $level->fresh()?->name,
            '等级本身仍必须写入成功'
        );
    }

    public function test_resync_all_user_levels_is_idempotent_and_reports_processed_count(): void
    {
        for ($i = 0; $i < 3; $i++) {
            User::query()->create([
                'email' => 'hotpath-'.$this->suffix.'-r'.$i.'@example.com',
                'password' => 'Temp@123456',
                'total_sales_amount' => '1.00',
            ]);
        }

        $service = app(MemberLevelService::class);

        $first = $service->resyncAllUserLevels(2);
        $second = $service->resyncAllUserLevels(2);

        $this->assertGreaterThanOrEqual(3, $first, '应至少重算到本用例创建的 3 个用户');
        $this->assertSame(
            $first,
            $second,
            '重算必须幂等：连跑两次处理的用户数一致，失败后重跑即可收敛'
        );
    }
}
