<?php

declare(strict_types=1);

use TuraIDC\Plugins\Captcha\Corptcha\CorptchaPlugin;

return [
    'info' => [
        'domain' => 'captcha',
        'slug' => 'corptcha',
        'key' => 'corptcha',
        'name' => 'Corptcha 人机验证',
        'version' => '1.0.0',
        'entry' => CorptchaPlugin::class,
        'capabilities' => ['config', 'verify', 'script'],
        'extra' => [
            'driver_binding' => [
                'binding_key' => 'captcha_driver',
                'provider_key' => 'corptcha',
            ],
        ],
    ],
    // 场景开关为 captcha 域各插件共用，见 plugins/captcha/scene-switches.php
    'config' => array_merge([
        'basic_notice' => [
            'title' => '配置说明',
            'type' => 'notice',
            'theme' => 'info',
            'content' => '请在 Corptcha 控制台（dash.corptcha.com）创建站点并填写 Site ID 与 Secret。Site ID 可安全出现在前端，Secret 只保存在服务端，保存后不会明文回显。',
        ],
        'site_key' => [
            'title' => 'Site ID',
            'type' => 'text',
            'value' => '',
            'required' => true,
            'placeholder' => '请输入 Site ID（形如 cpt_xxxxxxxxxxxx）',
            'description' => '来自 Corptcha 控制台的站点 Site ID，用于前端初始化验证组件。',
        ],
        'secret' => [
            'title' => 'Secret',
            'type' => 'password',
            'value' => '',
            'required' => true,
            'secret' => true,
            'placeholder' => '请输入站点密钥 Secret',
            'description' => '来自 Corptcha 控制台的站点密钥，仅用于后端核验 token。',
        ],
        'api_base_url' => [
            'title' => '验证服务 API 地址',
            'type' => 'text',
            'value' => 'https://cpt-api.25y.cn',
            'required' => false,
            'placeholder' => 'https://cpt-api.25y.cn',
            'description' => '验证服务 API 地址，自建部署时可按需修改。',
        ],
        'purpose' => [
            'title' => '验证场景',
            'type' => 'text',
            'value' => 'login',
            'required' => false,
            'placeholder' => 'login',
            'description' => '场景标识（login / register / comment 等），前端签发与后端核验必须一致。',
        ],
        'language' => [
            'title' => '界面语言',
            'type' => 'select',
            'value' => 'zh-CN',
            'required' => false,
            'options' => [
                ['label' => '简体中文', 'value' => 'zh-CN'],
                ['label' => 'English', 'value' => 'en-US'],
            ],
            'description' => '验证组件界面语言。',
        ],
        'theme_mode' => [
            'title' => '主题模式',
            'type' => 'select',
            'value' => 'auto',
            'required' => false,
            'options' => [
                ['label' => '跟随系统', 'value' => 'auto'],
                ['label' => '浅色', 'value' => 'light'],
                ['label' => '深色', 'value' => 'dark'],
            ],
            'description' => '验证组件主题模式。',
        ],
        'request_timeout' => [
            'title' => '核验超时（秒）',
            'type' => 'number',
            'value' => 10,
            'required' => false,
            'min' => 1,
            'max' => 30,
            'description' => '后端调用验证服务核验 token 的超时时间。',
        ],
    ], require __DIR__.'/../scene-switches.php'),
];
