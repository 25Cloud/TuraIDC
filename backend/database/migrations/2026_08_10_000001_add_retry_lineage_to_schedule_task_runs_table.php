<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('schedule_task_runs')) {
            return;
        }

        $hasParentRunId = Schema::hasColumn('schedule_task_runs', 'parent_run_id');
        $hasAttempt = Schema::hasColumn('schedule_task_runs', 'attempt');
        $hasManualRetryAt = Schema::hasColumn('schedule_task_runs', 'manual_retry_at');
        $hasManualRetryBy = Schema::hasColumn('schedule_task_runs', 'manual_retry_by');

        if (! $hasParentRunId || ! $hasAttempt || ! $hasManualRetryAt || ! $hasManualRetryBy) {
            Schema::table('schedule_task_runs', function (Blueprint $table) use ($hasParentRunId, $hasAttempt, $hasManualRetryAt, $hasManualRetryBy): void {
                if (! $hasParentRunId) {
                    $table->unsignedBigInteger('parent_run_id')->nullable()->after('id');
                    $table->index(['parent_run_id', 'created_at'], 'schedule_task_runs_parent_created_at_index');
                }

                if (! $hasAttempt) {
                    $table->unsignedSmallInteger('attempt')->default(1)->after('status');
                }

                if (! $hasManualRetryAt) {
                    $table->timestamp('manual_retry_at')->nullable()->after('finished_at');
                }

                if (! $hasManualRetryBy) {
                    $table->unsignedBigInteger('manual_retry_by')->nullable()->after('manual_retry_at');
                }
            });
        }
    }

    public function down(): void
    {
        // 运行台账属于审计记录；生产回滚仅停止新代码写入，不删除已落库的字段或索引。
    }
};
