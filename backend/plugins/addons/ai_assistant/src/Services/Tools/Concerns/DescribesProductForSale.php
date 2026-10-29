<?php

declare(strict_types=1);

namespace TuraIDC\Plugins\Addons\AiAssistant\Services\Tools\Concerns;

use App\Models\Product;
use App\Support\ProductGroupHierarchyFields;
use App\Support\PublicUrl;
use Throwable;

/**
 * 把商品翻译成「导购能直接说给用户听」的一行。
 *
 * 为什么需要它：`products.custom_display_name` 只存规范规格摘要（`2核2G20Mbps`），
 * 区域/机房信息全在分组名里（一级=业务类型，二级=大区，三级=机房或产品线）。
 * 工具此前只把 `name` 交给模型，于是模型只能说「2核2G20M」——用户根本不知道是哪台机器、
 * 在哪个区。这里一次补齐三件事：完整叫法、分组路径、可点链接，三个商品类工具共用。
 *
 * 链接口径必须与官网一致（见 frontend-user-v3-www/src/utils/productRoute.js）：
 *   /products/{一级slug}/{二级id}/{三级id}/{商品id}   商品配置/购买页
 *   /products/{一级slug}/{二级id}/{三级id}            该三级分组目录页
 * 缺任一段就退回旧版独立配置页 /products/{商品id}（官网仍兼容），绝不拼出会 404 的地址。
 */
trait DescribesProductForSale
{
    /**
     * @param  string|null  $monthlyPrice  已算好的月付挂牌价；null 表示本次不报价
     * @return array<string, mixed>
     */
    protected function describeProductForSale(Product $product, ?string $monthlyPrice = null): array
    {
        $hierarchy = ProductGroupHierarchyFields::fromProduct($product);

        $spec = trim((string) ($product->custom_display_name ?? ''));
        if ($spec === '') {
            // custom_display_name 为空的历史商品才走解析器；它可能触发额外查询，
            // 所以只在必要时取一次。
            $spec = trim((string) $product->name);
        }

        $typeName = trim((string) ($hierarchy['first_product_group_name'] ?? ''));
        $typeLabel = trim((string) ($hierarchy['product_type_label'] ?? ''));
        $region = trim((string) ($hierarchy['second_product_group_name'] ?? ''));
        $site = trim((string) ($hierarchy['third_product_group_name'] ?? ''));
        $groupPath = trim((string) ($hierarchy['group_path_text'] ?? ''));

        // 「区域 · 机房/产品线 · 规格」：二级分组在本站就是大区（华中/香港/美国…），
        // 三级是具体机房或线路（湖北襄阳 电信 / 三网精品铂金原生）。重复段去重，
        // 避免出现「香港 / 香港 / 2核2G」这种念起来别扭的叫法。
        $title = implode(' · ', array_values(array_unique(array_filter(
            [$region, $site, $spec],
            static fn (string $part): bool => $part !== '',
        ))));
        if ($title === '') {
            $title = trim((string) $product->name);
        }

        $urls = $this->productUrlsFor($product, $hierarchy);
        $stock = (int) ($product->stock ?? -1);

        return [
            'product_id' => (int) $product->id,
            // 给用户看的完整叫法，模型直接引用即可
            'title' => $title,
            'spec' => $spec,
            'region' => $region,
            'site' => $site,
            'group_path' => $groupPath !== '' ? $groupPath : $typeName,
            'product_type' => $typeLabel !== '' ? $typeLabel : $typeName,
            'monthly_price' => $monthlyPrice,
            'stock' => $stock,
            // -1 = 不限库存；0 = 售罄。模型据此决定要不要把「暂时缺货」说出来
            'in_stock' => $stock !== 0,
            'product_url' => $urls['product'],
            'group_url' => $urls['group'],
            // 现成的 markdown 链接，模型照抄就不会写错地址
            'link' => '['.$title.']('.$urls['product'].')',
        ];
    }

    /**
     * @param  array<string, mixed>  $hierarchy
     * @return array{product: string, group: string}
     */
    private function productUrlsFor(Product $product, array $hierarchy): array
    {
        $slug = trim((string) ($hierarchy['first_product_group_slug'] ?? ''));
        $secondId = (int) ($hierarchy['second_product_group_id'] ?? 0);
        $thirdId = (int) ($hierarchy['third_product_group_id'] ?? 0);
        $productId = (int) $product->id;

        // 默认值就是官网兼容的旧版深链，任何分支都能打开
        $productPath = '/products/'.$productId;
        $groupPath = '/products';

        if ($slug !== '' && $secondId > 0) {
            $groupPath = $thirdId > 0
                ? "/products/{$slug}/{$secondId}/{$thirdId}"
                : "/products/{$slug}/{$secondId}";

            $productPath = $thirdId > 0
                ? "/products/{$slug}/{$secondId}/{$thirdId}/{$productId}"
                : "/products/{$slug}/{$secondId}/{$productId}";
        }

        return [
            'product' => $this->absoluteWebsiteUrl($productPath),
            'group' => $this->absoluteWebsiteUrl($groupPath),
        ];
    }

    /**
     * 官网地址取自 app.frontend_url（PublicUrl 的唯一真源）。
     *
     * 该配置缺失或格式非法时 PublicUrl 会抛 LogicException；站点地址没配不该让
     * 「查商品」整体失败，退回相对路径——模型给出的链接在官网内仍然可点。
     */
    private function absoluteWebsiteUrl(string $path): string
    {
        try {
            return PublicUrl::website($path);
        } catch (Throwable) {
            return $path;
        }
    }
}
