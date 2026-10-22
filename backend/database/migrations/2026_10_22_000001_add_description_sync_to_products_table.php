<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 商品描述的上游同步开关与最近同步时间。
 *
 * 描述真源仍是 products.description（HTML），本迁移只加两个状态列：
 *   - description_sync_enabled：商品是否参与定时同步上游描述（0/1）；
 *   - description_synced_at：最近一次从上游拉取描述的时间。
 *
 * 不加「描述来源」列：人工填写与上游同步共用同一个字段，覆盖与否由
 * description_sync_enabled 表达，避免同一份数据出现第二个真源。
 *
 * nullable 且显式 DEFAULT NULL：规避 MySQL 5.7 在 explicit_defaults_for_timestamp=OFF 下
 * 给首个无默认值 timestamp 列附加隐式 ON UPDATE CURRENT_TIMESTAMP 的行为。
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('products', 'description_sync_enabled')) {
            Schema::table('products', function (Blueprint $table): void {
                $table->tinyInteger('description_sync_enabled')
                    ->default(0)
                    ->after('description')
                    ->comment('商品描述自动同步上游开关：1=参与定时同步');
            });
        }

        if (! Schema::hasColumn('products', 'description_synced_at')) {
            Schema::table('products', function (Blueprint $table): void {
                $table->timestamp('description_synced_at')
                    ->nullable()
                    ->after('description_sync_enabled')
                    ->comment('上游商品描述最近同步时间，为空表示从未同步');
            });
        }

        if (! Schema::hasIndex('products', ['description_sync_enabled'])) {
            Schema::table('products', function (Blueprint $table): void {
                $table->index('description_sync_enabled', 'products_description_sync_enabled_index');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasIndex('products', ['description_sync_enabled'])) {
            Schema::table('products', function (Blueprint $table): void {
                $table->dropIndex('products_description_sync_enabled_index');
            });
        }

        Schema::table('products', function (Blueprint $table): void {
            if (Schema::hasColumn('products', 'description_synced_at')) {
                $table->dropColumn('description_synced_at');
            }

            if (Schema::hasColumn('products', 'description_sync_enabled')) {
                $table->dropColumn('description_sync_enabled');
            }
        });
    }
};
