<?php

declare(strict_types=1);

use TuraIDC\Plugins\Certification\AlipayCertify\AlipayCertifyPlugin;

return [
    'info' => [
        'domain' => 'verification',
        // slug/key 用 alipay_certify 而非 alipay：支付域已有 key 为 alipay 的收款插件，
        // 虽然唯一约束是 (domain, key) 不会冲突，但 provider_key 会出现在流水、
        // 审计日志与错误映射里（那些位置不带 domain），同名会难以分辨。
        'slug' => 'alipay_certify',
        'key' => 'alipay_certify',
        'name' => '支付宝身份认证',
        'version' => '1.0.0',
        'entry' => AlipayCertifyPlugin::class,
        'capabilities' => ['personal', 'scan_url', 'query_status', 'verify_callback', 'fee_config'],
        'extra' => [
            'driver_binding' => [
                'binding_key' => 'verification_driver',
                'provider_key' => 'alipay_certify',
            ],
        ],
    ],
    'config' => [
        'basic_notice' => [
            'title' => '配置说明',
            'type' => 'notice',
            'theme' => 'info',
            'content' => '对接支付宝开放平台「身份认证」产品（alipay.user.certify 系列）。'
                .'需先在支付宝开放平台创建应用、签约身份认证能力，并配置应用私钥与支付宝公钥。'
                .'认证流程为：提交姓名与身份证号 → 用户扫码在支付宝内完成人脸核身 → 回查认证结果。'
                .'密钥保存后不会明文回显，留空表示不修改。',
        ],
        'app_id' => [
            'title' => '应用 AppID',
            'type' => 'text',
            'value' => '',
            'required' => true,
            'placeholder' => '例如 2021000000000000',
            'description' => '支付宝开放平台为该应用分配的 AppID。',
        ],
        'private_key' => [
            'title' => '应用私钥',
            'type' => 'textarea',
            'value' => '',
            'required' => true,
            'secret' => true,
            'rows' => 4,
            'placeholder' => '粘贴应用私钥（PKCS8 或 PKCS1，可带 PEM 头尾也可只粘 base64）',
            'description' => '用于对请求签名。已配置时不会明文回显，留空表示不修改。',
        ],
        'alipay_public_key' => [
            'title' => '支付宝公钥',
            'type' => 'textarea',
            'value' => '',
            'required' => true,
            'rows' => 4,
            'placeholder' => '粘贴支付宝公钥（可带 PEM 头尾也可只粘 base64）',
            'description' => '用于校验支付宝返回与异步通知的签名。注意是「支付宝公钥」，不是应用公钥。',
        ],
        'biz_code' => [
            'title' => '认证场景',
            'type' => 'select',
            'value' => 'FACE',
            'required' => true,
            'options' => [
                ['label' => 'FACE — 多因子人脸认证', 'value' => 'FACE'],
                ['label' => 'SMART_FACE — 多因子快捷认证', 'value' => 'SMART_FACE'],
                ['label' => 'CERT_PHOTO — 多因子证照认证', 'value' => 'CERT_PHOTO'],
                ['label' => 'CERT_PHOTO_FACE — 多因子证照与人脸认证', 'value' => 'CERT_PHOTO_FACE'],
            ],
            'description' => '必须选择已在支付宝开放平台签约的认证场景，否则初始化会被拒绝。',
        ],
        'advanced_divider' => [
            'title' => '高级设置',
            'type' => 'divider',
            // 各项都有合理默认值，默认收起，避免管理员误改
            'collapsible' => true,
            'collapsed' => true,
        ],
        'gateway_url' => [
            'title' => '网关地址',
            'type' => 'url',
            'value' => 'https://openapi.alipay.com/gateway.do',
            'required' => false,
            'placeholder' => 'https://openapi.alipay.com/gateway.do',
            'description' => '一般保持默认。使用沙箱环境时改为沙箱网关地址。',
        ],
        'return_url' => [
            'title' => '认证完成回跳地址',
            'type' => 'url',
            'value' => '',
            'required' => false,
            'placeholder' => '留空则由系统按用户控制台地址自动生成',
            'description' => '用户在支付宝完成认证后回跳的页面。留空时系统会传入控制台的实名认证页。',
        ],
        'request_timeout' => [
            'title' => '请求超时（秒）',
            'type' => 'number',
            'value' => 15,
            'min' => 5,
            'max' => 60,
            'step' => 1,
            'description' => '调用支付宝网关的超时时间，超出 5~60 秒范围会被自动钳制。',
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
