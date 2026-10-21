<?php

declare(strict_types=1);

namespace App\Services\Upstream\Contracts;

use App\Models\Order;
use App\Models\Service;
use App\Models\Supplier;

/** 已支付的插件扩展订单，仍通过平台支付履约与实例绑定解析执行。 */
interface ProvidesConsolePurchaseFulfillment
{
    public function fulfillConsolePurchase(Order $order, Service $service, Supplier $supplier, int $hostId): Service;
}
