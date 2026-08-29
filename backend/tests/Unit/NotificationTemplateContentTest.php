<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Support\EmailNotificationTemplateDefaults;
use App\Support\NotificationTemplateContent;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * 通知模板字段的入库净化。
 *
 * 这批用例锁的是两件事：subject 必须降为纯文本；正文必须**原样入库**。
 * 后者不是"没做净化"，而是刻意的——论证见 NotificationTemplateContent 的类注释。
 */
class NotificationTemplateContentTest extends TestCase
{
    /**
     * 默认邮件模板必须一字不改地入库。
     *
     * 这是本文件最重要的一条。它们是带 <style> 的完整 HTML 文档，一旦有人"顺手"把正文
     * 接上 RichHtmlSanitizer，7536 字节会只剩 1898：doctype/html/head/body 被剥壳、
     * 整段 CSS 被丢弃、table 排版属性被清空，管理员保存一次就毁掉整套邮件排版。
     * 这条用例就是拦这个的。
     */
    public function test_default_email_templates_survive_storage_unchanged(): void
    {
        $templates = EmailNotificationTemplateDefaults::templates();

        $this->assertNotEmpty($templates, '默认邮件模板不应为空，否则本用例形同虚设');

        foreach ($templates as $template) {
            $content = (string) ($template['content'] ?? '');
            $code = (string) ($template['code'] ?? '?');

            $this->assertSame(
                $content,
                NotificationTemplateContent::sanitizeForStorage('content', $content),
                "默认邮件模板 {$code} 的正文被入库净化改动了"
            );
        }
    }

    /**
     * 带 <style> 的自定义正文同样原样入库。
     *
     * 取值与 Feature 层 test_admin_notification_email_template_save_... 保存的内容一致，
     * 那条用例断言的正是 assertSame($newContent, $template->content)。
     */
    public function test_custom_html_content_with_style_block_is_stored_verbatim(): void
    {
        $content = '<style>.email-test-code { color: #1f5eff; font-weight: 700; }</style>'
            .'<div class="email-test-code">{{code}}</div>';

        $this->assertSame($content, NotificationTemplateContent::sanitizeForStorage('content', $content));
    }

    /**
     * 纯文本、短信正文、以及 subject/content 之外的字段，一律不动。
     *
     * @return iterable<string, array{string, string}>
     */
    public static function untouchedFieldProvider(): iterable
    {
        // 纯文本正文渲染时走 convertPlainTextToHtml() 逐行 htmlspecialchars，本身已安全；
        // 此处若过一遍 HTML 净化器，'&' 会先变 '&amp;'，渲染时再转义成 '&amp;amp;'。
        yield '纯文本正文' => ['content', "尊敬的 {{display_name}}，账单金额 100 元 & 已含税。\n请于 {{due_date}} 前支付。"];
        yield '短信正文' => ['content', '【{{site_name}}】验证码 {{code}}，10 分钟内有效 & 请勿转发。'];
        yield '短信模板号' => ['provider_template_id', 'SMS_DB_VERIFY'];
        yield '带尖括号的普通文案' => ['content', '价格 < 100 元时享受折扣 > 8 折'];
    }

    #[DataProvider('untouchedFieldProvider')]
    public function test_non_subject_fields_are_left_untouched(string $field, string $value): void
    {
        $this->assertSame($value, NotificationTemplateContent::sanitizeForStorage($field, $value));
    }

    public function test_subject_is_reduced_to_plain_text(): void
    {
        $sanitized = NotificationTemplateContent::sanitizeForStorage(
            'subject',
            "【{{site_name}}】<b>验证码</b>邮件\r\nBcc: attacker@example.com"
        );

        $this->assertStringNotContainsString('<b>', $sanitized, '标题进邮件头，HTML 无正当用途');
        $this->assertStringNotContainsString("\n", $sanitized, '换行会带来邮件头注入面');
        $this->assertStringNotContainsString("\r", $sanitized);
        $this->assertStringContainsString('{{site_name}}', $sanitized, '占位符不能被破坏');
    }

    /**
     * 现有 Feature 用例保存的普通标题不能被净化改动，否则那条断言会转红。
     */
    public function test_ordinary_subject_is_not_altered(): void
    {
        $subject = '后台保存验证码邮件';

        $this->assertSame($subject, NotificationTemplateContent::sanitizeForStorage('subject', $subject));
    }
}
