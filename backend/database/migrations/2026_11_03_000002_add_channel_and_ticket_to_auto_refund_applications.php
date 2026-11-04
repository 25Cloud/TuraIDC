<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 自助退款台账补齐「通道」与「工单」两维（插件 addons/auto_refund）。
 *
 * 复刻上游 AutoRefund 的四种退款类型后，一张申请单必须能回答两件事：
 * 1. 走的是哪条通道——`bridge`（上游 api.php 插件间对接）、`api_ticket`（向上游开工单）、
 *    `local`（不外发，本站人工审核直接结算）；
 * 2. 如果是工单通道，本地工单是哪张、上游工单号是什么——结算和排障都要能把两边对上。
 *
 * `policy_snapshot` 存提交那一刻的商品策略（退款规则、两项「首次」要求、时间窗口），
 * 因为策略是可改的配置：不存快照，事后没人能解释「当时为什么这台能退、金额怎么算的」。
 *
 * 只新增列，不删不改存量列；全部 nullable 或带默认值，MySQL 5.7 与 8.x 均可执行。
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('auto_refund_applications')) {
            return;
        }

        Schema::table('auto_refund_applications', function (Blueprint $table): void {
            $table->string('refund_type', 16)->default('manual')->after('status')->comment('提交时的商品退款类型快照');
            $table->string('channel', 16)->default('bridge')->after('refund_type')->comment('bridge 上游接口 / api_ticket 上游工单 / local 仅本站');
            $table->unsignedBigInteger('ticket_id')->nullable()->after('channel')->comment('api_ticket 通道生成的本站工单');
            $table->string('upstream_ticket_id', 64)->nullable()->after('ticket_id')->comment('上游工单号');
            $table->text('policy_snapshot')->nullable()->after('upstream_ticket_id')->comment('提交时的商品策略快照，JSON 字符串');

            $table->index('ticket_id', 'auto_refund_applications_ticket_index');
        });
    }

    public function down(): void
    {
        // 只新增内容的迁移不做物理回滚（存量台账与工单关联要保留）
    }
};
