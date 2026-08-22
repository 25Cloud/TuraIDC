<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Support\RichHtmlSanitizer;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * 富文本消毒：标签 + 属性 + URL 协议三层白名单。
 *
 * 被替换掉的 `strip_tags($html, $白名单)` 只做标签名过滤，属性原样保留，
 * 于是 `<img src=x onerror=...>` 整条通过。这批断言钉住属性层。
 */
class RichHtmlSanitizerTest extends TestCase
{
    /** @return list<array{0: string, 1: string}> */
    public static function eventHandlerProvider(): array
    {
        return [
            ['<img src="x" onerror="alert(1)">', 'onerror'],
            ['<p onmouseover="fetch(1)">hover</p>', 'onmouseover'],
            ['<div onclick="steal()">click</div>', 'onclick'],
            ['<a href="/ok" onfocus="x()">link</a>', 'onfocus'],
            ['<img src="x" ONERROR="alert(1)">', 'onerror'],
            ['<img src="x" onload="alert(1)">', 'onload'],
        ];
    }

    #[DataProvider('eventHandlerProvider')]
    public function test_event_handler_attributes_are_removed(string $input, string $attribute): void
    {
        $output = RichHtmlSanitizer::sanitize($input);

        $this->assertStringNotContainsStringIgnoringCase(
            $attribute,
            $output,
            "事件属性 {$attribute} 必须被剥离，否则公开页存在存储型 XSS"
        );
    }

    /** @return list<array{0: string}> */
    public static function dangerousUrlProvider(): array
    {
        return [
            ['<a href="javascript:alert(1)">x</a>'],
            ['<a href="JaVaScRiPt:alert(1)">x</a>'],
            ['<a href="vbscript:msgbox(1)">x</a>'],
            ['<img src="data:text/html;base64,PHNjcmlwdD4=">'],
            ['<a href="file:///etc/passwd">x</a>'],
            // 控制字符插入是经典绕过：浏览器解析 URL 时会自行忽略它们
            ["<a href=\"java\tscript:alert(1)\">x</a>"],
            ["<a href=\"java\nscript:alert(1)\">x</a>"],
        ];
    }

    #[DataProvider('dangerousUrlProvider')]
    public function test_dangerous_url_schemes_are_removed(string $input): void
    {
        $output = RichHtmlSanitizer::sanitize($input);

        foreach (['javascript', 'vbscript', 'data:text/html', 'file:'] as $scheme) {
            $this->assertStringNotContainsStringIgnoringCase($scheme, $output, "危险协议 {$scheme} 必须被剥离");
        }
    }

    public function test_script_and_style_tags_are_dropped_with_their_content(): void
    {
        $output = RichHtmlSanitizer::sanitize('<p>before</p><script>alert(1)</script><style>body{}</style><p>after</p>');

        $this->assertStringNotContainsString('alert(1)', $output, 'script 内容必须整段删除，不能剥壳留字');
        $this->assertStringNotContainsString('body{}', $output);
        $this->assertStringContainsString('before', $output);
        $this->assertStringContainsString('after', $output);
    }

    public function test_iframe_and_object_are_dropped(): void
    {
        $output = RichHtmlSanitizer::sanitize('<iframe src="//evil.test"></iframe><object data="x"></object><p>keep</p>');

        $this->assertStringNotContainsString('iframe', $output);
        $this->assertStringNotContainsString('object', $output);
        $this->assertStringContainsString('keep', $output);
    }

    public function test_legitimate_markup_survives(): void
    {
        $output = RichHtmlSanitizer::sanitize(
            '<h2>标题</h2><p>正文 <strong>加粗</strong> 与 <em>斜体</em></p>'
            .'<ul><li>条目</li></ul>'
            .'<a href="https://example.test/doc" title="文档">链接</a>'
            .'<img src="https://cdn.example.test/a.png" alt="图" width="640">'
            .'<table><thead><tr><th colspan="2">头</th></tr></thead><tbody><tr><td>格</td></tr></tbody></table>'
        );

        foreach (['<h2>', '<strong>', '<em>', '<ul>', '<li>', '<table>', '<th', '<td>'] as $tag) {
            $this->assertStringContainsString($tag, $output, "合法标签 {$tag} 不应被剥离");
        }

        $this->assertStringContainsString('https://example.test/doc', $output, '合法 href 必须保留');
        $this->assertStringContainsString('https://cdn.example.test/a.png', $output, '合法 img src 必须保留');
        $this->assertStringContainsString('alt="图"', $output);
        $this->assertStringContainsString('title="文档"', $output);
        $this->assertStringContainsString('colspan="2"', $output);
        $this->assertStringContainsString('width="640"', $output);
        $this->assertStringContainsString('标题', $output);
        $this->assertStringContainsString('正文', $output);
    }

    /**
     * rel="opener" 必须被剔除。
     *
     * 现代浏览器对 target="_blank" 默认隐含 noopener，但 rel="opener" 会显式覆盖该默认
     * 把 window.opener 还回去，目标页于是能 window.opener.location = '钓鱼页' 改写原页面。
     * 整串放行 rel 等于把这个开关交给内容作者。
     */
    public function test_rel_opener_token_is_stripped(): void
    {
        $output = RichHtmlSanitizer::sanitize(
            '<a href="https://evil.test" target="_blank" rel="opener">tabnabbing</a>'
        );

        $this->assertStringContainsString('rel="noopener"', $output, '应补上 noopener');
        // 按 token 断言而非子串：noopener 里就含 "opener" 字样，子串比对会自己骗自己
        $this->assertDoesNotMatchRegularExpression(
            '/rel="[^"]*\bopener\b/',
            $output,
            'rel 中不得残留裸 opener token'
        );
    }

    /** target="_blank" 即使原本没有 rel，也要补出 noopener。 */
    public function test_blank_target_gets_noopener_even_without_rel(): void
    {
        $output = RichHtmlSanitizer::sanitize('<a href="https://ok.test" target="_blank">x</a>');

        $this->assertStringContainsString('noopener', $output);
    }

    /** 已有的安全 rel token 要保留，不能被覆盖掉。 */
    public function test_existing_safe_rel_tokens_survive_alongside_noopener(): void
    {
        $output = RichHtmlSanitizer::sanitize(
            '<a href="https://ok.test" target="_blank" rel="nofollow ugc">x</a>'
        );

        $this->assertStringContainsString('nofollow', $output);
        $this->assertStringContainsString('ugc', $output);
        $this->assertStringContainsString('noopener', $output);
    }

    /** 未知 rel token 一并丢弃，但不影响同串里的合法 token。 */
    public function test_unknown_rel_tokens_are_dropped(): void
    {
        $output = RichHtmlSanitizer::sanitize('<a href="https://ok.test" rel="nofollow bogusrel">x</a>');

        $this->assertStringContainsString('nofollow', $output);
        $this->assertStringNotContainsString('bogusrel', $output);
    }

    /** 不带 target="_blank" 的链接不应被强塞 noopener（那是无意义噪音）。 */
    public function test_same_tab_link_is_not_given_noopener(): void
    {
        $output = RichHtmlSanitizer::sanitize('<a href="https://ok.test">x</a>');

        $this->assertStringNotContainsString('noopener', $output);
    }

    /** target 只允许 _blank / _self，_parent、_top 在公开正文里没有正当用途。 */
    public function test_unsafe_target_values_are_removed(): void
    {
        foreach (['_parent', '_top', 'someframe'] as $target) {
            $output = RichHtmlSanitizer::sanitize('<a href="https://ok.test" target="'.$target.'">x</a>');

            $this->assertStringNotContainsString($target, $output, "target={$target} 应被剥离");
        }

        $this->assertStringContainsString(
            'target="_self"',
            RichHtmlSanitizer::sanitize('<a href="https://ok.test" target="_self">x</a>'),
            'target="_self" 属合法取值，应保留'
        );
    }

    public function test_relative_and_anchor_urls_are_kept(): void
    {
        $output = RichHtmlSanitizer::sanitize(
            '<a href="/help/1">站内</a><a href="#top">锚点</a><a href="mailto:a@b.test">邮件</a>'
        );

        $this->assertStringContainsString('href="/help/1"', $output);
        $this->assertStringContainsString('href="#top"', $output);
        $this->assertStringContainsString('mailto:a@b.test', $output);
    }

    public function test_image_src_rejects_mailto_and_tel(): void
    {
        // 图片来源只接受 http/https，与前端 IMAGE_PROTOCOLS 口径一致
        $output = RichHtmlSanitizer::sanitize('<img src="mailto:a@b.test" alt="x">');

        $this->assertStringNotContainsString('mailto:', $output);
    }

    public function test_non_whitelisted_tag_is_unwrapped_keeping_text(): void
    {
        $output = RichHtmlSanitizer::sanitize('<marquee>滚动文字</marquee>');

        $this->assertStringNotContainsString('marquee', $output, '非白名单标签本身应去掉');
        $this->assertStringContainsString('滚动文字', $output, '但文字内容应保留，避免正文凭空消失');
    }

    public function test_html_comments_are_removed(): void
    {
        $output = RichHtmlSanitizer::sanitize('<p>a</p><!--[if IE]><script>x()</script><![endif]--><p>b</p>');

        $this->assertStringNotContainsString('<!--', $output);
        $this->assertStringNotContainsString('x()', $output);
    }

    public function test_enum_and_numeric_attributes_are_validated(): void
    {
        $output = RichHtmlSanitizer::sanitize(
            '<img src="https://a.test/x.png" alt="i" loading="evil" width="abc" height="12">'
        );

        $this->assertStringNotContainsString('loading="evil"', $output, '枚举属性取值非法应剥离');
        $this->assertStringNotContainsString('width="abc"', $output, '数值属性非数字应剥离');
        $this->assertStringContainsString('height="12"', $output, '合法数值应保留');
    }

    public function test_empty_input_returns_empty_string(): void
    {
        $this->assertSame('', RichHtmlSanitizer::sanitize(''));
        $this->assertSame('', RichHtmlSanitizer::sanitize('   '));
    }

    public function test_multibyte_content_is_preserved(): void
    {
        $output = RichHtmlSanitizer::sanitize('<p>中文内容与 emoji 🎉 都要完整保留</p>');

        $this->assertStringContainsString('中文内容与', $output);
        $this->assertStringContainsString('🎉', $output);
    }
}
