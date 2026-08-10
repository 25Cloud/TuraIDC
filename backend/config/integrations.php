<?php

use App\Constants\PaymentGatewayCode;

return [
    'payments' => [
        'default' => env('PAYMENT_GATEWAY_DEFAULT', PaymentGatewayCode::ALIPAY),
        'drivers' => [
            PaymentGatewayCode::ALIPAY => [
                'name' => '支付宝当面付',
                'provider' => PaymentGatewayCode::ALIPAY,
            ],
            PaymentGatewayCode::YIPAY => [
                'name' => '易支付',
                'provider' => PaymentGatewayCode::YIPAY,
            ],
        ],
    ],
    'identity' => [
        'default' => env('IDENTITY_PROVIDER_DEFAULT', 'stay33'),
        'drivers' => [
            'stay33' => [
                'name' => 'Stay33 实名认证',
            ],
        ],
    ],
    'sms' => [
        'default' => env('SMS_PROVIDER_DEFAULT', 'aliyun'),
        'drivers' => [
            'aliyun' => [
                'name' => '阿里云短信',
            ],
        ],
    ],
    'mail' => [
        'default' => env('MAIL_DRIVER_DEFAULT', 'smtp'),
        'drivers' => [
            'smtp' => [
                'name' => 'Single SMTP',
            ],
            'multi_smtp_round_robin' => [
                'name' => 'Multi SMTP Round Robin',
            ],
        ],
    ],
    'upstream' => [
        'default' => env('UPSTREAM_PROVIDER_DEFAULT', ''),
        'preserve_provider_keys' => [
            'zjmf_finance_api',
            'hosting_panel_api',
        ],
    ],
];
