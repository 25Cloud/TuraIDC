<?php

declare(strict_types=1);

use TuraIDC\Plugins\Certification\Smapi\SmapiPlugin;

return [
    'info' => [
        'domain' => 'verification',
        'slug' => 'smapi',
        'key' => 'smapi',
        'name' => '聚合实名认证',
        'version' => '1.0.0',
        'entry' => SmapiPlugin::class,
        'capabilities' => ['personal', 'scan_url', 'query_status', 'verify_callback', 'fee_config'],
        'extra' => [
            'driver_binding' => [
                'binding_key' => 'verification_driver',
                'provider_key' => 'smapi',
            ],
        ],
    ],
    'config' => [
        'basic_notice' => [
            'title' => '配置说明',
            'type' => 'notice',
            'theme' => 'info',
            'content' => '对接小沐实名 API（smapi.x1m1.cn）聚合实名平台。请填写用户中心分配的 AppKey、AppSecret 与产品标识；密钥保存后不会明文回显。',
        ],
        'api_url' => [
            'title' => 'API 地址',
            'type' => 'url',
            'value' => 'https://smapi.x1m1.cn',
            'required' => false,
            'placeholder' => 'https://smapi.x1m1.cn',
            'description' => '聚合实名平台地址，一般保持默认，只有服务商要求时才修改。',
        ],
        'app_key' => [
            'title' => 'AppKey',
            'type' => 'text',
            'value' => '',
            'required' => true,
            'placeholder' => '请输入用户中心 API 密钥 AppKey',
            'description' => '用户中心 API 密钥 AppKey。',
        ],
        'secret_key' => [
            'title' => 'AppSecret',
            'type' => 'password',
            'value' => '',
            'required' => true,
            'secret' => true,
            'placeholder' => '请输入用户中心 API 密钥 AppSecret',
            'description' => '已配置时不会明文回显，留空表示不修改。',
        ],
        'product_code' => [
            'title' => '产品标识',
            'type' => 'textarea',
            'value' => '',
            'required' => true,
            'rows' => 4,
            'placeholder' => "alipay_v3\n或 alipay_v3,支付宝身份认证|tencent_sm,微信实名认证",
            'description' => '单个接口直接填写产品标识，例如 alipay_v3；多个接口按“产品标识,显示名称|产品标识,显示名称”填写，多个产品时取第一个作为默认认证产品。',
        ],
        'ssl_verify' => [
            'title' => 'SSL 证书校验',
            'type' => 'switch',
            'value' => true,
            'description' => '开启后校验服务商 HTTPS 证书；证书链异常时可关闭或配置 CA 证书路径。',
        ],
        'ca_bundle' => [
            'title' => 'CA 证书路径',
            'type' => 'text',
            'value' => '',
            'required' => false,
            'placeholder' => '例如 /etc/ssl/certs/cacert.pem',
            'description' => '可选，填写服务器本地 CA bundle 文件路径。',
        ],
        'billing_divider' => ['title' => '计费设置', 'type' => 'divider'],
        'charge_enabled' => [
            'title' => '插件收费',
            'type' => 'switch',
            'value' => false,
            'description' => '开启后，用户发起实名认证时按配置金额扣费。',
        ],
        'amount' => [
            'title' => '收费金额',
            'type' => 'number',
            'value' => 0,
            'min' => 0,
            'step' => 0.01,
            'description' => '单位：元。关闭收费时该字段不生效。',
            'visible_when' => ['field' => 'charge_enabled', 'operator' => 'eq', 'value' => true],
        ],
        'free_times' => [
            'title' => '免费次数',
            'type' => 'number',
            'value' => 0,
            'min' => 0,
            'step' => 1,
            'description' => '每个用户可免费发起认证的次数。',
        ],
    ],
];
