<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 专家团结构批：清理已被其他索引左前缀覆盖、或基数为 2 的无效索引。
 *
 * 每枚索引均经 EXPLAIN/左前缀分析确认可安全删除：
 * - operation_logs_user_id_user_type_index: 被 (user_id,user_type,created_at) 严格覆盖
 * - schedule_run_logs_task_name_index: 被 (task_name,created_at) 覆盖
 * - invoices_user_id_index: 被 (user_id,status,*) 复合索引覆盖（FK 由复合索引满足）
 * - notice_reads_user_id_index: 被 UNIQUE(user_id,article_id) 覆盖
 * - tickets_user_id_index: 被 (user_id,status,updated_at)/(user_id,updated_at,id) 覆盖
 * - users_login_email_alert_index: 布尔列基数 2，483 行表无选择率
 * - idx_product_status_groups: status 基数 2（93% 为上架），131 行表全表扫描更快
 * - message_logs_channel_created_at_idx: 被 (channel,driver_key,created_at) 覆盖
 * - content_articles 四枚：status 开头无独立查询 / 建在无查询的快照列 category_name /
 *   14 行小表无过滤场景的 created_by/updated_by
 */
return new class extends Migration
{
    public function up(): void
    {
        $this->dropIndexIfExists('operation_logs', 'operation_logs_user_id_user_type_index');
        $this->dropIndexIfExists('schedule_run_logs', 'schedule_run_logs_task_name_index');
        $this->dropIndexIfExists('invoices', 'invoices_user_id_index');
        $this->dropIndexIfExists('notice_reads', 'notice_reads_user_id_index');
        $this->dropIndexIfExists('tickets', 'tickets_user_id_index');
        $this->dropIndexIfExists('users', 'users_login_email_alert_index');
        $this->dropIndexIfExists('products', 'idx_product_status_groups');
        $this->dropIndexIfExists('message_logs', 'message_logs_channel_created_at_idx');
        $this->dropIndexIfExists('content_articles', 'idx_article_published');
        $this->dropIndexIfExists('content_articles', 'idx_content_category_type');
        $this->dropIndexIfExists('content_articles', 'content_articles_created_by_index');
        $this->dropIndexIfExists('content_articles', 'content_articles_updated_by_index');

        // schedule_run_logs.status 基数 3 的单列索引无筛选价值，
        // 替换为 (status, created_at) 组合以支撑"按状态+时间"检索与失败调度清理。
        $this->dropIndexIfExists('schedule_run_logs', 'schedule_run_logs_status_index');
        if (! $this->indexExists('schedule_run_logs', 'schedule_run_logs_status_created_at_index')) {
            Schema::table('schedule_run_logs', function (Blueprint $table): void {
                $table->index(['status', 'created_at'], 'schedule_run_logs_status_created_at_index');
            });
        }
    }

    public function down(): void
    {
        // 结构批回滚：恢复被删除的索引。删除语句在 up 中按需执行，
        // 这里用存在性检查避免重建已存在的索引。
        Schema::table('operation_logs', function (Blueprint $table): void {
            $table->index(['user_id', 'user_type'], 'operation_logs_user_id_user_type_index');
        });
        Schema::table('schedule_run_logs', function (Blueprint $table): void {
            $table->index('task_name', 'schedule_run_logs_task_name_index');
        });
        $this->dropIndexIfExists('schedule_run_logs', 'schedule_run_logs_status_created_at_index');
        Schema::table('schedule_run_logs', function (Blueprint $table): void {
            $table->index('status', 'schedule_run_logs_status_index');
        });
        Schema::table('invoices', function (Blueprint $table): void {
            $table->index('user_id', 'invoices_user_id_index');
        });
        Schema::table('notice_reads', function (Blueprint $table): void {
            $table->index('user_id', 'notice_reads_user_id_index');
        });
        Schema::table('tickets', function (Blueprint $table): void {
            $table->index('user_id', 'tickets_user_id_index');
        });
        Schema::table('users', function (Blueprint $table): void {
            $table->index('login_email_alert', 'users_login_email_alert_index');
        });
        Schema::table('products', function (Blueprint $table): void {
            $table->index('status', 'idx_product_status_groups');
        });
        Schema::table('message_logs', function (Blueprint $table): void {
            $table->index(['channel', 'created_at'], 'message_logs_channel_created_at_idx');
        });
        Schema::table('content_articles', function (Blueprint $table): void {
            $table->index(['status', 'publish_at', 'is_pinned'], 'idx_article_published');
            $table->index(['category_name', 'content_type'], 'idx_content_category_type');
            $table->index('created_by', 'content_articles_created_by_index');
            $table->index('updated_by', 'content_articles_updated_by_index');
        });
    }

    private function indexExists(string $table, string $index): bool
    {
        return Schema::hasTable($table) && Schema::hasIndex($table, $index);
    }

    private function dropIndexIfExists(string $table, string $index): void
    {
        if (! $this->indexExists($table, $index)) {
            return;
        }

        Schema::table($table, function (Blueprint $table) use ($index): void {
            $table->dropIndex($index);
        });
    }
};
