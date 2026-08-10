<?php

declare(strict_types=1);

use EwyFinance\Plugins\Certification\BaiduFace\BaiduFacePlugin;
use EwyFinance\Plugins\Certification\BaiduFace\Logic\BaiduFaceClient;

return [
    'info' => [
        'domain' => 'verification',
        'slug' => 'baidu_face',
        'key' => 'baidu_face',
        'name' => '百度智能云人脸实名认证',
        'version' => '1.0.0',
        'entry' => BaiduFacePlugin::class,
        'capabilities' => ['personal', 'scan_url', 'query_status', 'direct_verify', 'fee_config'],
        'extra' => [
            'driver_binding' => [
                'binding_key' => 'verification_driver',
                'provider_key' => 'baidu_face',
            ],
            'config_saved_cache_clear' => [
                'class' => BaiduFaceClient::class,
                'method' => 'forgetAccessTokenCacheForConfig',
            ],
        ],
    ],
    'config' => [
        'basic_notice' => [
            'title' => '配置说明',
            'type' => 'notice',
            'theme' => 'info',
            'content' => '请填写百度智能云人脸识别应用的 API Key、Secret Key，并确认 H5 实名认证方案 ID。密钥保存后不会明文回显。',
        ],
        'api_key' => [
            'title' => '百度 API Key',
            'type' => 'password',
            'value' => '',
            'required' => true,
            'secret' => true,
            'placeholder' => '请输入百度智能云应用 API Key',
            'description' => '填写百度智能云人脸识别应用的 API Key。',
        ],
        'secret_key' => [
            'title' => '百度 Secret Key',
            'type' => 'password',
            'value' => '',
            'required' => true,
            'secret' => true,
            'placeholder' => '请输入百度智能云应用 Secret Key',
            'description' => '保存后系统会清空旧 access_token，下次调用实名接口时自动重新获取。',
        ],
        'api_version' => [
            'title' => '百度实名接口版本',
            'type' => 'select',
            'value' => 'v4',
            'required' => false,
            'options' => [
                ['label' => 'V4 - face/v4/mingjing/verify', 'value' => 'v4'],
                ['label' => 'V3 - face/v3/person/verify', 'value' => 'v3'],
            ],
            'description' => 'V4 支持更多风控返回字段，V3 用于兼容旧接口。当前用户端实名流程默认走 H5 方案，接口版本用于服务端直连动作。',
        ],
        'h5_plan_id' => [
            'title' => 'H5 方案ID',
            'type' => 'number',
            'value' => 25921,
            'required' => true,
            'min' => 1,
            'step' => 1,
            'description' => '使用 H5 人脸实名认证方案时必填，用于获取 verify_token。',
        ],
    ],
];
