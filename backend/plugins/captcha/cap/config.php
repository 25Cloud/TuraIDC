<?php

declare(strict_types=1);

use TuraIDC\Plugins\Captcha\Cap\CapPlugin;

return [
    'info' => [
        'domain' => 'captcha',
        'slug' => 'cap',
        'key' => 'cap',
        'name' => 'Cap 人机验证',
        'version' => '1.0.0',
        'entry' => CapPlugin::class,
        'capabilities' => ['config', 'verify', 'script'],
        'extra' => [
            'driver_binding' => [
                'binding_key' => 'captcha_driver',
                'provider_key' => 'cap',
            ],
        ],
    ],
    // 场景开关为 captcha 域各插件共用，见 plugins/captcha/scene-switches.php
    'config' => array_merge([
        'basic_notice' => [
            'title' => '配置说明',
            'type' => 'notice',
            'theme' => 'info',
            'content' => 'Cap 是自托管开源人机验证（https://trycap.dev，Apache 2.0）。请填写 Cap Standalone 实例的服务端地址、Site ID 与 Secret Key。Secret Key 只保存在服务端，保存后不会明文回显。',
        ],
        'server_address' => [
            'title' => '服务端地址',
            'type' => 'url',
            'value' => '',
            'required' => true,
            'placeholder' => 'https://cap.example.com',
            'description' => 'Cap Standalone 实例地址，例如 https://cap.example.com；前端 widget 与后端 siteverify 都请求该地址。',
        ],
        'site_id' => [
            'title' => 'Site ID',
            'type' => 'text',
            'value' => '',
            'required' => true,
            'placeholder' => '请输入 Cap Site ID',
            'description' => 'Cap 控制台创建的站点标识（公开，用于前端 widget 初始化）。',
        ],
        'secret_key' => [
            'title' => 'Secret Key',
            'type' => 'password',
            'value' => '',
            'required' => true,
            'secret' => true,
            'placeholder' => '请输入 Cap Secret Key',
            'description' => 'Cap 控制台的服务端密钥（sk- 开头），仅用于后端 siteverify，不下发前端。',
        ],
    ], require __DIR__.'/../scene-switches.php'),
];
