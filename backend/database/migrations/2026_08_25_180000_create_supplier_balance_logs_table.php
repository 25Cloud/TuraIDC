<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 上游额度变更流水。
 *
 * supplier_balances 只保留每个供应商的"当前值"（覆盖式更新），无法回答
 * "余额什么时候掉下去的""这单开通扣了多少"。本表按变更落一行，用于追溯与对账。
 *
 * 只在余额真正发生变化时写入：定时同步每 15 分钟跑一次，若每次都记一行，
 * 一个供应商一天就是 96 行无效数据，量大且没有信息量。
 *
 * 保留期由「自动化策略 → 工单与待支付清理」中的配置控制，默认 3 天，
 * 由既有的清理任务顺带回收，不额外占用调度槽。
 *
 * 时间列一律 nullable（带显式 DEFAULT NULL）：MySQL 5.7 在
 * explicit_defaults_for_timestamp=OFF 时会给首个无显式默认值的 timestamp NOT NULL
 * 列偷偷附加 ON UPDATE CURRENT_TIMESTAMP。详见
 * docs/references/database/mysql-version-compatibility.md。
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('supplier_balance_logs')) {
            return;
        }

        Schema::create('supplier_balance_logs', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('supplier_id')->comment('所属供应商');
            $table->decimal('balance', 14, 2)->nullable()->comment('本次同步到的余额');
            $table->decimal('previous_balance', 14, 2)->nullable()->comment('变更前余额，首次同步为空');
            $table->decimal('delta', 14, 2)->nullable()->comment('增减值，负数表示消耗');
            $table->string('currency', 20)->nullable()->comment('币种');
            $table->string('source', 30)->default('schedule')->comment('schedule=定时同步 provision=开通后触发 manual=手动查询');
            $table->unsignedBigInteger('order_id')->nullable()->comment('由开通触发时关联的订单');
            $table->timestamp('recorded_at')->nullable()->comment('变更记录时间');
            $table->timestamps();

            $table->index(['supplier_id', 'recorded_at'], 'supplier_balance_logs_supplier_recorded_index');
            $table->index('recorded_at', 'supplier_balance_logs_recorded_at_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('supplier_balance_logs');
    }
};
