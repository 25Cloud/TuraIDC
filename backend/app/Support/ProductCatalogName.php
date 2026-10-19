<?php

declare(strict_types=1);

namespace App\Support;

/**
 * 商品展示名（目录口径）：三级分组名 + 规范规格摘要。
 *
 * 口径由来：上游商品名把线路描述和规格混着写（"宿迁AMD 锐龙 Threadripper
 * 3960X三线BGP 2G2H"、"枣庄BGP 13900K 2核2G"、"2vCPU2GiB"），同一目录里
 * 横向比较完全对不上。改成「分组名 + 规格」后：
 *
 *   宿迁BGP Threadripper 3960X 2核2G30Mbps
 *   枣庄BGP I9-13900K 2核2G30Mbps
 *   湖北襄阳 电信 2核2G20Mbps
 *
 * 带上分组名，是为了让脱离目录上下文的页面（控制台实例列表、订单、后台商品
 * 列表）也能一眼看出是哪个产品线；规格摘要由
 * {@see \App\Services\ProductCatalog\ProductSpecSummaryBuilder} 按结构化配置
 * 算出，格式统一。
 *
 * 没有规格摘要的商品（虚拟主机、CDN、挂机宝等）沿用原名：它们的原名本身就是
 * 线路描述，硬拼分组名会变成「大带宽 香港HK 大带宽主机-A」这种重复。
 */
final class ProductCatalogName
{
    /**
     * @param  array<string, mixed>  $hierarchy  ProductGroupHierarchyFields::fromProduct() 的结果
     * @param  array<string, mixed>  $display    ProductDisplayNameResolver 的结果
     */
    public static function compose(array $hierarchy, array $display): string
    {
        $summary = trim((string) ($display['product_spec_summary'] ?? ''));
        $fallback = trim((string) ($display['product_display_name'] ?? ''));

        if ($summary === '') {
            return $fallback;
        }

        $group = trim((string) ($hierarchy['third_product_group_name'] ?? ''));
        if ($group === '') {
            $group = trim((string) ($hierarchy['second_product_group_name'] ?? ''));
        }

        return $group === '' ? $summary : $group.' '.$summary;
    }
}
