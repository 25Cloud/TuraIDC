<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('supplier_plugin_bindings') && ! Schema::hasColumn('supplier_plugin_bindings', 'ticket_delivery_enabled')) {
            Schema::table('supplier_plugin_bindings', function (Blueprint $table): void {
                $table->boolean('ticket_delivery_enabled')->default(false)->after('status');
                $table->index(['provider_key', 'ticket_delivery_enabled'], 'supplier_binding_ticket_delivery_idx');
            });
        }

        if (Schema::hasTable('ticket_delivery_rules')) {
            $hasSupplierId = Schema::hasColumn('ticket_delivery_rules', 'supplier_id');
            $hasScopeMode = Schema::hasColumn('ticket_delivery_rules', 'product_scope_mode');
            $hasSettingsIndex = Schema::hasIndex('ticket_delivery_rules', 'ticket_delivery_rule_settings_idx');
            Schema::table('ticket_delivery_rules', function (Blueprint $table) use ($hasSupplierId, $hasScopeMode, $hasSettingsIndex): void {
                if (! $hasSupplierId) {
                    $table->foreignId('supplier_id')->nullable()->after('department')->constrained('suppliers')->nullOnDelete();
                }
                if (! $hasScopeMode) {
                    $table->string('product_scope_mode', 16)->default('selected')->after('provider_key');
                }
                if (! $hasSettingsIndex) {
                    $table->index(['supplier_id', 'department', 'provider_key', 'enabled'], 'ticket_delivery_rule_settings_idx');
                }
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('ticket_delivery_rules')) {
            Schema::table('ticket_delivery_rules', function (Blueprint $table): void {
                if (Schema::hasIndex('ticket_delivery_rules', 'ticket_delivery_rule_settings_idx')) {
                    $table->dropIndex('ticket_delivery_rule_settings_idx');
                }
                if (Schema::hasColumn('ticket_delivery_rules', 'supplier_id')) {
                    $table->dropForeign(['supplier_id']);
                    $table->dropColumn('supplier_id');
                }
                if (Schema::hasColumn('ticket_delivery_rules', 'product_scope_mode')) {
                    $table->dropColumn('product_scope_mode');
                }
            });
        }

        if (Schema::hasTable('supplier_plugin_bindings') && Schema::hasColumn('supplier_plugin_bindings', 'ticket_delivery_enabled')) {
            Schema::table('supplier_plugin_bindings', function (Blueprint $table): void {
                if (Schema::hasIndex('supplier_plugin_bindings', 'supplier_binding_ticket_delivery_idx')) {
                    $table->dropIndex('supplier_binding_ticket_delivery_idx');
                }
                $table->dropColumn('ticket_delivery_enabled');
            });
        }
    }
};
