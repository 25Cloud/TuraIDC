<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\FirstProductGroup;

/**
 * 一级分组图标的统一解析器（管理端、官网、控制台三处共用）。
 *
 * 展示优先级（任一字段为空就继续往下走）：
 *   icon_image（图片图标） → icon_text（文字图标） → icon（既有内置 TDesign 图标名） → 名称自动缩写。
 *
 * 字段契约：
 * - `icon`：既有内置图标名（varchar(50)，系统分组存 Platform/Gamepad/...），可为空；
 * - `icon_text`：文字图标，如「轻量」「游戏」，可为空；
 * - `icon_image`：图片图标。管理端出参保留库内原始值（相对路径/外链，供表单回填），
 *   官网与控制台出参为解析后的可访问绝对地址；
 * - `icon_resolved`：解析结果 `{mode, image, text, icon_name, abbr}`，各端直接按 mode 渲染，
 *   不再各自实现一遍优先级。
 *
 * 自动缩写的宽度口径与 shared/text/abbr.ts 完全一致：中文/全角字符算 2 单位，
 * 英文/数字/半角符号算 1 单位，累计到 4 就停，不截半个字符。
 */
final class ProductGroupIcon
{
    public const MODE_IMAGE = 'image';

    public const MODE_TEXT = 'text';

    public const MODE_ICON = 'icon';

    public const MODE_ABBR = 'abbr';

    /** 自动缩写最多占用的半角宽度单位。 */
    public const ABBR_MAX_UNITS = 4;

    private const TEXT_MAX_LENGTH = 16;

    private const IMAGE_MAX_LENGTH = 255;

    private const ICON_NAME_MAX_LENGTH = 50;

    /**
     * 东亚全角（宽字符）码点区间，与 shared/text/abbr.ts 的 FULL_WIDTH_RANGES 保持一致。
     *
     * @var list<array{0: int, 1: int}>
     */
    private const FULL_WIDTH_RANGES = [
        [0x1100, 0x115f],
        [0x2329, 0x232a],
        [0x2e80, 0x303e],
        [0x3040, 0x33ff],
        [0x3400, 0x4dbf],
        [0x4e00, 0x9fff],
        [0xa000, 0xa4cf],
        [0xa960, 0xa97f],
        [0xac00, 0xd7a3],
        [0xf900, 0xfaff],
        [0xfe10, 0xfe19],
        [0xfe30, 0xfe6f],
        [0xff00, 0xff60],
        [0xffe0, 0xffe6],
        [0x1b000, 0x1b001],
        [0x1f200, 0x1f251],
        [0x1f300, 0x1f64f],
        [0x1f900, 0x1f9ff],
        [0x1fa70, 0x1faff],
    ];

    public static function normalizeIconName(mixed $value): ?string
    {
        $icon = trim((string) $value);

        return $icon === '' ? null : mb_substr($icon, 0, self::ICON_NAME_MAX_LENGTH);
    }

    public static function normalizeText(mixed $value): ?string
    {
        $text = trim((string) $value);

        return $text === '' ? null : mb_substr($text, 0, self::TEXT_MAX_LENGTH);
    }

    public static function normalizeImage(mixed $value): ?string
    {
        $image = trim((string) $value);

        return $image === '' ? null : mb_substr($image, 0, self::IMAGE_MAX_LENGTH);
    }

    /**
     * 只返回请求里显式提交过的图标字段，避免「未传」被当成「清空」。
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public static function fromInput(array $data): array
    {
        $payload = [];

        if (array_key_exists('icon_text', $data)) {
            $payload['icon_text'] = self::normalizeText($data['icon_text']);
        }

        if (array_key_exists('icon_image', $data)) {
            $payload['icon_image'] = self::normalizeImage($data['icon_image']);
        }

        return $payload;
    }

    /**
     * 一级分组图标出参（三端字段名一致）。
     *
     * @return array{
     *     icon: ?string,
     *     icon_text: ?string,
     *     icon_image: ?string,
     *     icon_resolved: array{mode: string, image: ?string, text: ?string, icon_name: ?string, abbr: string}
     * }
     */
    public static function payload(?FirstProductGroup $group, bool $resolveImage = true, ?string $fallbackIcon = null): array
    {
        $iconName = self::normalizeIconName($group?->getAttribute('icon')) ?? self::normalizeIconName($fallbackIcon);
        $text = self::normalizeText($group?->getAttribute('icon_text'));
        $rawImage = self::normalizeImage($group?->getAttribute('icon_image'));
        $resolvedImage = $rawImage === null ? null : (UploadUrl::resolve($rawImage) ?: $rawImage);
        $abbr = self::abbr((string) ($group?->getAttribute('name') ?? ''));

        $mode = match (true) {
            $resolvedImage !== null => self::MODE_IMAGE,
            $text !== null => self::MODE_TEXT,
            $iconName !== null => self::MODE_ICON,
            default => self::MODE_ABBR,
        };

        return [
            'icon' => $iconName,
            'icon_text' => $text,
            // 管理端要回填原始路径；官网/控制台直接拿可访问地址
            'icon_image' => $resolveImage ? $resolvedImage : $rawImage,
            'icon_resolved' => [
                'mode' => $mode,
                'image' => $resolvedImage,
                'text' => $text,
                'icon_name' => $iconName,
                'abbr' => $abbr,
            ],
        ];
    }

    /**
     * 兼容旧调用：只取解析后的图片地址（绝对地址）。
     */
    public static function resolveImage(?FirstProductGroup $group): ?string
    {
        $image = self::normalizeImage($group?->getAttribute('icon_image'));

        return $image === null ? null : (UploadUrl::resolve($image) ?: null);
    }

    /**
     * 一级分组名自动缩写：按半角宽度取前 4 个单位。
     */
    public static function abbr(string $name, int $maxUnits = self::ABBR_MAX_UNITS): string
    {
        $source = trim($name);
        $limit = max(0, $maxUnits);

        if ($source === '' || $limit === 0) {
            return '';
        }

        $units = 0;
        $result = '';

        foreach (mb_str_split($source) as $char) {
            $width = self::charHalfWidth($char);
            if ($units + $width > $limit) {
                break;
            }

            $units += $width;
            $result .= $char;
        }

        return $result;
    }

    private static function charHalfWidth(string $char): int
    {
        $codePoint = (int) mb_ord($char, 'UTF-8');

        if ($codePoint <= 0) {
            return 1;
        }

        foreach (self::FULL_WIDTH_RANGES as [$start, $end]) {
            if ($codePoint >= $start && $codePoint <= $end) {
                return 2;
            }
        }

        return 1;
    }
}
