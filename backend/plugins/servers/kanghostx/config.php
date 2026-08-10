<?php

declare(strict_types=1);

use App\Services\Upstream\Contracts\ProvidesConsoleCatalog;
use App\Services\Upstream\Contracts\ProvidesConsoleRuntime;
use App\Services\Upstream\Contracts\ProvidesProvisioning;
use App\Services\Upstream\Contracts\ProvidesRenewal;
use App\Services\Upstream\Contracts\ProvidesStatusSync;
use EwyFinance\Plugins\Servers\KangHostx\KangHostxPlugin;

return [
    'info' => [
        'domain' => 'upstream',
        'slug' => 'kanghostx',
        'key' => 'kanghostx',
        'name' => '康乐虚拟主机',
        'version' => '1.0.0',
        'entry' => KangHostxPlugin::class,
        'capabilities' => [
            ProvidesConsoleCatalog::class,
            ProvidesConsoleRuntime::class,
            ProvidesProvisioning::class,
            ProvidesRenewal::class,
            ProvidesStatusSync::class,
        ],
    ],
    'config' => [
        'kanghostx_notice' => [
            'title' => '配置说明',
            'type' => 'notice',
            'theme' => 'info',
            'content' => '该插件按 kanghostx 原模块的 Kangle WHM API 对接，供应商接口地址填写面板根地址，API 密钥填写 accesshash。',
        ],
        'provider_key' => [
            'title' => '上游标识',
            'type' => 'readonly',
            'value' => 'kanghostx',
            'description' => '供应商绑定 provider_key 为 kanghostx 时使用康乐虚拟主机插件。',
        ],
    ],
];
