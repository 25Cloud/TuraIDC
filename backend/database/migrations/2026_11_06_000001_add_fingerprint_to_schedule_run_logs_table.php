<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 定时任务运行日志：补「指纹」列，用于同一任务同一时间片的去重与并发收口。
 *
 * 背景：`schedule_run_logs` 是全站唯一的定时任务运行留痕，但它是纯 append 的——
 * 队列重试、同一槽位的重复心跳、降级互斥下的多进程重入，每次都会插一条新行。
 * 结果同一次「任务+槽位」的运行被记成好几行，管理端看起来像跑了很多次，
 * 失败重试还会把上一轮的成功/失败状态冲掉。
 *
 * 指纹口径：`sha1(task_key|slot)`，其中 slot 优先取心跳槽位（`schedule_tick_id` 或
 * 槽位起始时间），没有心跳上下文时退化为 15 分钟向下取整的时间片。
 *
 * 并发安全：`fingerprint` 上建**唯一索引**（允许 NULL，因此历史行天然不冲突，
 * MySQL 5.7 与 8.x 行为一致）。写日志时先 `INSERT IGNORE` 抢行，抢不到再按指纹读回，
 * 从库层面保证「同一指纹至多一条进行中记录」，不依赖先查后写。
 *
 * 只新增列与索引，不改不删任何存量结构；不涉及窗口函数、CTE 或表达式默认值。
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('schedule_run_logs')) {
            return;
        }

        Schema::table('schedule_run_logs', function (Blueprint $table): void {
            if (! Schema::hasColumn('schedule_run_logs', 'task_key')) {
                $table->string('task_key', 120)
                    ->nullable()
                    ->after('task_name')
                    ->comment('任务标识（心跳任务 key；历史行为空）');
            }

            if (! Schema::hasColumn('schedule_run_logs', 'fingerprint')) {
                $table->string('fingerprint', 64)
                    ->nullable()
                    ->after('task_key')
                    ->comment('同任务同时间片指纹 sha1(task_key|slot)，唯一索引用于去重');
            }
        });

        Schema::table('schedule_run_logs', function (Blueprint $table): void {
            if (! $this->hasIndex('schedule_run_logs', 'schedule_run_logs_fingerprint_unique')) {
                $table->unique('fingerprint', 'schedule_run_logs_fingerprint_unique');
            }

            if (! $this->hasIndex('schedule_run_logs', 'schedule_run_logs_task_key_created_at_index')) {
                $table->index(['task_key', 'created_at'], 'schedule_run_logs_task_key_created_at_index');
            }
        });
    }

    public function down(): void
    {
        // 只新增内容的迁移不做物理回滚（历史运行日志与去重索引都要保留）
    }

    private function hasIndex(string $table, string $index): bool
    {
        try {
            $connection = Schema::getConnection();
            $database = $connection->getDatabaseName();

            return $connection->table('information_schema.statistics')
                ->where('table_schema', $database)
                ->where('table_name', $table)
                ->where('index_name', $index)
                ->exists();
        } catch (Throwable) {
            return false;
        }
    }
};
