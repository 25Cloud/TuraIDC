<?php

declare(strict_types=1);

use TuraIDC\Plugins\Captcha\Turnstile\TurnstilePlugin;

return [
    'info' => [
        'domain' => 'captcha',
        'slug' => 'turnstile',
        'key' => 'turnstile',
        'name' => 'Cloudflare Turnstile',
        'version' => '1.0.0',
        'entry' => TurnstilePlugin::class,
        'capabilities' => ['config', 'verify', 'script'],
        'extra' => [
            'driver_binding' => [
                'binding_key' => 'captcha_driver',
                'provider_key' => 'turnstile',
            ],
        ],
    ],
    // 场景开关为 captcha 域各插件共用，见 plugins/captcha/scene-switches.php
    'config' => array_merge([
        'basic_notice' => [
            'title' => '配置说明',
            'type' => 'notice',
            'theme' => 'info',
            'content' => '在 Cloudflare 仪表盘 → Turnstile 新建 Widget，把「站点密钥」填入 Site Key、'
                .'「密钥」填入 Secret Key。Site Key 会下发前端，Secret Key 仅留在服务端，保存后不再明文回显。'
                .'Widget 的「域名」列表须包含实际访问本站的域名，否则前端组件会拒绝渲染。',
        ],
        'site_key' => [
            'title' => 'Site Key（站点密钥）',
            'type' => 'text',
            'value' => '',
            'required' => true,
            'placeholder' => '形如 0x4AAAAAAA...',
            'description' => '公开参数，用于前端初始化 Turnstile 组件。',
        ],
        'secret_key' => [
            'title' => 'Secret Key（密钥）',
            'type' => 'password',
            'value' => '',
            'required' => true,
            'secret' => true,
            'placeholder' => '形如 0x4AAAAAAA...',
            'description' => '服务端校验密钥，仅用于向 Cloudflare 兑换 token，不会下发前端。',
        ],

        'appearance_divider' => [
            'title' => '组件外观',
            'type' => 'divider',
            // 各项都有合理默认值，默认收起，避免管理员误改
            'collapsible' => true,
        ],
        'widget_appearance' => [
            'title' => '呈现方式',
            'type' => 'select',
            'value' => 'interaction-only',
            'required' => false,
            'options' => [
                ['label' => '无感（仅在需要交互时才显示）', 'value' => 'interaction-only'],
                ['label' => '始终显示验证组件', 'value' => 'always'],
                ['label' => '执行时显示', 'value' => 'execute'],
            ],
            'description' => '默认「无感」：点击提交后在后台静默完成验证，用户看不到任何组件；'
                .'仅当 Cloudflare 判定需要人工挑战时，才在提交按钮上方显示验证组件。'
                .'选择「始终显示」则每次都在按钮上方展示组件（含通过后的绿色提示）。',
        ],
        'widget_theme' => [
            'title' => '主题',
            'type' => 'select',
            'value' => 'auto',
            'required' => false,
            'options' => [
                ['label' => '跟随系统', 'value' => 'auto'],
                ['label' => '浅色', 'value' => 'light'],
                ['label' => '深色', 'value' => 'dark'],
            ],
        ],
        'widget_size' => [
            'title' => '尺寸',
            'type' => 'select',
            'value' => 'normal',
            'required' => false,
            'options' => [
                ['label' => '标准（300×65）', 'value' => 'normal'],
                ['label' => '自适应宽度', 'value' => 'flexible'],
                ['label' => '紧凑（150×140）', 'value' => 'compact'],
            ],
        ],
        'widget_language' => [
            'title' => '语言',
            'type' => 'select',
            'value' => 'zh-cn',
            'required' => false,
            'options' => [
                ['label' => '跟随浏览器', 'value' => 'auto'],
                ['label' => '简体中文', 'value' => 'zh-cn'],
                ['label' => '繁体中文', 'value' => 'zh-tw'],
                ['label' => 'English', 'value' => 'en'],
            ],
        ],

        'advanced_divider' => [
            'title' => '高级',
            'type' => 'divider',
            'collapsible' => true,
        ],
        'request_timeout' => [
            'title' => '校验请求超时（秒）',
            'type' => 'number',
            'value' => 10,
            'min' => 3,
            'max' => 30,
            'required' => false,
            'description' => '服务端向 Cloudflare 兑换 token 的 HTTP 超时。',
        ],
        'sdk_timeout' => [
            'title' => '组件加载超时（秒）',
            'type' => 'number',
            'value' => 15,
            'min' => 5,
            'max' => 60,
            'required' => false,
            'description' => '浏览器加载 Cloudflare 验证组件的超时。'
                .'challenges.cloudflare.com 在网络受限环境下可能长时间无响应而不报错，'
                .'超过此时长即提示用户「连接超时，请联系管理员」，避免按钮一直转圈。',
        ],
        'replay_ttl' => [
            'title' => '防重放缓存时长（秒）',
            'type' => 'number',
            'value' => 300,
            'min' => 60,
            'max' => 1800,
            'required' => false,
            'description' => 'Turnstile token 本身即为一次性凭据（约 300 秒过期）。'
                .'这里额外在本地记录已用过的 token，使重复提交在不请求 Cloudflare 的情况下即被拒绝。',
        ],
    ], require __DIR__.'/../scene-switches.php'),
];
