<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('schedule_ticks')) {
            return;
        }

        Schema::table('schedule_ticks', function (Blueprint $table): void {
            if (! Schema::hasColumn('schedule_ticks', 'triggered_at')) {
                return;
            }

            // scheduler:liveness 探针按 triggered_at 取 max 判断心跳活性，
            // 该列被持续追加写入，需要索引支撑。
            $table->index('triggered_at');
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('schedule_ticks')) {
            return;
        }

        Schema::table('schedule_ticks', function (Blueprint $table): void {
            $table->dropIndex(['triggered_at']);
        });
    }
};
