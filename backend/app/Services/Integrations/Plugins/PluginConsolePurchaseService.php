<?php

declare(strict_types=1);

namespace App\Services\Integrations\Plugins;

use App\Constants\InvoiceStatus;
use App\Constants\OrderStatus;
use App\Exceptions\BusinessException;
use App\Models\Order;
use App\Models\Service;
use App\Models\ServiceUpstreamBinding;
use App\Services\ClientServiceConsole\ServiceDetailService;
use App\Services\Upstream\Contracts\ProvidesConsolePurchaseFulfillment;

final class PluginConsolePurchaseService
{
    public function __construct(private readonly ServiceDetailService $detailService) {}

    public function fulfill(Order $order): Service
    {
        $order->loadMissing(['invoice', 'service']);
        $service = $order->service;
        throw_if(! $service instanceof Service || ! $order->invoice
            || (int) $order->invoice->status !== InvoiceStatus::PAID
            || (int) $service->user_id !== (int) $order->user_id
            || (int) $order->invoice->user_id !== (int) $order->user_id,
            new BusinessException('插件订单未支付或实例归属不一致', 42200));
        [$runtime, $supplier, $hostId] = $this->detailService->resolveUpstreamContext($service);
        throw_if(! $runtime instanceof ProvidesConsolePurchaseFulfillment, new BusinessException('当前上游不支持该插件订单履约', 42200));
        $meta = (array) data_get($order->config_pricing_snapshot, 'meta', []);
        $binding = ServiceUpstreamBinding::query()->where('service_id', $service->id)->latest('id')->first();
        throw_if(($meta['kind'] ?? '') !== 'plugin_purchase'
            || $binding === null
            || (int) ($meta['plugin_id'] ?? 0) <= 0
            || (int) ($meta['plugin_id'] ?? 0) !== (int) $binding->plugin_id
            || (int) ($meta['binding_id'] ?? 0) !== (int) $binding->id
            || (string) ($meta['provider'] ?? '') !== (string) $binding->provider_key
            || (int) ($meta['supplier_id'] ?? 0) !== (int) $supplier->id
            || (int) ($meta['host_id'] ?? 0) !== $hostId,
            new BusinessException('实例绑定已变更，不能执行原插件订单', 42200));
        $result = $runtime->fulfillConsolePurchase($order, $service, $supplier, $hostId);
        throw_if((int) $order->fresh()->status !== OrderStatus::COMPLETED, new BusinessException('插件订单尚未完成履约', 42200));

        return $result;
    }
}
