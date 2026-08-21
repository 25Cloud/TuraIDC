<?php

declare(strict_types=1);

use TuraIDC\Plugins\Addons\ZjmfBridge\Providers\ZjmfBridgeServiceProvider;
use TuraIDC\Plugins\Addons\ZjmfBridge\ZjmfBridgePlugin;

return [
    'info' => [
        'domain' => 'addons',
        'slug' => 'zjmf_bridge',
        'key' => 'zjmf_bridge',
        'name' => 'ZJMF Bridge 接口插件',
        'version' => '1.0.0',
        'entry' => ZjmfBridgePlugin::class,
        'provider' => ZjmfBridgeServiceProvider::class,
        'capabilities' => [
            'zjmf.bridge',
            'zjmf.dispatch',
            'zjmf.auth',
            'zjmf.finance',
            'zjmf.product',
            'zjmf.service',
            'zjmf.ticket',
            'zjmf.reconcile',
        ],
        'extra' => [
            'base_path_config' => 'zjmf_bridge.base_path',
        ],
    ],
    'config' => [
        'bridge_notice' => [
            'title' => '入口边界说明',
            'type' => 'notice',
            'theme' => 'info',
            'content' => 'ZJMF URL、签名、JWT 校验和业务接口均由本 addon 提供。',
        ],
        'enabled' => [
            'title' => '启用插件内业务处理',
            'type' => 'switch',
            'value' => true,
            'description' => '关闭后插件仍可安装，但 /zjmf/v1/* 会返回插件业务未启用。',
        ],
    ],
];
