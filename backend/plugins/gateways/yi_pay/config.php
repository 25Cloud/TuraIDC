<?php

declare(strict_types=1);

use EwyFinance\Plugins\Gateways\YiPay\YiPayPlugin;

return [
    'info' => [
        'domain' => 'payment',
        'slug' => 'yi_pay',
        'key' => 'yipay',
        'name' => '易支付',
        'version' => '1.0.0',
        'entry' => YiPayPlugin::class,
        'capabilities' => ['precreate', 'query', 'refund', 'notify_verify'],
    ],
    'config' => [
        'yipay_notice' => [
            'title' => '配置说明',
            'type' => 'notice',
            'theme' => 'warning',
            'content' => '请填写易支付接口地址、商户 ID，并选择签名方式。MD5 签名需填写商户密钥；RSA 签名需填写商户私钥和平台公钥，密钥保存后不会明文回显。',
        ],
        'enabled' => [
            'title' => '启用',
            'type' => 'switch',
            'value' => true,
            'required' => true,
            'description' => '关闭后该支付渠道不会作为可用支付方式。',
        ],
        'merchant_id' => [
            'title' => '商户 ID',
            'type' => 'text',
            'value' => '',
            'required' => true,
            'placeholder' => '请输入易支付商户 ID',
        ],
        'api_endpoint' => [
            'title' => '接口地址',
            'type' => 'url',
            'value' => '',
            'required' => true,
            'placeholder' => '请输入易支付接口地址',
            'description' => '可填写平台根地址；若填写 mapi.php 等具体接口地址，系统会按同目录解析其他接口。',
        ],
        'sign_type' => [
            'title' => '签名方式',
            'type' => 'select',
            'value' => 'MD5',
            'required' => true,
            'options' => [
                ['label' => 'MD5 签名', 'value' => 'MD5'],
                ['label' => 'RSA 签名', 'value' => 'RSA'],
            ],
        ],
        'merchant_key' => [
            'title' => 'MD5 商户密钥',
            'type' => 'password',
            'value' => '',
            'required' => false,
            'secret' => true,
            'placeholder' => 'MD5 签名时请输入商户密钥 KEY',
            'description' => '签名方式为 MD5 时必填；查询/退款接口如果服务商仍要求 KEY，也需要填写。',
            'visible_when' => [
                'field' => 'sign_type',
                'operator' => 'eq',
                'value' => 'MD5',
            ],
        ],
        'merchant_private_key' => [
            'title' => 'RSA 商户私钥',
            'type' => 'textarea',
            'value' => '',
            'required' => false,
            'secret' => true,
            'placeholder' => 'RSA 签名时请输入商户私钥',
            'visible_when' => [
                'field' => 'sign_type',
                'operator' => 'eq',
                'value' => 'RSA',
            ],
        ],
        'platform_public_key' => [
            'title' => 'RSA 平台公钥',
            'type' => 'textarea',
            'value' => '',
            'required' => false,
            'secret' => true,
            'placeholder' => 'RSA 签名时请输入平台公钥，用于验签回调',
            'visible_when' => [
                'field' => 'sign_type',
                'operator' => 'eq',
                'value' => 'RSA',
            ],
        ],
        'payment_types' => [
            'title' => '支付方式',
            'type' => 'checkbox',
            'value' => ['alipay'],
            'required' => true,
            'description' => '可同时启用支付宝和微信支付；未勾选时该渠道不会展示给用户。',
            'options' => [
                ['label' => '支付宝', 'value' => 'alipay'],
                ['label' => '微信支付', 'value' => 'wxpay'],
            ],
        ],
    ],
];
