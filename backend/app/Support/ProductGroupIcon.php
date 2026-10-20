<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\FirstProductGroup;

/**
 * 一级分组图标的统一解析器（管理端、官网、控制台三处共用）。
 *
 * 展示优先级（任一字段为空就继续往下走）：
 *   icon（内置 TDesign 图标名） → icon_text（文字图标，历史数据里也存图标别名） →
 *   设置项兜底图标（仅商品种类列表/控制台使用） → 名称自动缩写。
 *
 * 口径确认：一级分组图标只使用正规内置图标库（TDesign 图标名，存既有 `icon` 列）。
 * - `icon` 列是唯一图标写入来源：管理端只保留内置图标选择器，保存时把 kebab 名归一到本列；
 * - `icon_text` 保留历史兼容：早期系统分组把图标名写在了这列（platform/gamepad/Grid/Node/
 *   Server/Network/Desktop/Monitor 等），解析时先按图标别名归一；不是已知图标名的值仍按
 *   「文字图标」渲染（「轻量」「游戏」）；
 * - `icon_image` 列保留用于兼容（仓库禁止删列），但**不再参与界面渲染，也不再被写入**：
 *   解析时把它当作纯兼容字段原样下发，mode 判定永远不看它；
 * - 不做「无 icon 时回落图片」：那会让历史图片在用户已明确要求「只用内置图标库」后又悄悄
 *   出现，等于没改干净。
 *
 * 字段契约：
 * - `icon`：内置图标名原始值（varchar(50)），可为空；
 * - `icon_text`：原始值；既可能是文字图标，也可能是历史图标别名，渲染请用 icon_resolved；
 * - `icon_image`：仅兼容保留，不参与渲染、不再写入；
 * - `icon_resolved`：`{mode, image, text, icon_name, abbr}`，各端按 mode 渲染：
 *   mode=icon 时用 icon_name（已把 Platform/Gamepad/... 等历史值归一成真实 TDesign 图标名），
 *   mode=text 用 text，mode=abbr 用 abbr；image 仅为兼容字段，恒不参与 mode。
 *
 * 自动缩写的宽度口径与 shared/text/abbr.ts 完全一致：中文/全角字符算 2 单位，
 * 英文/数字/半角符号算 1 单位，累计到 4 就停，不截半个字符。
 */
final class ProductGroupIcon
{
    /**
     * @deprecated 图片图标已下线，本常量不再作为解析结果返回，仅保留避免外部常量引用断裂。
     */
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
     * 历史图标名 / 全项目别名 → 真实 TDesign 图标名（tdesign-icons-vue-next 中确实存在）。
     *
     * 两个来源共用这张表，必须与三端映射表保持一致：
     * - 生产库系统分组 `icon` 列存量值：Platform/Gamepad/Monitor/Desktop/Grid/Node/Server/Network；
     * - 早期回填/人工填在 `icon_text` 列里的历史别名（同样含上面这些 Pascal 值与小写 kebab 名）。
     * 直接喂给 `<t-icon name>` 是渲不出来的（TDesign 图标名是 kebab-case），必须在解析时归一。
     *
     * @var array<string, string>
     */
    private const ICON_ALIASES = [
        'platform' => 'control-platform',
        'control-platform' => 'control-platform',
        // 系统分组历史值 Gamepad（游戏云）统一到平台图标，避免落到管理端选择器之外
        'gamepad' => 'control-platform',
        'gamepad-1' => 'control-platform',
        'dedicated' => 'control-platform',
        'monitor' => 'desktop',
        'desktop' => 'desktop',
        'desktop-1' => 'desktop',
        'grid' => 'component-grid',
        'component-grid' => 'component-grid',
        'node' => 'server',
        'server' => 'server',
        'network' => 'internet',
        'internet' => 'internet',
        'isp' => 'internet',
        'cloud' => 'cloud',
        'vps' => 'server',
        'officebuilding' => 'building',
        'building' => 'building',
        'bare_metal' => 'building',
        'hosting' => 'hard-disk-storage',
        // icon_text 列为 varchar(16)，早期回填 hard-disk-storage（17 字符）会被截断成 16 字符，
        // 这里兼容截断后的存量值；根治需另开迁移加宽列并重刷数据。
        'hard-disk-storag' => 'hard-disk-storage',
        'cdn' => 'link',
        'storage' => 'hard-disk-storage',
        'hard-disk-storage' => 'hard-disk-storage',
        'database' => 'data-base',
        'db' => 'data-base',
        'data-base' => 'data-base',
        'domain' => 'link',
        'link' => 'link',
        'ssl' => 'lock-on',
        'lock-on' => 'lock-on',
        'other' => 'tools',
        'tool' => 'tools',
        'tools' => 'tools',
        'product' => 'shop',
        'invalid_products' => 'shop',
        'shop' => 'shop',
        'router' => 'lock-on',
        'nat' => 'lock-on',
        'router-wave' => 'lock-on',
    ];

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

    /**
     * 把历史别名归一成 `tdesign-icons-vue-next` 中真实存在的 kebab-case 图标名。
     *
     * 未登记的名字原样返回（兼容将来新增的 TDesign 图标），空值返回 null。
     */
    public static function canonicalIconName(mixed $value): ?string
    {
        $icon = self::normalizeIconName($value);

        if ($icon === null) {
            return null;
        }

        return self::ICON_ALIASES[strtolower($icon)] ?? $icon;
    }

    /**
     * 只认「已知内置图标名 / 历史别名」的归一；普通文字（「轻量」「游戏」）返回 null。
     *
     * 用于历史 `icon_text` 列的兼容：早期数据把图标名写在了这列，命中别名表才当图标，
     * 否则仍按文字图标渲染，避免把普通文字误判成图标后渲染成空图标。
     */
    public static function knownIconName(mixed $value): ?string
    {
        $icon = self::normalizeIconName($value);

        if ($icon === null) {
            return null;
        }

        return self::ICON_ALIASES[strtolower($icon)] ?? null;
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
     * 只接受 `icon_text`（历史文字图标兼容）；`icon` 由商品种类设置项同步写入一级分组
     * icon 列（ProductGroupHierarchyService::syncProductTypes），`icon_image` 已下线、
     * 不再写入（列继续保留，见类注释）。
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

        return $payload;
    }

    /**
     * 一级分组图标出参（三端字段名一致）。
     *
     * @param  bool  $resolveImage  管理端传 false 保留库内原始路径；站点/控制台传 true 解析为可访问地址
     * @param  string|null  $fallbackIcon  一级分组 icon 列为空时的兜底图标名（设置项里的图标）
     * @return array{
     *     icon: ?string,
     *     icon_text: ?string,
     *     icon_image: ?string,
     *     icon_resolved: array{mode: string, image: ?string, text: ?string, icon_name: ?string, abbr: string}
     * }
     */
    public static function payload(?FirstProductGroup $group, bool $resolveImage = true, ?string $fallbackIcon = null): array
    {
        $iconColumn = self::normalizeIconName($group?->getAttribute('icon'));
        // 兜底图标只在一级分组自身没有 icon / icon_text 时生效，不能压过显式配置的文字图标
        $fallbackName = self::normalizeIconName($fallbackIcon);
        $rawText = self::normalizeText($group?->getAttribute('icon_text'));
        $iconName = self::canonicalIconName($iconColumn);

        // 历史兼容：早期数据把图标名写进了 icon_text 列（platform / gamepad / Grid / ...），
        // 命中图标别名表就按内置图标渲染；未命中仍按文字图标处理。
        $legacyIconName = $iconName === null ? self::knownIconName($rawText) : null;
        $iconName ??= $legacyIconName;
        $text = $legacyIconName === null ? $rawText : null;

        if ($iconName === null && $text === null) {
            $iconName = self::canonicalIconName($fallbackName);
        }

        $rawImage = self::normalizeImage($group?->getAttribute('icon_image'));
        $resolvedImage = $rawImage === null ? null : (UploadUrl::resolve($rawImage) ?: $rawImage);
        $abbr = self::abbr((string) ($group?->getAttribute('name') ?? ''));

        // 图标优先级：icon（内置图标库）→ icon_text（文字图标 / 历史图标别名）→ 设置项兜底 → 名称缩写。
        // icon_image 仅兼容保留，不参与模式判定（见类注释）。
        $mode = match (true) {
            $iconName !== null => self::MODE_ICON,
            $text !== null => self::MODE_TEXT,
            default => self::MODE_ABBR,
        };

        return [
            // 原始 icon 值（含设置项兜底）保持原样，兼容既有消费者；渲染请用 icon_resolved.icon_name
            'icon' => $iconColumn ?? $fallbackName,
            // 原始 icon_text：既可能是文字图标，也可能是历史图标别名，渲染请用 icon_resolved
            'icon_text' => $rawText,
            // 兼容字段：管理端保留库内原始值，官网/控制台为解析后的可访问地址；不再参与渲染
            'icon_image' => $resolveImage ? $resolvedImage : $rawImage,
            'icon_resolved' => [
                'mode' => $mode,
                // 兼容字段：图片模式已下线，恒不参与 mode 判定
                'image' => $resolvedImage,
                'text' => $text,
                'icon_name' => $iconName,
                'abbr' => $abbr,
            ],
        ];
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
