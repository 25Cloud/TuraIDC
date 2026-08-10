<?php

declare(strict_types=1);

use EwyFinance\Plugins\Mail\DemoMail\DemoMailPlugin;

return [
    'info' => [
        'domain' => 'mail',
        'slug' => 'demo_mail',
        'key' => 'demo_mail',
        'name' => 'Demo 邮件',
        'version' => '1.0.0',
        'entry' => DemoMailPlugin::class,
        'capabilities' => ['html', 'smtp_like'],
    ],
    'config' => [
        'demo_notice' => ['title' => '演示插件', 'type' => 'notice', 'theme' => 'info', 'content' => '该插件用于模拟邮件发送，不连接真实 SMTP 服务。'],
        'from_address' => ['title' => '发件邮箱', 'type' => 'email', 'value' => 'demo@example.test', 'required' => true, 'placeholder' => '请输入发件邮箱'],
        'from_name' => ['title' => '发件名称', 'type' => 'text', 'value' => 'Demo Mail', 'required' => true, 'placeholder' => '请输入发件名称'],
        'api_token' => ['title' => 'API Token', 'type' => 'password', 'value' => '', 'required' => false, 'secret' => true, 'placeholder' => '请输入 API Token'],
        'rate_limit_divider' => ['title' => '验证码限流', 'type' => 'divider'],
        'rate_limit_enabled' => ['title' => '启用邮箱验证码限流', 'type' => 'switch', 'value' => true, 'required' => false, 'description' => '限制使用此插件发送邮箱验证码的单 IP 频率。'],
        'ip_minute_limit' => ['title' => '单 IP 每分钟上限', 'type' => 'number', 'value' => 6, 'required' => false, 'min' => 0, 'step' => 1, 'description' => '设为 0 表示不限制。'],
    ],
];
