<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Support\TextSanitizer;
use PHPUnit\Framework\TestCase;

final class TextSanitizerTest extends TestCase
{
    public function test_clean_html_converts_upstream_html_to_plain_text(): void
    {
        $content = TextSanitizer::cleanHtml(
            '<p>hhh</p><p>&amp; 客服</p><script>alert(1)</script>已处理',
            true
        );

        self::assertSame("hhh\n& 客服\n已处理", $content);
    }

    public function test_clean_html_keeps_plain_text_unchanged(): void
    {
        self::assertSame('普通文本', TextSanitizer::cleanHtml('普通文本', true));
    }
}
