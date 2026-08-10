<?php

return [
    // 支付宝应用ID
    'app_id' => env('ALIPAY_APP_ID', ''),

    // 应用私钥（PKCS1格式，一行字符串）
    'private_key' => env('ALIPAY_PRIVATE_KEY', ''),

    // 支付宝公钥（非证书模式使用）
    'alipay_public_key' => env('ALIPAY_PUBLIC_KEY', ''),

    // 异步通知地址
    'notify_url' => env('ALIPAY_NOTIFY_URL', ''),

    // 网关地址（正式: https://openapi.alipay.com/gateway.do  沙箱: https://openapi-sandbox.dl.alipaydev.com/gateway.do）
    'gateway' => env('ALIPAY_GATEWAY', 'https://openapi.alipay.com/gateway.do'),

    // 签名类型
    'sign_type' => 'RSA2',

    // 编码
    'charset' => 'utf-8',

    // 订单超时时间
    'timeout' => '30m',

    'ssl_verify' => filter_var(env('ALIPAY_SSL_VERIFY', env('APP_ENV') !== 'local'), FILTER_VALIDATE_BOOL),
    'ca_bundle' => env('ALIPAY_CA_BUNDLE', ''),
];
