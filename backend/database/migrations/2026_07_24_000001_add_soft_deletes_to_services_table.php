<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * 为 services 表添加软删除支持，防止误删服务实例后无法恢复。
     */
    public function up(): void
    {
        if (Schema::hasTable('services') && ! Schema::hasColumn('services', 'deleted_at')) {
            Schema::table('services', function (Blueprint $table) {
                $table->softDeletes()->after('updated_at');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('services', 'deleted_at')) {
            Schema::table('services', function (Blueprint $table) {
                $table->dropSoftDeletes();
            });
        }
    }
};
