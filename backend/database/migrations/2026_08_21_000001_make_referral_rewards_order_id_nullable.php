<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * 修复"无订单账单的推荐奖励必然写入失败"。
 *
 * 现象：ReferralService::rewardForPaidInvoice() 走的是 invoice-only 路径，
 * create() 只写 invoice_id 不写 order_id；而 referral_rewards.order_id 是
 * bigint unsigned NOT NULL 且无默认值，config/database.php 又开了 strict 模式，
 * 于是这条 insert 必然抛 "Field 'order_id' doesn't have a default value"。
 * 异常在 PaymentService::dispatchInvoiceOnlyReferralReward() 里只记日志不重抛，
 * 结果是该场景的推荐奖励永久性静默丢失（余额与流水因事务回滚不会写坏）。
 *
 * 可达路径：管理员开服时勾选"建账单"但不建订单
 * （HandlesAdminUserServices::createDirect(type=normal)），该账单被支付且买家有推荐人。
 *
 * 为什么必须放宽列而不是给默认值 0：
 * order_id 上有 UNIQUE KEY referral_rewards_order_id_unique，
 * 默认写 0 会导致全库只能存在一条 invoice-only 奖励记录。
 * 改为可空后，MySQL 的 UNIQUE 索引允许多个 NULL——
 * 订单路径的唯一性约束照旧生效，invoice-only 记录可以并存。
 *
 * 本迁移只放宽约束、不删列不删索引、不丢数据，且幂等可重复执行。
 */
return new class extends Migration
{
    /**
     * 把 referral_rewards.order_id 放宽为可空。
     *
     * 幂等：先查 information_schema 确认当前是否已可空，已可空则跳过，
     * 因此重复执行不会报错、也不会重复 ALTER。
     */
    public function up(): void
    {
        if (! $this->orderIdIsNullable()) {
            DB::statement('ALTER TABLE `referral_rewards` MODIFY `order_id` BIGINT UNSIGNED NULL');
        }
    }

    /**
     * 回滚为 NOT NULL。
     *
     * 刻意不删任何数据：推荐奖励是资金相关记录，回滚脚本无权替业务做处置决定。
     * 若库里已存在 order_id 为空的记录（即本次修复才能写入的 invoice-only 奖励），
     * NOT NULL 无法容纳它们——此时直接中止并报出数量，把决定权交回运维/开发，
     * 由人工核对后再决定迁移或清理。无此类记录时才执行列约束回退。
     */
    public function down(): void
    {
        if (! $this->orderIdIsNullable()) {
            return;
        }

        $orphanCount = (int) DB::table('referral_rewards')->whereNull('order_id')->count();

        if ($orphanCount > 0) {
            throw new RuntimeException(sprintf(
                '存在 %d 条 order_id 为空的推荐奖励记录（对应无订单账单的奖励），'
                .'回滚为 NOT NULL 会丢失这些资金记录，故已中止。'
                .'请先人工核对并处置这些记录，再执行回滚。',
                $orphanCount
            ));
        }

        DB::statement('ALTER TABLE `referral_rewards` MODIFY `order_id` BIGINT UNSIGNED NOT NULL');
    }

    /**
     * 查 information_schema 判断 order_id 当前是否可空，用于保证 up/down 幂等。
     *
     * 表还不存在时返回 true（当作"无需处理"），避免在全新库上按迁移顺序
     * 执行到本文件时因表未建而中断。
     */
    private function orderIdIsNullable(): bool
    {
        if (! Schema::hasTable('referral_rewards')) {
            return true;
        }

        $column = DB::selectOne(
            'SELECT IS_NULLABLE FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?',
            ['referral_rewards', 'order_id']
        );

        return $column !== null && strtoupper((string) $column->IS_NULLABLE) === 'YES';
    }
};
