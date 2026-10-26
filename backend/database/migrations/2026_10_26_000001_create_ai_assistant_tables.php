<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * AI 助手四张表：会话、消息、工具调用、每日用量。
 *
 * 兼容约束（MySQL 5.7.44 与 8.x）：
 * - 结构化载荷一律 text 存 JSON 字符串，不用 json 函数查询；
 * - 金额用 int（分）；时间列一律 nullable，避免 5.7 的隐式 ON UPDATE；
 * - 只新增表，不改任何存量表结构。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_conversations', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('user_id');
            $table->string('channel', 16)->default('console')->comment('console / site / admin');
            $table->string('subject_type', 32)->nullable()->comment('当前主体类型：product/service/invoice/ticket');
            $table->unsignedBigInteger('subject_id')->nullable();
            $table->string('title', 120)->default('')->comment('首条用户消息摘要');
            $table->string('permission_mode', 16)->default('confirm')->comment('read_only / confirm / auto_approve');
            $table->unsignedInteger('message_count')->default(0);
            $table->unsignedInteger('prompt_tokens')->default(0);
            $table->unsignedInteger('completion_tokens')->default(0);
            $table->unsignedInteger('cost_cents')->default(0);
            $table->timestamp('last_message_at')->nullable();
            $table->tinyInteger('status')->default(1)->comment('1 正常 / 0 用户删除');
            $table->timestamps();

            $table->index(['user_id', 'status'], 'ai_conversations_user_status_index');
            $table->index('last_message_at', 'ai_conversations_last_message_index');
        });

        Schema::create('ai_messages', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('conversation_id');
            $table->string('role', 16)->comment('user / assistant / tool / system');
            $table->longText('content')->nullable();
            $table->text('tool_calls')->nullable()->comment('JSON：工具调用与结果摘要');
            $table->string('tool_call_id', 64)->nullable();
            $table->unsignedInteger('prompt_tokens')->default(0);
            $table->unsignedInteger('completion_tokens')->default(0);
            $table->timestamps();

            $table->index('conversation_id', 'ai_messages_conversation_index');
        });

        Schema::create('ai_tool_invocations', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('conversation_id');
            $table->unsignedBigInteger('message_id')->nullable();
            $table->unsignedBigInteger('user_id');
            $table->string('tool_name', 64);
            $table->string('risk', 16)->default('read')->comment('read / write / dangerous');
            $table->text('arguments')->nullable()->comment('JSON：模型给出的参数');
            $table->text('result')->nullable()->comment('JSON：执行结果摘要');
            $table->string('status', 16)->default('pending')->comment('pending/confirmed/rejected/failed/done');
            $table->string('confirm_token_hash', 64)->nullable();
            $table->string('idempotency_key', 64)->unique('ai_tool_invocations_idempotency_unique');
            $table->string('error_code', 32)->nullable();
            $table->unsignedInteger('duration_ms')->nullable();
            $table->timestamp('confirmed_at')->nullable();
            $table->timestamps();

            $table->index(['conversation_id', 'status'], 'ai_tool_invocations_conversation_status_index');
            $table->index(['user_id', 'created_at'], 'ai_tool_invocations_user_time_index');
        });

        Schema::create('ai_usage_daily', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->date('day');
            $table->unsignedBigInteger('user_id');
            $table->unsignedInteger('messages')->default(0);
            $table->unsignedInteger('prompt_tokens')->default(0);
            $table->unsignedInteger('completion_tokens')->default(0);
            $table->unsignedInteger('cost_cents')->default(0);
            $table->timestamps();

            $table->unique(['day', 'user_id'], 'ai_usage_daily_day_user_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_usage_daily');
        Schema::dropIfExists('ai_tool_invocations');
        Schema::dropIfExists('ai_messages');
        Schema::dropIfExists('ai_conversations');
    }
};
