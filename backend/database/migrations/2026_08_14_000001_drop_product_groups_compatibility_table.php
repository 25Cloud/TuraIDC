<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * 移除 product_groups 兼容表（专家团演进批 M1/M5）。
 *
 * 背景：2026-08-01 分类结构事故修复曾保留 product_groups 作为回滚安全网。
 * 当前代码、schema baseline 与 products.product_group_id 外键均以三层实体表
 * （first/second/third_product_groups）为真源，app/ 内无任何对 product_groups
 * 的读写；项目回归测试 DatabaseOptimizationRegressionTest 显式断言该表必须
 * 不存在。数据已归档至 storage/app/archives/product_groups_20260813_235954.json
 * （43 行），可随时重建。
 *
 * 删除后实库与 baseline、DATABASE.md 三方一致。
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('product_groups')) {
            return;
        }

        Schema::dropIfExists('product_groups');
    }

    public function down(): void
    {
        // 数据已归档，down 不自动恢复；如需重建请从归档 JSON 导入并重建外键。
        DB::statement('CREATE TABLE IF NOT EXISTS `product_groups` (`id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT, PRIMARY KEY (`id`)) ENGINE=InnoDB');
    }
};
