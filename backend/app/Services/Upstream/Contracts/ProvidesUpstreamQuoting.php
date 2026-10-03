<?php

declare(strict_types=1);

namespace App\Services\Upstream\Contracts;

use App\Models\Supplier;

/**
 * 上游实时报价能力。
 *
 * 转售（代理）商品本地往往没有配置项单价——上游开放接口与站点目录的
 * config_options 都不暴露价格，真实价格全靠上游自己的实时报价接口计算。
 * 实现该能力的插件应提供 quoteUpstreamProduct()，由订单报价逻辑在
 * 商品绑定到上游且插件支持时调用，用上游返回的配置加价覆盖本地空算的结果。
 */
interface ProvidesUpstreamQuoting
{
    /**
     * 向上游拉取某商品的实时报价（解包后的 data）。
     *
     * @param  array<string, mixed>  $config   归一化后的配置（field => 业务值，range 型为数字）
     * @return array<string, mixed>            上游返回的 data（含 base_amount / config_amount / items / total_amount 等）
     */
    public function quoteUpstreamProduct(Supplier $supplier, int $upstreamProductId, array $config, string $billingCycle, int $quantity): array;
}
