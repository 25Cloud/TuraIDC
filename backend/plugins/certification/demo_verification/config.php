<?php

declare(strict_types=1);

use EwyFinance\Plugins\Certification\DemoVerification\DemoVerificationPlugin;

return [
    'info' => [
        'domain' => 'verification',
        'slug' => 'demo_verification',
        'key' => 'demo_verification',
        'name' => 'Demo 实名认证',
        'version' => '1.0.0',
        'entry' => DemoVerificationPlugin::class,
        'capabilities' => ['personal', 'scan_url', 'query_status', 'callback_verify', 'fee_config'],
    ],
    'config' => [
        'demo_notice' => ['title' => '演示插件', 'type' => 'notice', 'theme' => 'info', 'content' => '该插件用于验证插件流程，不建议在生产环境启用。'],
        'api_url' => ['title' => '接口地址', 'type' => 'url', 'value' => 'https://example.test', 'required' => true, 'placeholder' => '请输入接口地址'],
        'app_id' => ['title' => 'App ID', 'type' => 'text', 'value' => 'demo_app', 'required' => true, 'placeholder' => '请输入 App ID'],
        'app_secret' => ['title' => 'App Secret', 'type' => 'password', 'value' => '', 'required' => false, 'secret' => true, 'placeholder' => '请输入 App Secret'],
        'billing_divider' => ['title' => '计费设置', 'type' => 'divider'],
        'charge_enabled' => ['title' => '插件收费', 'type' => 'switch', 'value' => false],
        'amount' => ['title' => '收费金额', 'type' => 'number', 'value' => 0, 'min' => 0, 'step' => 0.01, 'visible_when' => ['field' => 'charge_enabled', 'operator' => 'eq', 'value' => true]],
        'free_times' => ['title' => '免费次数', 'type' => 'number', 'value' => 0, 'min' => 0, 'step' => 1],
    ],
];
