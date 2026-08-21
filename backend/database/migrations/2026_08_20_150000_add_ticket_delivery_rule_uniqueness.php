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

        // 归一化 supplier_id 为空的安全唯一键：NULL 供应商范围统一映射为 0，
        // 使 (supplier_id, department, ...) 的空值重复记录也能被唯一约束拦截。
        if (! Schema::hasColumn('ticket_delivery_rules', 'supplier_scope_key')) {
            DB::statement('ALTER TABLE ticket_delivery_rules ADD COLUMN supplier_scope_key BIGINT UNSIGNED GENERATED ALWAYS AS (COALESCE(supplier_id, 0)) STORED');
        }

        if (DB::table('ticket_delivery_rules')
            ->select('supplier_scope_key', 'department', 'provider_key', 'product_scope_mode')
            ->groupBy('supplier_scope_key', 'department', 'provider_key', 'product_scope_mode')
            ->havingRaw('COUNT(*) > 1')
            ->exists()) {
            throw new RuntimeException('ticket_delivery_rules 存在重复范围规则，无法建立唯一约束');
        }

        $indexes = Schema::getIndexListing('ticket_delivery_rules');
        if (in_array('ticket_delivery_rule_scope_unique', $indexes, true)) {
            Schema::table('ticket_delivery_rules', function (Blueprint $table): void {
                $table->dropUnique('ticket_delivery_rule_scope_unique');
            });
        }
        Schema::table('ticket_delivery_rules', function (Blueprint $table): void {
            $table->unique(
                ['supplier_scope_key', 'department', 'provider_key', 'product_scope_mode'],
                'ticket_delivery_rule_scope_unique'
            );
        });
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
        if (Schema::hasColumn('ticket_delivery_rules', 'supplier_scope_key')) {
            DB::statement('ALTER TABLE ticket_delivery_rules DROP COLUMN supplier_scope_key');
        }
    }
};
