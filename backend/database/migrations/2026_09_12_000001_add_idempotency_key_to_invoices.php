<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * 开放 API 下单要求幂等键，但原实现只把「幂等键 → 账单 ID」映射放在 15 分钟 TTL 的
     * volatile 缓存里：缓存驱逐/重启后，下游按标准幂等语义重试同一请求会重复建单扣费。
     * 把幂等键落库并加唯一索引作为 DB 层兜底，缓存只作加速。
     */
    public function up(): void
    {
        Schema::table('invoices', function (Blueprint $table): void {
            $table->string('idempotency_key', 64)->nullable()->after('trace_id')
                ->comment('开放 API 下单幂等键，同一用户内唯一；站内下单为 NULL');
            $table->unique(['user_id', 'idempotency_key'], 'invoices_user_idempotency_key_unique');
        });
    }

    public function down(): void
    {
        Schema::table('invoices', function (Blueprint $table): void {
            $table->dropUnique('invoices_user_idempotency_key_unique');
            $table->dropColumn('idempotency_key');
        });
    }
};
