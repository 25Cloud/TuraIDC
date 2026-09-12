<?php

declare(strict_types=1);

namespace App\Services\Upstream\Contracts;

use App\Models\Order;
use App\Models\Service;
use App\Models\Supplier;

/**
 * 直接下单开通协议：驱动一次性完成「上游建单 + 支付 + 交付」并返回开通结果。
 *
 * 与基础 ProvidesProvisioning（通用 REST 购物车协议）的区别：hosting_panel_api
 * transport 只会讲通用协议（购物车/账单/主机 REST 端点），编排层需要为它走
 * 内联购物车流程；实现本接口的驱动（如 ZJMF 财务适配器）则由驱动内部完成
 * 整个开通会话，编排层只需调用 provisionOrder()。
 */
interface ProvidesOrderProvisioning extends ProvidesProvisioning
{
    /**
     * @param  ?Service  $existingService  幂等回查用的本地既有实例，驱动必须先核对上游
     *                                   是否已存在对应主机，避免重复开通
     * @return array{upstream_invoice_id?: int, upstream_host_id?: int|string, upstream_host_ids?: array<int, int|string>, host_detail?: array<string, mixed>, requested_host?: string}
     */
    public function provisionOrder(Order $order, Supplier $supplier, ?Service $existingService = null): array;
}
