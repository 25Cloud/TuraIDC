<?php

declare(strict_types=1);

use TuraIDC\Plugins\Sms\DemoSms\DemoSmsPlugin;

return [
    'info' => [
        'domain' => 'sms',
        'slug' => 'demo_sms',
        'key' => 'demo_sms',
        'name' => 'Demo 短信',
        'version' => '1.0.0',
        'entry' => DemoSmsPlugin::class,
        'capabilities' => ['verify_code', 'message'],
    ],
    'config' => [
        'demo_notice' => ['title' => '演示插件', 'type' => 'notice', 'theme' => 'info', 'content' => '该插件只模拟短信发送结果，用于验证插件流程。'],
        'access_key' => ['title' => 'Access Key', 'type' => 'text', 'value' => 'demo_access_key', 'required' => true, 'placeholder' => '请输入 Access Key'],
        'secret_key' => ['title' => 'Secret Key', 'type' => 'password', 'value' => '', 'required' => false, 'secret' => true, 'placeholder' => '请输入 Secret Key'],
        'sign_name' => ['title' => '短信签名', 'type' => 'text', 'value' => 'Demo 签名', 'required' => true, 'placeholder' => '请输入短信签名'],
        'template_code' => ['title' => '验证码模板编号', 'type' => 'text', 'value' => '100001', 'required' => true, 'placeholder' => '请输入模板编号', 'description' => '验证码测试使用系统短信模板编号。'],
        'rate_limit_divider' => ['title' => '验证码限流', 'type' => 'divider'],
        'rate_limit_enabled' => ['title' => '启用短信验证码限流', 'type' => 'switch', 'value' => true, 'required' => false, 'description' => '限制使用此插件发送短信验证码的单 IP 频率。'],
        'ip_minute_limit' => ['title' => '单 IP 每分钟上限', 'type' => 'number', 'value' => 6, 'required' => false, 'min' => 0, 'step' => 1, 'description' => '设为 0 表示不限制。'],
    ],
];
