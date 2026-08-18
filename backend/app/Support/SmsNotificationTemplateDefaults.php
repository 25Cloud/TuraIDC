<?php

declare(strict_types=1);

namespace App\Support;

/**
 * 短信通知默认模板内容（channel=sms）。
 *
 * 说明：
 * - 只内置「验证码短信」100001 的最小必需内容，供非阿里云类短信驱动
 *   在 sendTemplateSms 路径渲染正文；阿里云验证码走驱动自身的
 *   verifyCodeTemplate + 云商模板 ID，不依赖本地 content。
 * - 其余业务短信模板（登录提醒等）的 content / provider_template_id
 *   由后台在「通知与接口 → 短信模板」按服务商要求维护。
 */
final class SmsNotificationTemplateDefaults
{
    /**
     * @return array<int, array<string, mixed>>
     */
    public static function templates(): array
    {
        return [
            [
                'code' => SmsTemplateCatalog::TEMPLATE_VERIFY_CODE,
                'name' => '验证码短信',
                'description' => '用户获取短信验证码时发送。',
                'content' => '您的验证码为{{code}}，请在{{expire_minutes}}分钟内填写，请勿向他人泄露。',
                'variables' => ['code', 'min', 'expire_minutes'],
                'audience' => 'user',
            ],
        ];
    }
}
