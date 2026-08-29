<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Support\NotificationTemplateContent;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * 通知模板正文的入库净化。
 *
 * 原实现是单遍黑名单 `preg_replace('/<\/?script\b[^>]*>/iu', '', $value)`，注释却声称会
 * 移除 script/iframe/object/embed 与事件处理器。本地实测 9 个常见载荷放行 8 个，且
 * `<scr<script>ipt>` 剥掉内层后会**重组出**可执行标签 —— 净化反而使情况变坏。
 * 本用例把当时那批载荷逐条钉死，并锁住「纯文本不动、HTML 才净化」的口径。
 */
class NotificationTemplateContentTest extends TestCase
{
    /**
     * 当年逐条实测绕过原黑名单的载荷，全部必须被白名单净化挡下。
     *
     * @return iterable<string, array{string}>
     */
    public static function xssPayloadProvider(): iterable
    {
        yield '事件处理器' => ['<img src=x onerror=alert(document.cookie)>'];
        yield 'SVG 事件' => ['<svg onload=alert(1)>'];
        yield 'iframe' => ['<iframe src="javascript:alert(1)"></iframe>'];
        yield 'object' => ['<object data="javascript:alert(1)"></object>'];
        yield 'embed' => ['<embed src="data:text/html,<script>alert(1)</script>">'];
        yield 'body onload' => ['<body onload=alert(1)>'];
        yield 'a 标签 javascript 协议' => ['<a href="javascript:alert(1)">x</a>'];
        yield '普通 script' => ['<p>正文</p><script>alert(1)</script>'];
        yield '单遍剥离后重组成标签' => ['<div><scr<script>ipt>alert(1)</scr</script>ipt></div>'];
    }

    #[DataProvider('xssPayloadProvider')]
    public function test_email_html_content_is_stripped_of_executable_vectors(string $payload): void
    {
        $sanitized = NotificationTemplateContent::sanitizeForStorage('email', 'content', $payload);

        $this->assertDoesNotMatchRegularExpression(
            '/<\s*(script|iframe|object|embed|svg|body)\b/i',
            $sanitized,
            '危险标签必须被移除：'.$payload
        );
        $this->assertDoesNotMatchRegularExpression(
            '/\bon[a-z]+\s*=/i',
            $sanitized,
            '事件处理器必须被移除：'.$payload
        );
        $this->assertStringNotContainsStringIgnoringCase(
            'javascript:',
            $sanitized,
            'javascript: 协议必须被移除：'.$payload
        );
    }

    public function test_plain_text_content_is_left_untouched(): void
    {
        // 纯文本正文渲染时走 convertPlainTextToHtml()，逐行 htmlspecialchars，本身已安全。
        // 此处若强行过 HTML 净化器，'&' 会先变 '&amp;'，渲染时再转义成 '&amp;amp;'，
        // 等于凭空制造双重编码 —— 这条用例就是防止有人"顺手"把它改成一刀切净化。
        $plain = "尊敬的 {{display_name}}，您的账单金额为 100 元 & 已含税。\n请于 {{due_date}} 前完成支付。";

        $this->assertSame(
            $plain,
            NotificationTemplateContent::sanitizeForStorage('email', 'content', $plain)
        );
    }

    public function test_subject_is_reduced_to_plain_text(): void
    {
        $sanitized = NotificationTemplateContent::sanitizeForStorage(
            'email',
            'subject',
            "【{{site_name}}】<b>验证码</b>邮件\r\nBcc: attacker@example.com"
        );

        $this->assertStringNotContainsString('<b>', $sanitized, '标题进邮件头，HTML 无正当用途');
        $this->assertStringNotContainsString("\n", $sanitized, '换行会带来邮件头注入面');
        $this->assertStringNotContainsString("\r", $sanitized);
        $this->assertStringContainsString('{{site_name}}', $sanitized, '占位符不能被破坏');
    }

    public function test_sms_content_is_not_run_through_the_html_sanitizer(): void
    {
        // 短信不经 HTML 渲染，过 HTML 净化器只会改动内容而不带来任何安全收益。
        $sms = '【{{site_name}}】验证码 {{code}}，10 分钟内有效 & 请勿转发。';

        $this->assertSame($sms, NotificationTemplateContent::sanitizeForStorage('sms', 'content', $sms));
    }

    public function test_other_fields_are_untouched(): void
    {
        $value = '<b>provider-template-id</b>';

        $this->assertSame(
            $value,
            NotificationTemplateContent::sanitizeForStorage('sms', 'provider_template_id', $value)
        );
    }

    /**
     * 保存侧与渲染侧必须用同一个判断，否则会出现「存时按纯文本放行、渲染时按 HTML
     * 原样输出」的缝隙 —— 那正是存储型 XSS 的入口。
     */
    public function test_html_detection_matches_the_render_side_contract(): void
    {
        $this->assertTrue(NotificationTemplateContent::looksLikeHtml('<p>hi</p>'));
        $this->assertTrue(NotificationTemplateContent::looksLikeHtml('  <!DOCTYPE html><html></html>'));
        $this->assertTrue(NotificationTemplateContent::looksLikeHtml('文字在前 <div>再有标签</div>'));
        $this->assertFalse(NotificationTemplateContent::looksLikeHtml('纯文本 {{code}}'));
        $this->assertFalse(NotificationTemplateContent::looksLikeHtml('价格 < 100 元'));
        $this->assertFalse(NotificationTemplateContent::looksLikeHtml(''));
    }
}
