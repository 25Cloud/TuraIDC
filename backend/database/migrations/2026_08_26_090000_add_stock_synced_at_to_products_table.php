<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 商品库存的最近同步时间。
 *
 * 上游库存同步必须逐商品查询（实测魔方 /cart/all 列表里的 stock_control 不可信：
 * 24 个抽样中 14 个与商品详情接口不一致，其中 2 个实际已售罄却报"不限量"），
 * 商品多时一次全量刷新会打出成百上千个请求，容易被上游风控判成攻击。
 *
 * 因此改为分批轮转：每轮只同步"最久未同步"的一批，本列即轮转依据。
 * 为空表示从未同步过，排序时优先级最高。
 *
 * nullable 且带显式 DEFAULT NULL：规避 MySQL 5.7 在
 * explicit_defaults_for_timestamp=OFF 下给首个无默认值 timestamp 列附加隐式
 * ON UPDATE CURRENT_TIMESTAMP 的行为（products 表首个时间列本就有默认值，
 * 这里仍按规范写，避免后续调整列序时踩雷）。
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('products', 'stock_synced_at')) {
            return;
        }

        Schema::table('products', function (Blueprint $table): void {
            $table->timestamp('stock_synced_at')
                ->nullable()
                ->after('stock')
                ->comment('上游库存最近同步时间，为空表示从未同步');
            $table->index('stock_synced_at', 'products_stock_synced_at_index');
        });
    }

    public function down(): void
    {
        if (! Schema::hasColumn('products', 'stock_synced_at')) {
            return;
        }

        Schema::table('products', function (Blueprint $table): void {
            $table->dropIndex('products_stock_synced_at_index');
            $table->dropColumn('stock_synced_at');
        });
    }
};
