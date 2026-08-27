<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 上游余额台账。
 *
 * 此前上游余额只能由管理员在供应商页面手动点击查询，实时打上游接口、不落库：
 * 既看不到历史，也无法判断"是否跌破阈值"（比较需要一个已知的当前值），
 * 更没有任何余额不足的预警。本表按供应商记录最近一次同步结果与告警阈值。
 *
 * 每个供应商一行（supplier_id 唯一）：定时任务与支付后触发都是覆盖式更新，
 * 不追加历史行，避免高频同步把表撑大；需要趋势时另建快照表即可，本表不受影响。
 *
 * 时间列一律 nullable（带显式 DEFAULT NULL）：MySQL 5.7 在
 * explicit_defaults_for_timestamp=OFF 时会给首个无显式默认值的 timestamp NOT NULL
 * 列偷偷附加 ON UPDATE CURRENT_TIMESTAMP，任何不含该列的 UPDATE 都会改写它。
 * 详见 docs/references/database/mysql-version-compatibility.md。
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('supplier_balances')) {
            return;
        }

        Schema::create('supplier_balances', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('supplier_id')->comment('所属供应商');
            $table->string('provider_key', 120)->nullable()->comment('同步时使用的上游标识');
            $table->decimal('balance', 14, 2)->nullable()->comment('最近一次成功同步到的上游余额');
            $table->string('currency', 20)->nullable()->comment('币种，取上游返回值');
            $table->decimal('low_balance_threshold', 14, 2)->default(20)->comment('余额不足告警阈值，默认 20');
            $table->boolean('low_balance_alert_enabled')->default(true)->comment('是否启用余额不足邮件提醒');
            $table->timestamp('last_synced_at')->nullable()->comment('最近一次成功同步时间');
            $table->timestamp('last_attempted_at')->nullable()->comment('最近一次尝试同步时间，含失败');
            $table->string('last_sync_status', 30)->nullable()->comment('success / failed');
            $table->string('last_sync_error', 500)->nullable()->comment('最近一次同步失败原因');
            $table->timestamp('low_balance_notified_at')->nullable()->comment('最近一次余额不足告警时间，用于冷却与状态机');
            $table->timestamps();

            $table->unique('supplier_id', 'supplier_balances_supplier_id_unique');
            $table->index(['last_synced_at'], 'supplier_balances_last_synced_at_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('supplier_balances');
    }
};
