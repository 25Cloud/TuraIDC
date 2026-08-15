<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 专家团结构批：account_transactions 的多态 source_type/source_id
 * 用于"余额变动 → 业务来源(账单/支付/提现)"的回追，此前无索引，
 * 对账与回溯查询只能全表扫描。
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('account_transactions')) {
            return;
        }

        if (! Schema::hasIndex('account_transactions', 'account_transactions_source_idx')) {
            Schema::table('account_transactions', function (Blueprint $table): void {
                $table->index(['source_type', 'source_id'], 'account_transactions_source_idx');
            });
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('account_transactions')) {
            return;
        }

        if (Schema::hasIndex('account_transactions', 'account_transactions_source_idx')) {
            Schema::table('account_transactions', function (Blueprint $table): void {
                $table->dropIndex('account_transactions_source_idx');
            });
        }
    }
};
