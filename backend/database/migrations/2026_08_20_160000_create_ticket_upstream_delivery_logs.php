<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('ticket_upstream_bindings') && ! Schema::hasColumn('ticket_upstream_bindings', 'delivered_at')) {
            Schema::table('ticket_upstream_bindings', function (Blueprint $table): void {
                $table->timestamp('delivered_at')->nullable()->after('last_attempt_at');
            });
        }

        if (Schema::hasTable('ticket_upstream_delivery_logs')) {
            return;
        }

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
