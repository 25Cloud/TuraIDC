<?php

declare(strict_types=1);

use EwyFinance\Plugins\Sms\Stay33\Stay33Plugin;

return [
    'info' => [
        'domain' => 'sms',
        'slug' => 'stay33',
        'key' => 'stay33',
        'name' => 'MC云短信',
        'version' => '1.0.0',
        'entry' => Stay33Plugin::class,
        'capabilities' => ['verify_code', 'message'],
        'extra' => [
            'driver_binding' => [
                'binding_key' => 'sms_driver',
                'provider_key' => 'stay33',
            ],
        ],
    ],
    'config' => [
        'credential_notice' => [
            'title' => '配置说明',
            'type' => 'notice',
            'theme' => 'warning',
            'content' => '按 MC云短信控制台 API 信息填写用户名和用户密钥。短信发送前需完成签名与实名，并确认账号有短信额度。',
        ],
        'username' => [
            'title' => '用户名',
            'type' => 'text',
            'value' => '',
            'required' => true,
            'placeholder' => '请输入短信平台用户名',
        ],
        'api_key' => [
            'title' => '用户密钥',
            'type' => 'password',
            'value' => '',
            'required' => true,
            'secret' => true,
            'placeholder' => 'sk_live_XXXXX',
        ],
        'sign_name' => [
            'title' => '短信签名',
            'type' => 'text',
            'value' => '',
            'required' => true,
            'placeholder' => '请输入签名名称，不含外层【】',
        ],
        'rate_limit_divider' => ['title' => '验证码限流', 'type' => 'divider'],
        'rate_limit_enabled' => [
            'title' => '启用短信验证码限流',
            'type' => 'switch',
            'value' => true,
            'required' => false,
            'description' => '限制使用此插件发送短信验证码的单 IP 频率。',
        ],
        'ip_minute_limit' => [
            'title' => '单 IP 每分钟上限',
            'type' => 'number',
            'value' => 6,
            'required' => false,
            'min' => 0,
            'step' => 1,
            'description' => '设为 0 表示不限制。',
        ],
    ],
];
