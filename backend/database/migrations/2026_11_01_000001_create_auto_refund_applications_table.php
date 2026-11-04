<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 自助退款台账（插件 addons/auto_refund 的业务表）。
 *
 * 为什么建表放在核心迁移里、而不是插件目录：插件标准 v2 §3 明确「插件不能自行注册
 * 管理端菜单、权限、**迁移**和任意路由」，`PluginLifecycleRunner` 的注释也写着
 * 「迁移由调用方负责」。内置插件 `addons/ai_assistant` 的建表同样落在核心迁移
 * （2026_10_26_000001_create_ai_assistant_tables），这里与它保持同一口径，
 * 否则插件安装完表不存在，控制台只能看到一张空台账。
 *
 * 兼容约束（MySQL 5.7.44 与 8.x）：
 * - 结构化载荷一律 text 存 JSON 字符串，不用 json 列、不用 json 函数查询；
 * - 金额用 decimal(12,2)，时间列一律 nullable，避免 5.7 的隐式 ON UPDATE；
 * - 只新增表，不改任何存量表结构。
 *
 * `active_service_id` 是本表最关键的约束：**幂等闸门**。
 * 申请处于「待审核 / 结果未知」时它等于 `service_id`，进入任何终态后置 NULL。
 * MySQL 唯一索引允许多个 NULL，所以既保证「同一服务同时只有一条在途申请」，
 * 又不妨碍同一服务在关闭/失败后重新申请——这比「先查再写」可靠：并发下先查再写必然漏。
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('auto_refund_applications')) {
            return;
        }

        Schema::create('auto_refund_applications', function (Blueprint $table): void {
            // 显式声明 InnoDB：这张表要在与退款结算同一事务里写，落在 MyISAM 上
            // 事务静默失效。仓库其它迁移都吃服务器默认引擎（生产是 InnoDB），
            // 但实测有实例把 default_storage_engine 配成 MyISAM，账务表不赌这个默认值。
            $table->engine = 'InnoDB';
            $table->id();
            $table->string('application_no', 32)->comment('申请单号，形如 AR20261101A1B2，便于工单引用');
            $table->unsignedBigInteger('user_id')->comment('申请用户');
            $table->unsignedBigInteger('service_id')->comment('本地服务实例');
            $table->unsignedBigInteger('order_id')->nullable()->comment('本地订单');
            $table->unsignedBigInteger('invoice_id')->nullable()->comment('本地账单');
            $table->string('upstream_product_id', 120)->default('')->comment('上游 hostid');
            $table->string('status', 20)->default('created')->comment('见 AutoRefundApplication 状态常量');
            $table->unsignedBigInteger('active_service_id')->nullable()->comment('幂等闸门：在途时为 service_id，收口后置 NULL');
            $table->string('reason', 500)->default('')->comment('用户填写的退款原因');
            $table->decimal('estimated_amount', 12, 2)->default(0)->comment('提交时的本地预估金额');
            $table->decimal('settled_amount', 12, 2)->nullable()->comment('实际结算金额');
            $table->integer('upstream_code')->nullable()->comment('最近一次上游 code，只做记录，不参与本地判定');
            $table->string('upstream_msg', 255)->nullable()->comment('上游 msg 原文，仅供人看');
            $table->string('trace_id', 64)->nullable()->comment('上游返回的 trace_id，没返回就是空');
            $table->text('response_meta')->nullable()->comment('脱敏后的请求/响应摘要，JSON 字符串');
            $table->timestamp('submitted_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->timestamp('settled_at')->nullable();
            $table->unsignedBigInteger('operator_id')->nullable()->comment('结算/驳回/作废的管理员');
            $table->string('settle_remark', 255)->nullable();
            $table->timestamps();

            $table->unique('application_no', 'auto_refund_applications_no_unique');
            // 可空唯一列＝MySQL 5.7 没有部分索引时，唯一不引额外表的幂等做法
            $table->unique('active_service_id', 'auto_refund_applications_active_service_unique');
            $table->index('user_id', 'auto_refund_applications_user_index');
            $table->index('service_id', 'auto_refund_applications_service_index');
            $table->index('status', 'auto_refund_applications_status_index');
            $table->index('created_at', 'auto_refund_applications_created_index');
        });
    }

    public function down(): void
    {
        // 台账是财务留痕，回滚版本不回滚数据（插件标准 v2 §3）
    }
};
