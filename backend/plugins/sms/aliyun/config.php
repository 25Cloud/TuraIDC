<?php

declare(strict_types=1);
use TuraIDC\Plugins\Sms\Aliyun\AliyunPlugin;

/**
 * 阿里云「号码认证服务（PNVS）」插件配置——用其中的「短信认证」功能发送验证码。
 *
 * 与同域的 aliyun_sms 插件不是一回事，两者对应阿里云的两个独立产品：
 *
 * - aliyun      → 号码认证服务（PNVS）/ Dypnsapi / SendSmsVerifyCode
 *                 控制台 https://dypns.console.aliyun.com/
 *                 文档   https://help.aliyun.com/zh/pnvs/developer-reference/api-dypnsapi-2017-05-25-sendsmsverifycode
 *                 模板为控制台赠送的内置编号（100001~100005），不可自定义；免资质，仅支持中国大陆号码。
 *
 * - aliyun_sms  → 短信服务（SMS）/ Dysmsapi / SendSms
 *                 控制台 https://dysms.console.aliyun.com/
 *                 文档   https://help.aliyun.com/zh/sms/developer-reference/api-dysmsapi-2017-05-25-sendsms
 *                 模板为控制台自建的 SMS_xxxxxxxxx，变量名自定义；需先完成资质报备与签名/模板审核。
 *
 * 两者的控制台、接口、套餐包、签名与模板体系彼此独立，模板不能互换。
 */
return [
    'info' => [
        'domain' => 'sms',
        'slug' => 'aliyun',
        'key' => 'aliyun',
        'name' => '阿里云号码认证',
        'version' => '1.0.0',
        'entry' => AliyunPlugin::class,
        'capabilities' => ['verify_code'],
        'extra' => [
            'driver_binding' => [
                'binding_key' => 'sms_driver',
                'provider_key' => 'aliyun',
            ],
        ],
    ],
    'config' => [
        'credential_notice' => [
            'title' => '配置说明',
            'type' => 'notice',
            'theme' => 'warning',
            'content' => '本插件对应阿里云「号码认证服务（PNVS）」的短信认证功能，'
                .'接口为 dypnsapi.aliyuncs.com / SendSmsVerifyCode。'
                ."\n控制台：https://dypns.console.aliyun.com/"
                ."\n产品介绍：https://www.aliyun.com/product/dypns"
                ."\n接口文档：https://help.aliyun.com/zh/pnvs/developer-reference/api-dypnsapi-2017-05-25-sendsmsverifycode"
                ."\n开通指引：https://help.aliyun.com/zh/pnvs/use-cases/sms-verify-for-individual-developers"
                ."\n\n请使用拥有号码认证服务权限的阿里云 AccessKey，密钥保存后不会明文回显。"
                .'验证码模板编号由插件按业务场景内置选择（对应控制台赠送的 100001~100005 五个模板），因此没有模板 ID 填写项。'
                ."\n\n注意：本插件与同域的「阿里云短信服务」插件是阿里云的两个独立产品——"
                .'控制台、接口、套餐包、签名与模板体系都各自独立，模板不能互换；'
                .'用本插件发送验证码扣减的是号码认证服务的套餐包，不能用短信服务的套餐抵扣。'
                .'另外号码认证的短信认证功能目前只支持中国大陆手机号（国家码 86）。',
        ],
        'access_key' => ['title' => 'Access Key', 'type' => 'password', 'value' => '', 'required' => true, 'secret' => true, 'placeholder' => '请输入 Access Key ID'],
        'secret_key' => ['title' => 'Secret Key', 'type' => 'password', 'value' => '', 'required' => true, 'secret' => true, 'placeholder' => '请输入 Access Key Secret'],
        'sign_name' => [
            'title' => '短信签名',
            'type' => 'text',
            'value' => '',
            'required' => true,
            'placeholder' => '请输入号码认证控制台中的短信签名',
            'description' => '填写号码认证服务控制台（https://dypns.console.aliyun.com/）中可用于发送验证码的短信签名。'
                .'推荐直接用控制台赠送的签名——运营商对自定义签名管控较严，容易下发失败；'
                .'且系统赠送签名必须搭配系统赠送模板使用，而本插件用的正是赠送模板。',
        ],
        // 限流各项都有合理默认值，默认收起，避免管理员误改
        'rate_limit_divider' => ['title' => '验证码限流', 'type' => 'divider', 'collapsible' => true],
        'rate_limit_enabled' => ['title' => '启用短信验证码限流', 'type' => 'switch', 'value' => true, 'required' => false, 'description' => '限制使用此插件发送短信验证码的单 IP 频率。'],
        'ip_minute_limit' => ['title' => '单 IP 每分钟上限', 'type' => 'number', 'value' => 6, 'required' => false, 'min' => 0, 'step' => 1, 'description' => '设为 0 表示不限制。'],
    ],
];
