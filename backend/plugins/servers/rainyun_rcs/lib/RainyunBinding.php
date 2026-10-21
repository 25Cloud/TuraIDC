<?php

declare(strict_types=1);

namespace TuraIDC\Plugins\Servers\RainyunRcs\Lib;

use App\Exceptions\BusinessException;
use App\Models\Service;
use App\Models\ServiceUpstreamBinding;
use App\Models\Supplier;

final class RainyunBinding
{
    public static function require(Service $service, Supplier $supplier, int $hostId): ServiceUpstreamBinding
    {
        $binding = ServiceUpstreamBinding::query()->with(['plugin', 'supplierPluginBinding'])
            ->where('service_id', $service->id)->latest('id')->first();
        throw_if($binding === null || $binding->provider_key !== 'rainyun_rcs'
            || $binding->plugin?->slug !== 'rainyun_rcs' || $binding->plugin?->domain !== 'upstream'
            || (int) $binding->upstream_service_id !== $hostId
            || (int) $binding->supplierPluginBinding?->supplier_id !== (int) $supplier->id
            || (int) $binding->supplierPluginBinding?->plugin_id !== (int) $binding->plugin_id,
            new BusinessException('当前实例、供应商与雨云 RCS 插件绑定不一致', 42200));

        return $binding;
    }
}
