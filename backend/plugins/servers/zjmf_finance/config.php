<?php

declare(strict_types=1);

use App\Services\Upstream\Contracts\ProvidesConsoleAccess;
use App\Services\Upstream\Contracts\ProvidesConsoleCatalog;
use App\Services\Upstream\Contracts\ProvidesConsoleNetwork;
use App\Services\Upstream\Contracts\ProvidesConsoleRuntime;
use App\Services\Upstream\Contracts\ProvidesConsoleSecurity;
use App\Services\Upstream\Contracts\ProvidesProvisioning;
use App\Services\Upstream\Contracts\ProvidesRenewal;
use App\Services\Upstream\Contracts\ProvidesScheduledAuthRefresh;
use App\Services\Upstream\Contracts\ProvidesStatusSync;
use TuraIDC\Plugins\Servers\ZjmfFinance\Lib\ZjmfInventoryAndServiceSyncHook;
use TuraIDC\Plugins\Servers\ZjmfFinance\Lib\ZjmfInventoryAndServiceSyncTask;
use TuraIDC\Plugins\Servers\ZjmfFinance\Lib\ZjmfScheduledAuthRefreshHook;
use TuraIDC\Plugins\Servers\ZjmfFinance\Lib\ZjmfScheduledAuthRefreshTask;
use TuraIDC\Plugins\Servers\ZjmfFinance\Providers\ZjmfFinanceServiceProvider;
use TuraIDC\Plugins\Servers\ZjmfFinance\ZjmfFinancePlugin;

return [
    'info' => [
        'domain' => 'upstream',
        'slug' => 'zjmf_finance',
        'key' => 'zjmf_finance_api',
        'name' => 'ZJMF 财务接口',
        'version' => '1.0.0',
        'entry' => ZjmfFinancePlugin::class,
        'provider' => ZjmfFinanceServiceProvider::class,
        'capabilities' => [
            ProvidesConsoleAccess::class,
            ProvidesConsoleCatalog::class,
            ProvidesConsoleNetwork::class,
            ProvidesConsoleRuntime::class,
            ProvidesConsoleSecurity::class,
            ProvidesProvisioning::class,
            ProvidesRenewal::class,
            ProvidesScheduledAuthRefresh::class,
            ProvidesStatusSync::class,
        ],
        'extra' => [
            'scheduled_tasks' => [
                ZjmfScheduledAuthRefreshTask::class,
                ZjmfInventoryAndServiceSyncTask::class,
            ],
            'schedule_hooks' => [
                'plugins.zjmf_finance.auth_refresh' => [
                    ZjmfScheduledAuthRefreshHook::class,
                ],
                'plugins.zjmf_finance.inventory_service_sync' => [
                    ZjmfInventoryAndServiceSyncHook::class,
                ],
            ],
        ],
    ],
    'config' => [
        'zjmf_notice' => [
            'title' => '配置说明',
            'type' => 'notice',
            'theme' => 'info',
            'content' => '该插件承载ZJMF 财务上游差异适配，接口地址、账号和密钥由供应商配置维护。',
        ],
        'provider_key' => [
            'title' => '上游标识',
            'type' => 'readonly',
            'value' => 'zjmf_finance_api',
            'description' => '供应商绑定 provider_key 必须保持该值，不要别名为 hosting_panel_api。',
        ],
    ],
];
