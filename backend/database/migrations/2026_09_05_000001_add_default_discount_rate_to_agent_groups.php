<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('agent_groups', function (Blueprint $table): void {
            // 代理组全局默认折扣率：折扣矩阵未覆盖（商品未挂折扣组或矩阵无记录）时兜底生效；
            // NULL 表示不启用全局折扣，保持仅按矩阵生效的既有行为。
            $table->decimal('default_discount_rate', 5, 2)->nullable()->after('status');
        });
    }

    public function down(): void
    {
        Schema::table('agent_groups', function (Blueprint $table): void {
            $table->dropColumn('default_discount_rate');
        });
    }
};
