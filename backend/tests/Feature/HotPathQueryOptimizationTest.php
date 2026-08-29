<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\MemberLevel;
use App\Models\OperationLog;
use App\Models\User;
use App\Services\Referral\MemberLevelService;
use App\Support\DatabaseSchema;
use App\Support\DeferredJoinPaginator;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * 三处热点查询优化的回归护栏：
 * 1) DeferredJoinPaginator 必须与原生 paginate() 返回完全一致的结果
 * 2) MemberLevelService 的存量重算不得再回到「单事务全表遍历 + 循环内 User::find」
 * 3) DatabaseSchema::hasTable() 必须真的记忆化（否则循环里的兼容判断会退回每次一条 information_schema）
 */
class HotPathQueryOptimizationTest extends TestCase
{
    private function seedLogs(int $count, string $action = 'probe.action'): void
    {
        $rows = [];
        $base = now()->subSeconds($count);
        for ($i = 1; $i <= $count; $i++) {
            $rows[] = [
                'action' => $action,
                'module' => 'probe',
                // 每 3 行共享同一时间戳：operation_logs.created_at 实测不唯一，
                // 分页必须在排序键重复时依然稳定。
                'created_at' => $base->copy()->addSeconds(intdiv($i, 3)),
            ];
        }
        foreach (array_chunk($rows, 200) as $chunk) {
            DB::table('operation_logs')->insert($chunk);
        }
    }

    public function test_deferred_join_paginator_matches_native_paginate_row_for_row(): void
    {
        $this->seedLogs(120);

        foreach ([1, 2, 3, 6] as $page) {
            $native = OperationLog::query()
                ->where('module', 'probe')
                ->orderByDesc('created_at')->orderByDesc('id')
                ->paginate(20, ['*'], 'page', $page);

            $deferred = DeferredJoinPaginator::paginate(
                OperationLog::query()->where('module', 'probe'),
                20,
                $page
            );

            $this->assertSame(
                $native->pluck('id')->all(),
                $deferred->pluck('id')->all(),
                "第 {$page} 页的行集必须与原生 paginate 完全一致（含排序键重复的情况）"
            );
            $this->assertSame($native->total(), $deferred->total(), '总数必须一致');
            $this->assertSame($native->currentPage(), $deferred->currentPage());
            $this->assertSame($native->perPage(), $deferred->perPage());
        }
    }

    public function test_deferred_join_paginator_keeps_filters_and_handles_empty_and_overflow_pages(): void
    {
        $this->seedLogs(30, 'kept.action');
        $this->seedLogs(30, 'filtered.action');

        $deferred = DeferredJoinPaginator::paginate(
            OperationLog::query()->where('action', 'kept.action'),
            20,
            1
        );
        $this->assertSame(30, $deferred->total(), '过滤条件必须生效，不能把被过滤掉的行算进总数');
        $this->assertCount(20, $deferred->items());
        foreach ($deferred->items() as $item) {
            $this->assertSame('kept.action', $item->action);
        }

        // 越界页：不得报错，返回空集但总数仍然正确
        $overflow = DeferredJoinPaginator::paginate(
            OperationLog::query()->where('action', 'kept.action'),
            20,
            99
        );
        $this->assertSame(30, $overflow->total());
        $this->assertCount(0, $overflow->items());

        // 空结果集：不得触发第二段 whereIn 查询
        $empty = DeferredJoinPaginator::paginate(
            OperationLog::query()->where('action', 'no.such.action'),
            20,
            1
        );
        $this->assertSame(0, $empty->total());
        $this->assertCount(0, $empty->items());
    }

    public function test_deferred_join_paginator_only_selects_key_in_the_first_pass(): void
    {
        $this->seedLogs(60);

        $sqls = [];
        DB::listen(function ($event) use (&$sqls): void {
            $sqls[] = $event->sql;
        });

        DeferredJoinPaginator::paginate(OperationLog::query()->where('module', 'probe'), 20, 2);

        // 取主键那一趟必须只 select 主键，否则 MySQL 不会走覆盖索引，优化就白做了。
        $selectsKeyOnly = array_filter(
            $sqls,
            fn (string $sql): bool => str_contains($sql, 'select `operation_logs`.`id`')
                && str_contains($sql, 'offset')
        );
        $this->assertNotEmpty(
            $selectsKeyOnly,
            '第一趟必须是「只取主键 + offset」的窄查询；一旦退回 select *，覆盖索引失效'
        );
    }

    public function test_member_level_resync_does_not_reload_users_one_by_one(): void
    {
        if (! DatabaseSchema::hasTable('user_referrals')) {
            $this->markTestSkipped('该分支只在存在 user_referrals 表时生效');
        }

        $level = MemberLevel::query()->create([
            'name' => '探针等级',
            'sort_order' => 1,
        ]);

        for ($i = 1; $i <= 5; $i++) {
            User::query()->create([
                'email' => "hotpath{$i}@example.test",
                'password' => 'secret-password',
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
            fn (string $sql): bool => (bool) preg_match('/select \* from `users` where `users`\.`id` = \?/', $sql)
        );
        $this->assertLessThanOrEqual(
            1,
            count($singleUserLookups),
            '存量重算不得在循环里逐个 User::find()——这正是被修掉的 N+1'
        );
    }

    public function test_member_level_update_keeps_level_change_and_stays_idempotent(): void
    {
        $level = MemberLevel::query()->create([
            'name' => '探针等级',
            'sort_order' => 1,
        ]);

        $user = User::query()->create([
            'email' => 'hotpath-idem@example.test',
            'password' => 'secret-password',
            'total_sales_amount' => '10.00',
            'member_level_id' => $level->id,
        ]);

        $service = app(MemberLevelService::class);
        $service->update($level, ['name' => '探针等级改名']);

        $this->assertSame('探针等级改名', $level->fresh()->name, '等级写入必须落库');

        $before = $user->fresh()->only(['member_level_id', 'total_sales_amount']);
        // 重算是幂等的——这正是「把重算移出等级写入事务」这一取舍成立的前提。
        $service->resyncAllUserLevels();
        $service->resyncAllUserLevels();
        $this->assertSame($before, $user->fresh()->only(['member_level_id', 'total_sales_amount']));
    }

    public function test_database_schema_has_table_is_memoized(): void
    {
        DatabaseSchema::resetCache();

        $count = 0;
        DB::listen(function ($event) use (&$count): void {
            if (str_contains($event->sql, 'information_schema')) {
                $count++;
            }
        });

        for ($i = 0; $i < 8; $i++) {
            DatabaseSchema::hasTable('users');
        }

        $this->assertLessThanOrEqual(
            1,
            $count,
            'hasTable 必须记忆化：否则循环里的兼容判断会退回每次一条 information_schema 查询'
        );
        $this->assertTrue(DatabaseSchema::hasTable('users'));
        $this->assertFalse(DatabaseSchema::hasTable('definitely_not_a_real_table'));
    }
}
