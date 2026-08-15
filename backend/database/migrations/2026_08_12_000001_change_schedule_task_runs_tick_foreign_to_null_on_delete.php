<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('schedule_task_runs') || ! Schema::hasTable('schedule_ticks')) {
            return;
        }

        $hasCascadeTickForeign = false;
        foreach (Schema::getForeignKeys('schedule_task_runs') as $foreignKey) {
            $columns = is_array($foreignKey['columns'] ?? null) ? $foreignKey['columns'] : [];
            if (in_array('schedule_tick_id', $columns, true)) {
                $hasCascadeTickForeign = true;

                break;
            }
        }

        if (! $hasCascadeTickForeign) {
            return;
        }

        // 运行台账是长期审计记录：清理 schedule_ticks 槽位时不得级联删除台账，
        // 改为置空关联；schedule_tick_id 本就允许为空。
        Schema::table('schedule_task_runs', function (Blueprint $table): void {
            $table->dropForeign(['schedule_tick_id']);
            $table->foreign('schedule_tick_id')
                ->references('id')
                ->on('schedule_ticks')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        // 台账审计优先；生产回滚不恢复为级联删除。
    }
};
