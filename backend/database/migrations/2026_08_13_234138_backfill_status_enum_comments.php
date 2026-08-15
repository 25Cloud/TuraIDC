<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * 专家团结构批：补齐状态/枚举字段的列注释，消除"实库数据取值已在用、
 * 但注释与代码常量漂移"的文档缺口。
 *
 * 取值语义均以代码常量（app/Constants、模型常量）为准，不在注释中臆造：
 * - PaymentStatus::CANCELLED=4 已取消（充值超时自动关闭流程产物）
 * - InvoiceStatus 含 PARTIALLY_REFUNDED=6，4 为历史跳号无定义，保留
 * - UserCouponStatus: OWNED=1 / USED=2 / REVOKED=3
 * - CouponStatus: DISABLED=0 / ACTIVE=1
 * - Refund::STATUS_COMPLETED=1
 * - RechargeRecord 的 scene/direction/entry_type 取值见 FinanceDocumentService
 */
return new class extends Migration
{
    private const TARGETS = [
        'payments' => [
            'status' => [
                'type' => 'tinyint NOT NULL DEFAULT 0',
                'comment' => '支付状态：0待支付 1成功 2失败 3已退款 4已取消',
                'restore' => '支付状态：0待支付 1成功 2失败 3已退款',
            ],
        ],
        'invoices' => [
            'status' => [
                'type' => 'tinyint NOT NULL DEFAULT 0',
                'comment' => '账单状态：0未付 1已付 2已取消 3逾期 5已退款 6部分退款',
                'restore' => '账单状态：0待支付 1已支付 2已取消 3已逾期 5已退款',
            ],
        ],
        'refunds' => [
            'status' => [
                'type' => 'tinyint unsigned NOT NULL DEFAULT 1',
                'comment' => '退款状态：1=已完成',
                'restore' => '',
            ],
        ],
        'user_coupons' => [
            'status' => [
                'type' => 'tinyint NOT NULL DEFAULT 1',
                'comment' => '优惠券状态：1=持有 2=已使用 3=已回收',
                'restore' => '',
            ],
        ],
        'coupons' => [
            'status' => [
                'type' => 'tinyint NOT NULL DEFAULT 1',
                'comment' => '状态：0=禁用 1=启用',
                'restore' => '',
            ],
        ],
        'coupon_campaigns' => [
            'status' => [
                'type' => 'tinyint NOT NULL DEFAULT 1',
                'comment' => '状态：0=禁用 1=启用',
                'restore' => '',
            ],
        ],
        'recharge_records' => [
            'scene' => [
                'type' => 'varchar(30) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL',
                'comment' => '业务场景：recharge=支付充值 admin_recharge=管理员充值 refund=退款 等',
                'restore' => '',
            ],
            'direction' => [
                'type' => 'varchar(8) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL',
                'comment' => '资金方向：in=入账 out=出账',
                'restore' => '',
            ],
            'entry_type' => [
                'type' => 'varchar(30) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL',
                'comment' => '入账类型：third_party_payment/manual_recharge/account_recharge/refund_offset',
                'restore' => '',
            ],
        ],
    ];

    public function up(): void
    {
        foreach (self::TARGETS as $table => $columns) {
            if (! Schema::hasTable($table)) {
                continue;
            }

            foreach ($columns as $column => $config) {
                if (! Schema::hasColumn($table, $column)) {
                    continue;
                }

                $comment = str_replace("'", "''", (string) $config['comment']);
                DB::statement("ALTER TABLE `{$table}` MODIFY `{$column}` {$config['type']} COMMENT '{$comment}'");
            }
        }
    }

    public function down(): void
    {
        foreach (self::TARGETS as $table => $columns) {
            if (! Schema::hasTable($table)) {
                continue;
            }

            foreach ($columns as $column => $config) {
                if (! Schema::hasColumn($table, $column)) {
                    continue;
                }

                $comment = $config['restore'] === '' ? '' : " COMMENT '".str_replace("'", "''", $config['restore'])."'";
                DB::statement("ALTER TABLE `{$table}` MODIFY `{$column}` {$config['type']}{$comment}");
            }
        }
    }
};
