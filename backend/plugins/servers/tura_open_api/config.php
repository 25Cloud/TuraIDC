<?php

declare(strict_types=1);

use App\Services\Upstream\Contracts\ProvidesBatchStatusSync;
use App\Services\Upstream\Contracts\ProvidesConsoleCatalog;
use App\Services\Upstream\Contracts\ProvidesConsoleRuntime;
use App\Services\Upstream\Contracts\ProvidesInvoiceRenewal;
use App\Services\Upstream\Contracts\ProvidesOrderProvisioning;
use App\Services\Upstream\Contracts\ProvidesProvisioning;
use App\Services\Upstream\Contracts\ProvidesRenewal;
use App\Services\Upstream\Contracts\ProvidesRenewalRecovery;
use App\Services\Upstream\Contracts\ProvidesStatusSync;
use App\Services\Upstream\Contracts\ProvidesSupplierBalance;
use TuraIDC\Plugins\Servers\TuraOpenApi\TuraOpenApiPlugin;

return [
    'info' => [
        'domain' => 'upstream',
        'slug' => 'tura_open_api',
        'key' => 'tura_open_api',
        'name' => 'TuraIDC 开放接口',
        'version' => '1.0.0',
        'entry' => TuraOpenApiPlugin::class,
        // 基契约必须列出：PluginUpstreamDriver 按本清单判定 supports()，
        // 编排层以 ProvidesProvisioning/ProvidesRenewal/ProvidesStatusSync 作为能力门槛
        'capabilities' => [
            ProvidesBatchStatusSync::class,
            ProvidesConsoleCatalog::class,
            ProvidesConsoleRuntime::class,
            ProvidesInvoiceRenewal::class,
            ProvidesOrderProvisioning::class,
            ProvidesProvisioning::class,
            ProvidesRenewal::class,
            ProvidesRenewalRecovery::class,
            ProvidesStatusSync::class,
            ProvidesSupplierBalance::class,
        ],
    ],
    'config' => [
        'tura_open_api_notice' => [
            'title' => '配置说明',
            'type' => 'notice',
            'theme' => 'info',
            'content' => '对接上游 TuraIDC 实例的开放接口（/api/v2/open）。接口地址填上游站点根地址，API 密钥填在上游「API 密钥」页创建的完整密钥（仅创建时展示一次），需具备 products/orders/services/finance 读写权限且上游账户余额充足。',
        ],
        'provider_key' => [
            'title' => '上游标识',
            'type' => 'readonly',
            'value' => 'tura_open_api',
            'description' => '供应商绑定 provider_key 为 tura_open_api 时使用本插件，纯自有协议对接，不走 ZJMF 财务兼容链路。',
        ],
    ],
];
