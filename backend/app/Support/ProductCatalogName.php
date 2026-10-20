<?php

declare(strict_types=1);

namespace App\Support;

/**
 * 商品展示名（目录口径）：只保留规范规格摘要。
 *
 * 口径历史：上游商品名把线路描述和规格混着写（"宿迁AMD 锐龙 Threadripper
 * 3960X三线BGP 2G2H"、"枣庄BGP 13900K 2核2G"、"2vCPU2GiB"），同一目录里
 * 横向比较完全对不上。早先改成「分组名 + 规格」，但商品名在实例、订单、卡片
 * 各处重复分组信息，又长又难读；分组层级改由 product_full_path 等字段单独提供。
 * 现在商品名只放规格摘要：
 *
 *   2核2G30M
 *   2核2G20M
 *
 * 规格摘要由 {@see \App\Services\ProductCatalog\ProductSpecSummaryBuilder} 按
 * 结构化配置算出，格式统一。
 *
 * 没有规格摘要的商品（虚拟主机、CDN、挂机宝等）沿用原名：它们的原名本身就是
 * 线路描述，硬拼规格会变成「大带宽主机-A 2核2G」这种重复。
 */
final class ProductCatalogName
{
    /**
     * @param  array<string, mixed>  $hierarchy  ProductGroupHierarchyFields::fromProduct() 的结果
     *                                           （分组路径不再进商品名，保留参数以复用调用方签名）
     * @param  array<string, mixed>  $display    ProductDisplayNameResolver 的结果
     */
    public static function compose(array $hierarchy, array $display): string
    {
        $summary = trim((string) ($display['product_spec_summary'] ?? ''));
        $fallback = trim((string) ($display['product_display_name'] ?? ''));

        return $summary === '' ? $fallback : $summary;
    }
}
