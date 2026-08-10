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
use EwyFinance\Plugins\Servers\DemoServers\DemoServersPlugin;

return [
    'info' => [
        'domain' => 'upstream',
        'slug' => 'demo_servers',
        'key' => 'demo_servers',
        'name' => 'Demo 上游服务',
        'version' => '1.0.0',
        'entry' => DemoServersPlugin::class,
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
    ],
    'config' => [
        'demo_notice' => [
            'title' => '演示插件',
            'type' => 'notice',
            'theme' => 'info',
            'content' => '该插件只模拟上游商品、开通、续费、状态同步和控制台能力，不会请求真实上游。',
        ],
        'provider_key' => [
            'title' => '上游标识',
            'type' => 'readonly',
            'value' => 'demo_servers',
            'description' => '供应商绑定 provider_key 为 demo_servers 时使用该演示插件。',
        ],
        'demo_region' => [
            'title' => '模拟区域',
            'type' => 'text',
            'value' => 'ap-demo-1',
            'required' => false,
            'placeholder' => 'ap-demo-1',
        ],
        'enabled' => [
            'title' => '启用演示上游',
            'type' => 'switch',
            'value' => true,
            'description' => '关闭后仍可安装，但不建议作为真实供应商使用。',
        ],
    ],
];
