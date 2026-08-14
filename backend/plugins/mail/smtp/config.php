<?php

declare(strict_types=1);

use TuraIDC\Plugins\Mail\Smtp\SmtpPlugin;

return [
    'info' => [
        'domain' => 'mail',
        'slug' => 'smtp',
        'key' => 'smtp',
        'name' => 'Single SMTP',
        'version' => '1.0.0',
        'entry' => SmtpPlugin::class,
        'capabilities' => ['smtp', 'html'],
        'extra' => [
            'driver_binding' => [
                'binding_key' => 'mail_driver',
                'provider_key' => 'smtp',
            ],
        ],
    ],
    'config' => [
        'smtp_notice' => [
            'title' => '配置说明',
            'type' => 'notice',
            'theme' => 'info',
            'content' => '单 SMTP 插件使用一组 SMTP 账号发送系统邮件，密钥保存后不会明文回显。',
        ],
        'host' => ['title' => 'SMTP 主机', 'type' => 'text', 'value' => '', 'required' => false, 'placeholder' => 'smtp.example.com'],
        'port' => ['title' => 'SMTP 端口', 'type' => 'number', 'value' => 465, 'required' => false, 'min' => 1, 'step' => 1],
        'username' => ['title' => 'SMTP 账号', 'type' => 'text', 'value' => '', 'required' => false, 'placeholder' => 'no-reply@example.com'],
        'password' => ['title' => 'SMTP 密码', 'type' => 'password', 'value' => '', 'required' => false, 'secret' => true, 'placeholder' => '请输入 SMTP 密码'],
        'from_name' => ['title' => '发件名称', 'type' => 'text', 'value' => '图拉云', 'required' => false],
        'encryption' => [
            'title' => '加密方式',
            'type' => 'select',
            'value' => '',
            'required' => false,
            'options' => [
                ['label' => '自动', 'value' => ''],
                ['label' => 'SSL', 'value' => 'ssl'],
                ['label' => 'TLS', 'value' => 'tls'],
                ['label' => '无', 'value' => 'none'],
            ],
        ],
        'timeout_seconds' => ['title' => '超时秒数', 'type' => 'number', 'value' => 8, 'required' => false, 'min' => 1, 'step' => 1],
        'rate_limit_divider' => ['title' => '验证码限流', 'type' => 'divider'],
        'rate_limit_enabled' => ['title' => '启用邮箱验证码限流', 'type' => 'switch', 'value' => true, 'required' => false, 'description' => '限制使用此插件发送邮箱验证码的单 IP 频率。'],
        'ip_minute_limit' => ['title' => '单 IP 每分钟上限', 'type' => 'number', 'value' => 6, 'required' => false, 'min' => 0, 'step' => 1, 'description' => '设为 0 表示不限制。'],
    ],
];
