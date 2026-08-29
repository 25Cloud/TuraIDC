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
use Illuminate\Foundation\Testing\DatabaseTransactions;
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
    // 本类会调用 resyncAllUserLevels()，它按设计会遍历库里**全部**存量用户。
    // 测试库是多方共用的，因此整类包在事务里跑完即回滚，保证不残留、也不改动他人数据。
    use DatabaseTransactions;

    private string $suffix = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->suffix = bin2hex(random_bytes(5));
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

    public function test_deferred_join_paginator_handles_empty_and_overflow_pages(): void
    {
        $this->seedLogs(30);
        $module = 'hotpath-'.$this->suffix;

        // 越界页：不得报错，返回空集但总数仍然正确
        $overflow = DeferredJoinPaginator::paginate(
            OperationLog::query()->where('module', $module),
            20,
            99
        );
        $this->assertSame(30, $overflow->total(), '越界页的 total 仍应是真实总数');
        $this->assertCount(0, $overflow->items(), '越界页应返回空集而不是报错');

        // 空结果集：不得因为主键集合为空而炸掉第二趟查询
        $empty = DeferredJoinPaginator::paginate(
            OperationLog::query()->where('module', 'no-such-module-'.$this->suffix),
            20,
            1
        );
        $this->assertSame(0, $empty->total());
        $this->assertCount(0, $empty->items());
    }

    public function test_deferred_join_paginator_only_selects_the_key_in_the_first_pass(): void
    {
        $this->seedLogs(60);
        $module = 'hotpath-'.$this->suffix;

        $sqls = [];
        DB::listen(function ($event) use (&$sqls): void {
            $sqls[] = $event->sql;
        });

        DeferredJoinPaginator::paginate(OperationLog::query()->where('module', $module), 20, 2);

        // 取主键那一趟必须只 select 主键：一旦退回 select *，MySQL 就不走覆盖索引，
        // 整个优化失效——而结果依然正确，所以只断言结果的用例拦不住这种回退。
        $keyOnlyPass = array_filter(
            $sqls,
            static fn (string $sql): bool => str_contains($sql, 'select `operation_logs`.`id`')
                && str_contains($sql, 'offset')
        );

        $this->assertNotEmpty(
            $keyOnlyPass,
            '第一趟必须是「只取主键 + offset」的窄查询，否则覆盖索引失效、优化白做'
        );
    }

    public function test_member_level_resync_does_not_reload_users_one_by_one(): void
    {
        if (! DatabaseSchema::hasTable('user_referrals')) {
            $this->markTestSkipped('该 N+1 分支只在存在 user_referrals 表时生效');
        }

        $level = MemberLevel::query()->create([
            'name' => 'hotpath-'.$this->suffix.'-n1',
            // code 是 NOT NULL 且带唯一索引，必须显式给且各用例互不重复
            'code' => 'hp'.substr($this->suffix, 0, 8).'n1',
            'sort_order' => 1,
        ]);

        for ($i = 0; $i < 5; $i++) {
            User::query()->create([
                'email' => 'hotpath-'.$this->suffix.'-n'.$i.'@example.com',
                'password' => 'Temp@123456',
                'total_sales_amount' => '10.00',
                'member_level_id' => $level->id,
            ]);
        }

        $sqls = [];
        DB::listen(function ($event) use (&$sqls): void {
            $sqls[] = $event->sql;
        });

        app(MemberLevelService::class)->resyncAllUserLevels();

        // N+1 的特征是「按单个主键取用户」重复出现；改成整批 whereIn 之后不该再有多条。
        $singleUserLookups = array_filter(
            $sqls,
            static fn (string $sql): bool => (bool) preg_match('/select \* from `users` where `users`\.`id` = \?/', $sql)
        );

        $this->assertLessThanOrEqual(
            1,
            count($singleUserLookups),
            '存量重算不得在循环里逐个 User::find()——这正是本次修掉的 N+1'
        );
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
            'code' => 'hp'.substr($this->suffix, 0, 8).'lv',
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
