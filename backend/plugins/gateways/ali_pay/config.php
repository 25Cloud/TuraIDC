<?php

declare(strict_types=1);

use TuraIDC\Plugins\Gateways\AliPay\AliPayPlugin;

return [
    'info' => [
        'domain' => 'payment',
        'slug' => 'ali_pay',
        'key' => 'alipay',
        'name' => '支付宝当面付',
        'version' => '1.0.0',
        'entry' => AliPayPlugin::class,
        'capabilities' => ['precreate', 'query', 'refund', 'notify_verify'],
        'extra' => [
            'legacy_settings' => [
                'group' => 'payment',
                'map' => [
                    'alipay_enabled' => 'alipay_enabled',
                    'app_id' => 'alipay_app_id',
                    'private_key' => 'alipay_private_key',
                    'alipay_public_key' => 'alipay_public_key',
                ],
            ],
        ],
    ],
    'config' => [
        'alipay_notice' => [
            'title' => '配置说明',
            'type' => 'notice',
            'theme' => 'warning',
            'content' => '请填写支付宝开放平台应用参数。私钥和公钥保存后不会明文回显。',
        ],
        'alipay_enabled' => [
            'title' => '启用',
            'type' => 'switch',
            'value' => true,
            'required' => true,
            'description' => '关闭后该支付渠道不会作为可用支付方式。',
        ],
        'app_id' => [
            'title' => 'App ID',
            'type' => 'text',
            'value' => '',
            'required' => true,
            'placeholder' => '请输入支付宝应用 App ID',
        ],
        'key_divider' => [
            'title' => '密钥配置',
            'type' => 'divider',
        ],
        'private_key' => [
            'title' => '应用私钥',
            'type' => 'textarea',
            'value' => '',
            'required' => true,
            'secret' => true,
            'rows' => 6,
            'placeholder' => '请输入应用私钥 PEM 内容',
            'description' => '请填写应用私钥，不要填写支付宝公钥。',
        ],
        'alipay_public_key' => [
            'title' => '支付宝公钥',
            'type' => 'textarea',
            'value' => '',
            'required' => true,
            'secret' => true,
            'rows' => 6,
            'placeholder' => '请输入支付宝公钥 PEM 内容',
        ],
    ],
];
