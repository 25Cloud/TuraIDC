<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('ticket_delivery_rules')) {
            Schema::create('ticket_delivery_rules', function (Blueprint $table): void {
                $table->id();
                $table->string('name', 120);
                $table->string('department', 32);
                $table->unsignedBigInteger('supplier_id')->nullable();
                $table->string('provider_key', 64);
                $table->string('product_scope_mode', 16)->default('selected');
                $table->string('upstream_department_id', 64);
                $table->boolean('enabled')->default(true);
                $table->boolean('sync_admin_replies')->default(false);
                $table->boolean('auto_reply_enabled')->default(false);
                $table->text('auto_reply_content')->nullable();
                $table->text('mask_keywords')->nullable();
                $table->timestamps();
                $table->foreign('supplier_id')->references('id')->on('suppliers')->nullOnDelete();
                $table->index(['supplier_id', 'department', 'provider_key', 'enabled'], 'ticket_delivery_rule_match_idx');
            });
        }

        if (! Schema::hasTable('ticket_delivery_rule_products')) {
            Schema::create('ticket_delivery_rule_products', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('rule_id')->constrained('ticket_delivery_rules')->cascadeOnDelete();
                $table->foreignId('product_id')->constrained('products')->cascadeOnDelete();
                $table->unique(['rule_id', 'product_id']);
            });
        }

        if (! Schema::hasTable('ticket_upstream_bindings')) {
            Schema::create('ticket_upstream_bindings', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('ticket_id')->constrained('tickets')->cascadeOnDelete();
                $table->string('provider_key', 64);
                $table->unsignedBigInteger('supplier_id')->nullable();
                $table->string('upstream_department_id', 64);
                $table->string('upstream_service_id', 128);
                $table->string('upstream_ticket_id', 128)->nullable();
                $table->string('status', 32)->default('pending');
                $table->unsignedInteger('attempts')->default(0);
                $table->text('last_error')->nullable();
                $table->timestamp('last_attempt_at')->nullable();
                $table->timestamps();
                $table->unique('ticket_id');
                $table->unique(['provider_key', 'upstream_ticket_id']);
                $table->index(['upstream_service_id', 'upstream_ticket_id'], 'ticket_upstream_lookup_idx');
            });
        }

        if (! Schema::hasTable('ticket_reply_deliveries')) {
            Schema::create('ticket_reply_deliveries', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('ticket_reply_id')->constrained('ticket_replies')->cascadeOnDelete();
                $table->string('direction', 16);
                $table->string('content_prefix', 64)->nullable();
                $table->string('status', 32)->default('pending');
                $table->string('idempotency_key', 160);
                $table->string('remote_event_id', 160)->nullable();
                $table->unsignedInteger('attempts')->default(0);
                $table->text('last_error')->nullable();
                $table->timestamp('delivered_at')->nullable();
                $table->timestamps();
                $table->unique('ticket_reply_id');
                $table->unique('idempotency_key');
                $table->unique(['remote_event_id', 'direction']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('ticket_reply_deliveries');
        Schema::dropIfExists('ticket_upstream_bindings');
        Schema::dropIfExists('ticket_delivery_rule_products');
        Schema::dropIfExists('ticket_delivery_rules');
    }
};
