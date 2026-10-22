<?php

declare(strict_types=1);

use App\Services\Upstream\Contracts\ProvidesBatchStatusSync;
use App\Services\Upstream\Contracts\ProvidesBrowserConsole;
use App\Services\Upstream\Contracts\ProvidesConsoleAccess;
use App\Services\Upstream\Contracts\ProvidesConsoleCatalog;
use App\Services\Upstream\Contracts\ProvidesConsolePurchaseFulfillment;
use App\Services\Upstream\Contracts\ProvidesConsoleRuntime;
use App\Services\Upstream\Contracts\ProvidesHostTermination;
use App\Services\Upstream\Contracts\ProvidesInvoiceRenewal;
use App\Services\Upstream\Contracts\ProvidesLocalConsoleActions;
use App\Services\Upstream\Contracts\ProvidesOrderProvisioning;
use App\Services\Upstream\Contracts\ProvidesProvisioning;
use App\Services\Upstream\Contracts\ProvidesReinstallTimeout;
use App\Services\Upstream\Contracts\ProvidesRenewableCycleFiltering;
use App\Services\Upstream\Contracts\ProvidesRenewal;
use App\Services\Upstream\Contracts\ProvidesStatusSync;
use App\Services\Upstream\Contracts\ProvidesSupplierBalance;
use TuraIDC\Plugins\Servers\RainyunRcs\RainyunRcsPlugin;

return [
    'info' => ['domain' => 'upstream', 'slug' => 'rainyun_rcs', 'key' => 'rainyun_rcs', 'name' => 'RCS',
        'version' => '1.0.0', 'entry' => RainyunRcsPlugin::class, 'capabilities' => [ProvidesConsoleCatalog::class, ProvidesConsoleRuntime::class, ProvidesConsoleAccess::class,
            ProvidesBrowserConsole::class, ProvidesProvisioning::class, ProvidesOrderProvisioning::class, ProvidesRenewal::class,
            ProvidesInvoiceRenewal::class, ProvidesRenewableCycleFiltering::class, ProvidesStatusSync::class, ProvidesBatchStatusSync::class,
            ProvidesSupplierBalance::class, ProvidesHostTermination::class, ProvidesLocalConsoleActions::class, ProvidesConsolePurchaseFulfillment::class, ProvidesReinstallTimeout::class]],
    'config' => ['notice' => ['type' => 'notice', 'title' => '配置说明',
        'content' => '在供应商中绑定 RCS 插件并填写 API 地址、密钥和附加服务销售价格；插件重装会自动关机。']],
];
