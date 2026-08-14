<?php

declare(strict_types=1);

use TuraIDC\Plugins\Gateways\DemoPay\DemoPayPlugin;

return [
    'info' => [
        'domain' => 'payment',
        'slug' => 'demo_pay',
        'key' => 'demo_pay',
        'name' => 'Demo 支付网关',
        'version' => '1.0.0',
        'entry' => DemoPayPlugin::class,
        'capabilities' => ['precreate', 'query', 'refund', 'notify_verify'],
    ],
    'config' => [
        'demo_notice' => ['title' => '演示插件', 'type' => 'notice', 'theme' => 'info', 'content' => '该插件只模拟支付下单、查询、退款和回调验签。'],
        'merchant_id' => ['title' => '商户号', 'type' => 'text', 'value' => 'demo_merchant', 'required' => true, 'placeholder' => '请输入商户号'],
        'secret_key' => ['title' => '演示密钥', 'type' => 'password', 'value' => '', 'required' => false, 'secret' => true, 'placeholder' => '请输入演示密钥'],
        'enabled' => ['title' => '启用演示支付', 'type' => 'switch', 'value' => true, 'description' => '关闭后插件仍可安装，但不建议启用为实际渠道。'],
    ],
];
