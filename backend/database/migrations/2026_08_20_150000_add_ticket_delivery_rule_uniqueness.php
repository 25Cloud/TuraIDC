<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('ticket_delivery_rules')) {
            return;
        }

        $indexes = Schema::getIndexListing('ticket_delivery_rules');
        if (DB::table('ticket_delivery_rules')
            ->select('supplier_id', 'department', 'provider_key', 'product_scope_mode')
            ->groupBy('supplier_id', 'department', 'provider_key', 'product_scope_mode')
            ->havingRaw('COUNT(*) > 1')
            ->exists()) {
            throw new RuntimeException('ticket_delivery_rules 存在重复范围规则，无法建立唯一约束');
        }
        if (! in_array('ticket_delivery_rule_scope_unique', $indexes, true)) {
            Schema::table('ticket_delivery_rules', function (Blueprint $table): void {
                $table->unique(
                    ['supplier_id', 'department', 'provider_key', 'product_scope_mode'],
                    'ticket_delivery_rule_scope_unique'
                );
            });
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('ticket_delivery_rules')) {
            return;
        }

        if (in_array('ticket_delivery_rule_scope_unique', Schema::getIndexListing('ticket_delivery_rules'), true)) {
            Schema::table('ticket_delivery_rules', function (Blueprint $table): void {
                $table->dropUnique('ticket_delivery_rule_scope_unique');
            });
        }
    }
};
