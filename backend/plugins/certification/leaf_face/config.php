<?php

declare(strict_types=1);

use TuraIDC\Plugins\Certification\LeafFace\LeafFacePlugin;

return [
    'info' => [
        'domain' => 'verification',
        'slug' => 'leaf_face',
        'key' => 'leaf_face',
        'name' => 'leaf实名',
        'version' => '1.0.0',
        'entry' => LeafFacePlugin::class,
        'capabilities' => ['personal', 'scan_url', 'query_status', 'verify_callback', 'fee_config'],
        'extra' => [
            'driver_binding' => [
                'binding_key' => 'verification_driver',
                'provider_key' => 'leaf_face',
            ],
        ],
    ],
    'config' => [
        'basic_notice' => [
            'title' => '配置说明',
            'type' => 'notice',
            'theme' => 'info',
            'content' => '请填写 leaf 平台的 AppId 与 AppSecret，并确认该应用已开通 h5_face 能力。密钥保存后不会明文回显。',
        ],
        'app_id' => [
            'title' => 'AppId',
            'type' => 'text',
            'value' => '',
            'required' => true,
            'placeholder' => '请输入 leaf 平台商户应用 AppId',
            'description' => 'leaf 平台商户应用标识，请求时放入 X-App-Id 请求头。',
        ],
        'app_secret' => [
            'title' => 'AppSecret',
            'type' => 'password',
            'value' => '',
            'required' => true,
            'secret' => true,
            'placeholder' => '请输入 leaf 平台商户应用 AppSecret',
            'description' => '用于请求签名与回调验签，保存后不会明文回显。',
        ],
        'api_base_url' => [
            'title' => '平台接口地址',
            'type' => 'text',
            'value' => 'https://face.ly-y.cn',
            'required' => true,
            'placeholder' => '请输入 leaf 平台接口地址',
            'description' => 'leaf 平台根地址，用于拼接任务创建、查询与 H5 认证页面链接。',
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
