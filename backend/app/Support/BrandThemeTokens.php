<?php

declare(strict_types=1);

namespace App\Support;

/**
 * 品牌主题 CSS 变量
 *
 * 商家在后台配的主色与圆角，必须在内联脚本执行之前就写进 <head> 的 <style>，
 * 否则首屏会先渲染出编译期默认色再跳到品牌色（FOUC）。
 *
 * 为什么派生色要在后端算：官网用 sass 在编译期注入 Element Plus 主题，
 * --el-color-primary-light-3/5/7/8/9 与 dark-2 在编译产物里是写死的，
 * 运行时只覆盖 --el-color-primary 的话，hover / disabled 仍是旧蓝色。
 * TDesign 的十级色阶由前端既有的 changeBrandTheme 用 tvision-color 生成，
 * 这里不重复实现，只给它的主色与圆角变量。
 *
 * 入参的 hex 必须已经过校验（见 SiteConfigPayload::normalizeColor），
 * 本类只做色彩运算，不重复做格式校验。
 */
final class BrandThemeTokens
{
    /**
     * 生成内联 <style> 标签，插到 <head> 里最靠前的位置。
     */
    public static function styleTag(string $primaryColor, int $radius): string
    {
        return '<style id="brand-theme-tokens">'.self::cssVariables($primaryColor, $radius).'</style>';
    }

    public static function cssVariables(string $primaryColor, int $radius): string
    {
        $radius = max(0, $radius);
        $small = (int) round($radius / 2);
        $large = (int) round($radius * 1.5);

        $vars = [
            // Element Plus 主色与派生色（官网）
            '--el-color-primary' => $primaryColor,
            '--el-color-primary-light-3' => self::mixWhite($primaryColor, 0.3),
            '--el-color-primary-light-5' => self::mixWhite($primaryColor, 0.5),
            '--el-color-primary-light-7' => self::mixWhite($primaryColor, 0.7),
            '--el-color-primary-light-8' => self::mixWhite($primaryColor, 0.8),
            '--el-color-primary-light-9' => self::mixWhite($primaryColor, 0.9),
            '--el-color-primary-dark-2' => self::mixBlack($primaryColor, 0.2),
            // Element Plus 圆角
            '--el-border-radius-base' => $radius.'px',
            '--el-border-radius-small' => $small.'px',
            // TDesign 主色与圆角（控制台 / 管理端）；十级色阶由前端 changeBrandTheme 补齐
            '--td-brand-color' => $primaryColor,
            '--td-radius-small' => $small.'px',
            '--td-radius-default' => $small.'px',
            '--td-radius-medium' => $radius.'px',
            '--td-radius-large' => $large.'px',
        ];

        $declarations = [];
        foreach ($vars as $name => $value) {
            $declarations[] = $name.':'.$value;
        }

        return ':root{'.implode(';', $declarations).'}';
    }

    /**
     * 与白色按权重混合（权重即白色占比），Element Plus 的 light-N 即由此得到。
     *
     * @param  float  $weight  0–1
     */
    private static function mixWhite(string $hex, float $weight): string
    {
        $channels = array_map(
            static fn (int $c): int => (int) round($c + (255 - $c) * $weight),
            self::hexToRgb($hex)
        );

        return self::rgbToHex($channels);
    }

    /**
     * 与黑色按权重混合（权重即黑色占比），Element Plus 的 dark-N 即由此得到。
     *
     * @param  float  $weight  0–1
     */
    private static function mixBlack(string $hex, float $weight): string
    {
        $channels = array_map(
            static fn (int $c): int => (int) round($c * (1 - $weight)),
            self::hexToRgb($hex)
        );

        return self::rgbToHex($channels);
    }

    /**
     * @return array<int, int>
     */
    private static function hexToRgb(string $hex): array
    {
        $hex = ltrim($hex, '#');

        return [
            (int) hexdec(substr($hex, 0, 2)),
            (int) hexdec(substr($hex, 2, 2)),
            (int) hexdec(substr($hex, 4, 2)),
        ];
    }

    /**
     * @param  array<int, int>  $channels
     */
    private static function rgbToHex(array $channels): string
    {
        return '#'.implode('', array_map(
            static fn (int $c): string => str_pad(dechex(max(0, min(255, $c))), 2, '0', STR_PAD_LEFT),
            $channels
        ));
    }
}