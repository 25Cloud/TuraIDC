<?php

declare(strict_types=1);

use TuraIDC\Plugins\Sms\AliyunSms\AliyunSmsPlugin;

/**
 * 阿里云「短信服务（SMS）」插件配置。
 *
 * 与同域的 aliyun 插件不是一回事，两者对应阿里云的两个独立产品：
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
 * 因此这里把模板 ID 与变量名都做成可配置项，便于直接复用已在短信控制台备案好的模板。
 */
return [
    'info' => [
        'domain' => 'sms',
        'slug' => 'aliyun_sms',
        'key' => 'aliyun_sms',
        'name' => '阿里云短信服务',
        'version' => '1.0.0',
        'entry' => AliyunSmsPlugin::class,
        // 能力名与同域其他短信插件保持一致（demo_sms / stay33 用的都是 message）
        'capabilities' => ['verify_code', 'message'],
        'extra' => [
            'driver_binding' => [
                'binding_key' => 'sms_driver',
                'provider_key' => 'aliyun_sms',
            ],
        ],
    ],
    'config' => [
        'credential_notice' => [
            'title' => '配置说明',
            'type' => 'notice',
            'theme' => 'info',
            'content' => '本插件对应阿里云「短信服务（SMS）」，接口为 dysmsapi.aliyuncs.com / SendSms，采用 V3 签名。'
                ."\n控制台：https://dysms.console.aliyun.com/"
                ."\n产品介绍：https://www.aliyun.com/product/sms"
                ."\n接口文档：https://help.aliyun.com/zh/sms/developer-reference/api-dysmsapi-2017-05-25-sendsms"
                ."\n模板申请：https://help.aliyun.com/zh/sms/user-guide/create-message-templates-1"
                ."\n\n请填写具备短信发送权限的 AccessKey；子账号需授予 AliyunDysmsFullAccess 或等效权限。"
                .'密钥保存后不会明文回显。'
                ."\n\n注意：本插件与同域的「阿里云号码认证」插件是阿里云的两个独立产品——"
                .'控制台、接口、套餐包、签名与模板体系都各自独立，模板不能互换。'
                .'本产品需要先完成资质报备，再依次申请签名与模板（模板审核一般 2 小时内），'
                .'而号码认证服务用的是控制台赠送的签名与模板、免资质但只支持中国大陆号码。'
                .'请按实际备案情况选择其中一个启用。',
        ],
        'access_key' => [
            'title' => 'Access Key ID',
            'type' => 'password',
            'value' => '',
            'required' => true,
            'secret' => true,
            'placeholder' => '请输入 AccessKey ID',
        ],
        'secret_key' => [
            'title' => 'Access Key Secret',
            'type' => 'password',
            'value' => '',
            'required' => true,
            'secret' => true,
            'placeholder' => '请输入 AccessKey Secret',
        ],
        'sign_name' => [
            'title' => '短信签名',
            'type' => 'text',
            'value' => '',
            'required' => true,
            'placeholder' => '请输入已审核通过的短信签名',
            'description' => '短信服务控制台（https://dysms.console.aliyun.com/）'
                .'「国内消息 → 签名管理」中状态为「审核通过」的签名名称，'
                .'保存后可用「检测」按钮核验是否在可用列表内。',
        ],
        'endpoint' => [
            'title' => '接入地址',
            'type' => 'text',
            'value' => 'dysmsapi.aliyuncs.com',
            'required' => false,
            'placeholder' => 'dysmsapi.aliyuncs.com',
            'description' => '一般无需修改。仅在使用专属/国际站接入点时才需要调整。',
        ],

        'template_divider' => [
            'title' => '验证码模板',
            'type' => 'divider',
        ],
        'template_notice' => [
            'title' => '模板说明',
            'type' => 'notice',
            'theme' => 'info',
            'content' => '「默认模板」是必填项，所有场景都会回退到它；下方各场景模板留空即沿用默认模板。'
                .'如果你的阿里云账号只备案了一个验证码模板，只填默认模板即可。'
                ."\n「验证码变量名」必须与模板正文中的占位符一致——例如模板写作 \${captcha} 就填 captcha，"
                .'写作 ${code} 就填 code；填错会导致阿里云返回「模板参数缺失」。'
                .'配置完成后点「检测」按钮，会自动比对模板正文里的变量名，提前发现这类错配。'
                ."\n模板 CODE 可在短信服务控制台（https://dysms.console.aliyun.com/）"
                .'的「国内消息 → 模板管理」中查看。',
        ],
        'template_code' => [
            'title' => '默认模板 ID',
            'type' => 'text',
            'value' => '',
            'required' => true,
            'placeholder' => '形如 SMS_123456789',
            'description' => '阿里云短信控制台中的模板 CODE。',
        ],
        'code_variable' => [
            'title' => '验证码变量名',
            'type' => 'text',
            'value' => 'code',
            'required' => true,
            'placeholder' => 'code',
            'description' => '模板正文里承载验证码的变量名，不含 ${}。常见为 code 或 captcha。',
        ],
        'expire_variable' => [
            'title' => '有效期变量名',
            'type' => 'text',
            'value' => '',
            'required' => false,
            'placeholder' => '留空表示模板中没有有效期变量',
            'description' => '若模板正文还带「${min}分钟内有效」这类占位符，在此填写该变量名；'
                .'留空则不向阿里云传递该变量。填了但模板里没有此变量会被阿里云拒绝。',
        ],

        'scene_template_divider' => [
            'title' => '分场景模板（可选）',
            'type' => 'divider',
            'collapsible' => true,
        ],
        'template_login' => [
            'title' => '登录 / 注册',
            'type' => 'text',
            'value' => '',
            'required' => false,
            'placeholder' => '留空沿用默认模板',
        ],
        'template_change_phone' => [
            'title' => '修改手机号',
            'type' => 'text',
            'value' => '',
            'required' => false,
            'placeholder' => '留空沿用默认模板',
        ],
        'template_reset_password' => [
            'title' => '重置密码',
            'type' => 'text',
            'value' => '',
            'required' => false,
            'placeholder' => '留空沿用默认模板',
        ],
        'template_bind_phone' => [
            'title' => '绑定手机号',
            'type' => 'text',
            'value' => '',
            'required' => false,
            'placeholder' => '留空沿用默认模板',
        ],
        'template_verify_bound_phone' => [
            'title' => '验证已绑定手机号',
            'type' => 'text',
            'value' => '',
            'required' => false,
            'placeholder' => '留空沿用默认模板',
        ],

        'rate_limit_divider' => [
            'title' => '验证码限流',
            'type' => 'divider',
            'collapsible' => true,
        ],
        'rate_limit_enabled' => [
            'title' => '启用短信验证码限流',
            'type' => 'switch',
            'value' => true,
            'required' => false,
            'description' => '限制使用此插件发送短信验证码的单 IP 频率。短信按条计费，建议保持开启。',
        ],
        'ip_minute_limit' => [
            'title' => '单 IP 每分钟上限',
            'type' => 'number',
            'value' => 6,
            'required' => false,
            'min' => 0,
            'step' => 1,
            'description' => '设为 0 表示不限制。',
        ],

        'advanced_divider' => [
            'title' => '高级',
            'type' => 'divider',
            'collapsible' => true,
        ],
        'request_timeout' => [
            'title' => '请求超时（秒）',
            'type' => 'number',
            'value' => 10,
            'min' => 3,
            'max' => 30,
            'required' => false,
            'description' => '调用阿里云短信接口的 HTTP 超时。',
        ],
    ],
];
