<?php

declare(strict_types=1);
use TuraIDC\Plugins\Certification\Stay33\Stay33Plugin;

return [
    'info' => [
        'domain' => 'verification',
        'slug' => 'stay33',
        'key' => 'stay33',
        'name' => 'Stay33 实名认证',
        'version' => '1.0.0',
        'entry' => Stay33Plugin::class,
        'capabilities' => ['personal', 'scan_url', 'query_status', 'verify_callback', 'fee_config'],
        'extra' => [
            'driver_binding' => [
                'binding_key' => 'verification_driver',
                'provider_key' => 'stay33',
            ],
        ],
    ],
    'config' => [
        'basic_notice' => ['title' => '配置说明', 'type' => 'notice', 'theme' => 'info', 'content' => '请填写 Stay33 服务商后台分配的 API 标识、接口密钥和认证业务码。'],
        'api' => ['title' => 'API 标识', 'type' => 'text', 'value' => '', 'required' => true, 'placeholder' => '请输入 API 标识', 'description' => '用于识别当前认证应用。'],
        'key' => ['title' => '接口密钥', 'type' => 'password', 'value' => '', 'required' => true, 'secret' => true, 'placeholder' => '请输入接口密钥', 'description' => '已配置时不会明文回显，留空表示不修改。'],
        'biz_code' => ['title' => '认证业务码', 'type' => 'text', 'value' => '', 'required' => true, 'placeholder' => '例如 FACE', 'description' => '服务商分配的实名业务场景码。'],
        'api_endpoint' => ['title' => '接口地址', 'type' => 'url', 'value' => 'https://idc.stay33.cn/realname/certapi.php', 'required' => false, 'placeholder' => '请输入 HTTPS 接口地址', 'description' => '通常保持默认地址，只有服务商要求时才修改。'],
        'ssl_verify' => ['title' => 'SSL 证书校验', 'type' => 'switch', 'value' => true, 'description' => '开启后校验服务商 HTTPS 证书；证书链异常时请配置 CA 证书路径。'],
        'ca_bundle' => ['title' => 'CA 证书路径', 'type' => 'text', 'value' => '', 'required' => false, 'placeholder' => '例如 /etc/ssl/certs/cacert.pem', 'description' => '可选，填写服务器本地 CA bundle 文件路径。'],
        'billing_divider' => ['title' => '计费设置', 'type' => 'divider'],
        'charge_enabled' => ['title' => '插件收费', 'type' => 'switch', 'value' => false, 'description' => '开启后，用户发起实名认证时按配置金额扣费。'],
        'amount' => ['title' => '收费金额', 'type' => 'number', 'value' => 0, 'min' => 0, 'step' => 0.01, 'description' => '单位：元。关闭收费时该字段不生效。', 'visible_when' => ['field' => 'charge_enabled', 'operator' => 'eq', 'value' => true]],
        'free_times' => ['title' => '免费次数', 'type' => 'number', 'value' => 0, 'min' => 0, 'step' => 1, 'description' => '每个用户可免费发起认证的次数。'],
    ],
];
