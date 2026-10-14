<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Support\BrandThemeTokens;
use App\Support\SiteConfigPayload;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * 品牌主色与圆角的回归。
 *
 * 这两处都要在首屏 <head> 里内联成 CSS 变量，错了会同时造成两种问题：
 * 视觉上首屏闪一下默认色（FOUC），以及非法配置被原样写进 style 变成样式注入点。
 *
 * 这里锁定：
 *  1) Element Plus 的 light-N / dark-2 必须按白黑混合算对，否则 hover 与 disabled
 *     会停留在编译期默认蓝色；
 *  2) 圆角按「控件 = 基数的一半、容器 = 基数」映射，基数 6 时与 TDesign 默认完全一致；
 *  3) 主色与圆角只放行严格格式，任何带分号 / 花括号 / url() 的输入都回退默认。
 *
 * 继承裸 PHPUnit TestCase：不启动 Laravel、不连数据库，可在任何环境安全执行。
 */
class BrandThemeTokensTest extends TestCase
{
    #[Test]
    public function element_plus_derived_colors_are_mixed_with_white_and_black(): void
    {
        $css = BrandThemeTokens::cssVariables('#165dff', 6);

        // 与白各档混合：#165DFF → (22,93,255)
        $this->assertStringContainsString('--el-color-primary:#165dff', $css);
        // light-5 = 与白 50%：(22+116.5, 93+81, 255) = (139,174,255)
        $this->assertStringContainsString('--el-color-primary-light-5:#8baeff', $css);
        // light-9 = 与白 90%：(231.7, 238.8, 255) = (232,239,255)
        $this->assertStringContainsString('--el-color-primary-light-9:#e8efff', $css);
        // dark-2 = 与黑 20%：(17.6, 74.4, 204) = (18,74,204)
        $this->assertStringContainsString('--el-color-primary-dark-2:#124acc', $css);
    }

    #[Test]
    public function radius_maps_to_framework_variables_consistently(): void
    {
        $css = BrandThemeTokens::cssVariables('#165dff', 6);

        $this->assertStringContainsString('--el-border-radius-base:6px', $css);
        $this->assertStringContainsString('--el-border-radius-small:3px', $css);
        $this->assertStringContainsString('--td-radius-medium:6px', $css);
        $this->assertStringContainsString('--td-radius-small:3px', $css);
        $this->assertStringContainsString('--td-radius-default:3px', $css);
        $this->assertStringContainsString('--td-radius-large:9px', $css);
    }

    #[Test]
    public function zero_radius_produces_square_corners_everywhere(): void
    {
        $css = BrandThemeTokens::cssVariables('#165dff', 0);

        foreach ([
            '--el-border-radius-base:0px',
            '--el-border-radius-small:0px',
            '--td-radius-small:0px',
            '--td-radius-medium:0px',
            '--td-radius-large:0px',
        ] as $expected) {
            $this->assertStringContainsString($expected, $css);
        }
    }

    #[Test]
    public function style_tag_is_wrapped_and_scoped_to_root(): void
    {
        $tag = BrandThemeTokens::styleTag('#165dff', 6);

        $this->assertStringStartsWith('<style id="brand-theme-tokens">', $tag);
        $this->assertStringEndsWith('</style>', $tag);
        $this->assertStringContainsString(':root{', $tag);
    }

    #[Test]
    public function color_accepts_only_strict_hex_and_expands_shorthand(): void
    {
        $this->assertSame('#165dff', $this->normalizeColor('#165DFF'), '六位写法应统一小写');
        $this->assertSame('#1166dd', $this->normalizeColor('#16D'), '三位写法应展开成六位');
    }

    /**
     * 主色会被原样内联进 <style>，这些输入必须全部回退默认，否则就是样式注入点。
     */
    #[Test]
    public function color_rejects_anything_that_could_break_out_of_the_style_tag(): void
    {
        $default = SiteConfigPayload::DEFAULT_BRAND_PRIMARY_COLOR;

        foreach ([
            'red',
            '#165DFF; background-image: url(//evil.example.com/x)',
            '#165DFF;}body{display:none',
            '#165DFF</style><script>alert(1)</script>',
            'rgb(22,93,255)',
            'var(--x)',
            '#165DFF ',
        ] as $hostile) {
            // 末尾空格属正常输入，应被 trim 后接受，因此单独排除
            if ($hostile === '#165DFF ') {
                $this->assertSame('#165dff', $this->normalizeColor($hostile));

                continue;
            }

            $this->assertSame(
                $default,
                $this->normalizeColor($hostile),
                '该输入必须回退默认色：'.$hostile
            );
        }
    }

    #[Test]
    public function radius_accepts_zero_to_max_and_rejects_the_rest(): void
    {
        $default = SiteConfigPayload::DEFAULT_BRAND_RADIUS;

        $this->assertSame(0, $this->normalizeRadius('0'), '直角是合法配置');
        $this->assertSame(12, $this->normalizeRadius('12'));
        $this->assertSame(24, $this->normalizeRadius('24'));

        foreach (['', 'abc', '-3', '6.5', '25', '999', '6px', '0x10'] as $invalid) {
            $this->assertSame(
                $default,
                $this->normalizeRadius($invalid),
                '该输入必须回退默认圆角：'.$invalid
            );
        }
    }

    private function normalizeColor(string $value): string
    {
        $method = new ReflectionMethod(SiteConfigPayload::class, 'normalizeColor');
        $method->setAccessible(true);

        return (string) $method->invoke(null, $value);
    }

    private function normalizeRadius(string $value): int
    {
        $method = new ReflectionMethod(SiteConfigPayload::class, 'normalizeRadius');
        $method->setAccessible(true);

        return (int) $method->invoke(null, $value);
    }
}