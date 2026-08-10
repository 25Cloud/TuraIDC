<?php

declare(strict_types=1);

use EwyFinance\Plugins\Addons\DemoStyle\DemoStylePlugin;
use EwyFinance\Plugins\Addons\DemoStyle\Lib\DemoStyleHook;
use EwyFinance\Plugins\Addons\DemoStyle\Lib\DemoStyleScheduledTask;

return [
    'info' => [
        'domain' => 'addons',
        'slug' => 'demo_style',
        'key' => 'demo_style',
        'name' => 'Demo Style 功能扩展',
        'version' => '1.0.0',
        'entry' => DemoStylePlugin::class,
        'capabilities' => [
            'addon.admin_page',
            'addon.client_page',
            'addon.public_assets',
            'addon.schedule_hook',
            'addon.scheduled_task',
        ],
        'extra' => [
            'admin_entry' => [
                'title' => '样式演示后台页',
                'action' => 'addon.admin.index',
            ],
            'client_entry' => [
                'title' => '样式演示用户页',
                'action' => 'addon.client.index',
            ],
            'public_entry' => [
                'title' => '样式演示公开资源',
                'action' => 'addon.public.assets',
            ],
            'schedule_hooks' => [
                'addons.demo_style.refresh' => [
                    DemoStyleHook::class,
                ],
            ],
            'scheduled_tasks' => [
                DemoStyleScheduledTask::class,
            ],
        ],
    ],
    'config' => [
        'style_notice' => [
            'title' => 'ZJMF Addon 结构参考',
            'type' => 'notice',
            'theme' => 'info',
            'content' => '该示例参考 ZJMF addons/demo_style 的目录语义，但运行时使用 EwyFinance 统一插件 execute() 入口。',
        ],
        'theme_name' => [
            'title' => '主题名称',
            'type' => 'text',
            'value' => 'classic-blue',
            'required' => true,
            'placeholder' => 'classic-blue',
        ],
        'accent_color' => [
            'title' => '强调色',
            'type' => 'text',
            'value' => '#2563eb',
            'required' => false,
            'placeholder' => '#2563eb',
        ],
        'enabled' => [
            'title' => '启用扩展',
            'type' => 'switch',
            'value' => true,
            'description' => '关闭后仍可安装插件，但业务入口应由调用方隐藏。',
        ],
    ],
];
