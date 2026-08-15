<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * 商品控制台页面由商品配置决定，默认使用通用计算控制台。
     */
    public function up(): void
    {
        if (Schema::hasTable('products') && ! Schema::hasColumn('products', 'console_template')) {
            Schema::table('products', function (Blueprint $table): void {
                $table->string('console_template', 32)
                    ->default('compute')
                    ->after('product_type')
                    ->comment('用户控制台模板：compute 或 port_mapping');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('products') && Schema::hasColumn('products', 'console_template')) {
            Schema::table('products', function (Blueprint $table): void {
                $table->dropColumn('console_template');
            });
        }
    }
};
