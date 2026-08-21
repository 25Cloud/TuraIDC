<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use RuntimeException;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('ticket_upstream_bindings')) {
            throw new RuntimeException('ticket_upstream_bindings 表不存在，请先执行前置迁移');
        }
        // 建表外键依赖的表必须存在；DDL 不可回滚，依赖缺失会导致建表中途失败并残留半成品结构。
        foreach (['tickets', 'ticket_replies', 'ticket_reply_deliveries'] as $dependencyTable) {
            if (! Schema::hasTable($dependencyTable)) {
                throw new RuntimeException("{$dependencyTable} 表不存在，请先执行前置迁移");
            }
        }

        // 漂移结构（旧部署已存在同名表/列）必须走独立修复流程，禁止静默跳过或部分执行，
        // 否则 down() 无法判断结构是否由本迁移创建，回滚会误删既有数据。
        if (Schema::hasTable('ticket_upstream_delivery_logs')) {
            throw new RuntimeException('ticket_upstream_delivery_logs 表已存在，请先处理漂移结构再执行迁移');
        }
        if (Schema::hasColumn('ticket_upstream_bindings', 'delivered_at')) {
            throw new RuntimeException('ticket_upstream_bindings.delivered_at 已存在，请先处理漂移结构再执行迁移');
        }

        // 先建日志表再补列：MySQL 的 DDL 不可回滚，若先加 delivered_at 后建表失败会残留半成品结构。
        Schema::create('ticket_upstream_delivery_logs', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('ticket_id')->constrained('tickets')->cascadeOnDelete();
            $table->foreignId('ticket_reply_id')->nullable()->constrained('ticket_replies')->nullOnDelete();
            $table->foreignId('binding_id')->nullable()->constrained('ticket_upstream_bindings')->nullOnDelete();
            $table->foreignId('delivery_id')->nullable()->constrained('ticket_reply_deliveries')->nullOnDelete();
            $table->string('direction', 16)->default('outbound');
            $table->string('operation', 32);
            $table->string('event', 32);
            $table->string('status', 32);
            $table->string('reason_code', 64)->nullable();
            $table->string('provider_key', 64)->nullable();
            $table->unsignedBigInteger('supplier_id')->nullable();
            $table->unsignedInteger('attempt')->nullable();
            $table->unsignedSmallInteger('http_status')->nullable();
            $table->unsignedInteger('duration_ms')->nullable();
            $table->text('message')->nullable();
            $table->timestamp('occurred_at');
            $table->timestamps();
            $table->index(['ticket_id', 'occurred_at'], 'ticket_upstream_log_ticket_time_idx');
            $table->index(['binding_id', 'occurred_at'], 'ticket_upstream_log_binding_time_idx');
            $table->index(['ticket_reply_id', 'occurred_at'], 'ticket_upstream_log_reply_time_idx');
            $table->index(['status', 'occurred_at'], 'ticket_upstream_log_status_time_idx');
        });

        Schema::table('ticket_upstream_bindings', function (Blueprint $table): void {
            $table->timestamp('delivered_at')->nullable()->after('last_attempt_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ticket_upstream_delivery_logs');

        if (Schema::hasTable('ticket_upstream_bindings') && Schema::hasColumn('ticket_upstream_bindings', 'delivered_at')) {
            Schema::table('ticket_upstream_bindings', function (Blueprint $table): void {
                $table->dropColumn('delivered_at');
            });
        }
    }
};
