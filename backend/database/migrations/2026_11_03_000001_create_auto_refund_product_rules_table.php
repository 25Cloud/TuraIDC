<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 自助退款：按商品配置退款策略（插件 addons/auto_refund）。
 *
 * 为什么是「按商品」：上游 AutoRefund 的退款类型、退款规则、退款要求三项都挂在
 * **商品**上（它后台「添加产品」页逐商品配置），`product_refund` 表一行一个商品。
 * 全局一套配置时，「产品首次退款」这类要求无处落地——判定粒度本来就是商品。
 *
 * 三个字段的取值直接对齐上游 `product_refund` 表的 `type` / `rules` / `request`
 * （见 docs/internal/execution-plans/active/auto-refund-plugin-2026-11-01.md 第二阶段的语义对照表）：
 * - `refund_type`：`manual` 人工审核(1) / `auto` 自动退款(2) / `api_ticket` API 工单退款(3) / `bridge` 插件间对接(4)；
 * - `refund_rule`：`duration` 按时长(1) / `month` 按月(2) / `full` 全额(3)；
 * - `requirement`：`product_first` 产品首次(1) / `category_first` 同类产品首次(2) / `within_hours` 指定时间内(3)；
 * - `window_hours`：对应上游 `within`，**小时**不是天，只有 `within_hours` 时生效。
 *
 * 上游把「要求」存成单值而不是多选，这里保持一致：需求侧一行只能是一种要求。
 *
 * 兼容约束（MySQL 5.7.44 与 8.x）：只新增表、显式 InnoDB、无表达式默认值、无窗口函数。
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('auto_refund_product_rules')) {
            return;
        }

        Schema::create('auto_refund_product_rules', function (Blueprint $table): void {
            // 提交申请时要在同一事务里读策略，落 MyISAM 会失去隔离性
            $table->engine = 'InnoDB';
            $table->id();
            $table->unsignedBigInteger('product_id')->comment('本站商品 ID');
            $table->string('refund_type', 16)->default('manual')->comment('manual 人工审核 / auto 自动退款 / api_ticket API 工单退款 / bridge 插件间对接');
            $table->string('refund_rule', 16)->default('duration')->comment('duration 按时长 / month 按月 / full 全额');
            $table->string('requirement', 24)->default('product_first')->comment('product_first 产品首次 / category_first 同类产品首次 / within_hours 指定时间内');
            $table->unsignedInteger('window_hours')->default(0)->comment('开通后 N 小时内可申请（requirement=within_hours 时生效），0 表示不限');
            $table->tinyInteger('enabled')->default(1)->comment('1 启用 / 0 停用');
            $table->unsignedBigInteger('operator_id')->nullable()->comment('最后修改的管理员');
            $table->timestamps();

            $table->unique('product_id', 'auto_refund_product_rules_product_unique');
            $table->index(['enabled', 'refund_type'], 'auto_refund_product_rules_enabled_type_index');
        });
    }

    public function down(): void
    {
        // 策略是运营配置，回滚版本不丢配置
    }
};
