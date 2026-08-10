<?php

declare(strict_types=1);

use EwyFinance\Plugins\Captcha\Geetest\GeetestPlugin;

return [
    'info' => [
        'domain' => 'captcha',
        'slug' => 'geetest',
        'key' => 'geetest',
        'name' => 'GeeTest 行为验证',
        'version' => '1.0.0',
        'entry' => GeetestPlugin::class,
        'capabilities' => ['config', 'verify', 'script'],
        'extra' => [
            'driver_binding' => [
                'binding_key' => 'captcha_driver',
                'provider_key' => 'geetest',
            ],
        ],
    ],
    'config' => [
        'basic_notice' => [
            'title' => '配置说明',
            'type' => 'notice',
            'theme' => 'info',
            'content' => '请填写 GeeTest 控制台分配的 Captcha ID 和 Captcha Key。密钥保存后不会明文回显。',
        ],
        'captcha_id' => [
            'title' => 'Captcha ID',
            'type' => 'text',
            'value' => '',
            'required' => true,
            'placeholder' => '请输入 Captcha ID',
            'description' => '来自 GeeTest 控制台的 captcha_id。',
        ],
        'captcha_key' => [
            'title' => 'Captcha Key',
            'type' => 'password',
            'value' => '',
            'required' => true,
            'secret' => true,
            'placeholder' => '请输入 Captcha Key',
            'description' => '来自 GeeTest 控制台的 captcha_key。',
        ],
    ],
];
