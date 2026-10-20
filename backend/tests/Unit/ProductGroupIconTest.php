<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Models\FirstProductGroup;
use App\Support\ProductGroupIcon;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * 一级分组图标解析器：优先级、历史别名归一与 icon_image 下线口径。
 */
class ProductGroupIconTest extends TestCase
{
    /**
     * @param  array<string, mixed>  $attributes
     */
    private function group(array $attributes): FirstProductGroup
    {
        return new FirstProductGroup([
            'code' => 'test-group',
            'name' => '轻量云',
            ...$attributes,
        ]);
    }

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function historicalIconAliasProvider(): array
    {
        return [
            'Platform' => ['Platform', 'control-platform'],
            'platform' => ['platform', 'control-platform'],
            'Gamepad' => ['Gamepad', 'control-platform'],
            'gamepad' => ['gamepad', 'control-platform'],
            'Monitor' => ['Monitor', 'desktop'],
            'Desktop' => ['Desktop', 'desktop'],
            'Grid' => ['Grid', 'component-grid'],
            'Node' => ['Node', 'server'],
            'Server' => ['Server', 'server'],
            'Network' => ['Network', 'internet'],
            'OfficeBuilding' => ['OfficeBuilding', 'building'],
            'Vps' => ['Vps', 'server'],
            'kebab server' => ['server', 'server'],
            'kebab lock-on' => ['lock-on', 'lock-on'],
        ];
    }

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function iconTextAliasProvider(): array
    {
        return [
            'platform' => ['platform', 'control-platform'],
            'gamepad' => ['gamepad', 'control-platform'],
            'Grid' => ['Grid', 'component-grid'],
            'Node' => ['Node', 'server'],
            'Server' => ['Server', 'server'],
            'Network' => ['Network', 'internet'],
            'Desktop' => ['Desktop', 'desktop'],
            'Monitor' => ['Monitor', 'desktop'],
            'kebab cloud' => ['cloud', 'cloud'],
        ];
    }

    #[DataProvider('historicalIconAliasProvider')]
    public function test_icon_column_historical_values_are_canonicalized(string $raw, string $canonical): void
    {
        $payload = ProductGroupIcon::payload($this->group(['icon' => $raw]), false);

        $this->assertSame('icon', $payload['icon_resolved']['mode']);
        $this->assertSame($canonical, $payload['icon_resolved']['icon_name']);
    }

    #[DataProvider('iconTextAliasProvider')]
    public function test_icon_text_historical_alias_is_resolved_as_icon(string $raw, string $canonical): void
    {
        $payload = ProductGroupIcon::payload($this->group(['icon_text' => $raw]), false);

        $this->assertSame('icon', $payload['icon_resolved']['mode']);
        $this->assertSame($canonical, $payload['icon_resolved']['icon_name']);
        // 被当作图标的 icon_text 不再作为文字图标下发，避免前端渲染成「server」文字
        $this->assertNull($payload['icon_resolved']['text']);
        $this->assertSame($raw, $payload['icon_text']);
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function productionBackfillIconProvider(): array
    {
        return [
            'server' => ['server'],
            'cloud' => ['cloud'],
            'internet' => ['internet'],
            'control-platform' => ['control-platform'],
            'component-grid' => ['component-grid'],
            'desktop' => ['desktop'],
            'hard-disk-storage' => ['hard-disk-storage'],
            'link' => ['link'],
            'lock-on' => ['lock-on'],
            'building' => ['building'],
            'tools' => ['tools'],
            'shop' => ['shop'],
        ];
    }

    #[DataProvider('productionBackfillIconProvider')]
    public function test_production_backfilled_icon_text_values_resolve_to_icons(string $iconText): void
    {
        $payload = ProductGroupIcon::payload($this->group(['icon_text' => $iconText]), false);

        $this->assertSame('icon', $payload['icon_resolved']['mode']);
        $this->assertSame($iconText, $payload['icon_resolved']['icon_name']);
    }

    public function test_icon_column_has_priority_over_icon_text_and_icon_image(): void
    {
        $payload = ProductGroupIcon::payload(
            $this->group([
                'icon' => 'Cloud',
                'icon_text' => '轻量',
                'icon_image' => '/media/group-icon.png',
            ]),
            false
        );

        $this->assertSame('icon', $payload['icon_resolved']['mode']);
        $this->assertSame('cloud', $payload['icon_resolved']['icon_name']);
        // 同时配置了文字图标时原始文字仍保留在契约里，但 mode=icon 决定按内置图标渲染
        $this->assertSame('轻量', $payload['icon_resolved']['text']);
        $this->assertSame('轻量', $payload['icon_text']);
        // 图片字段仅为兼容保留，不参与 mode 判定
        $this->assertSame('/media/group-icon.png', $payload['icon_image']);
    }

    public function test_plain_icon_text_still_renders_as_text_icon(): void
    {
        $payload = ProductGroupIcon::payload($this->group(['icon_text' => ' 轻量 ']), false);

        $this->assertSame('text', $payload['icon_resolved']['mode']);
        $this->assertSame('轻量', $payload['icon_resolved']['text']);
        $this->assertNull($payload['icon_resolved']['icon_name']);
    }

    public function test_icon_image_alone_never_drives_rendering(): void
    {
        $payload = ProductGroupIcon::payload(
            $this->group(['icon_image' => '/media/legacy-group-icon.png']),
            false
        );

        // 图片图标已下线：即使 icon_image 有值，也回退到名称缩写
        $this->assertSame('abbr', $payload['icon_resolved']['mode']);
        $this->assertSame('轻量', $payload['icon_resolved']['abbr']);
        $this->assertNull($payload['icon_resolved']['icon_name']);
        $this->assertNull($payload['icon_resolved']['text']);
        // 兼容字段仍原样下发，各端不得据此渲染
        $this->assertSame('/media/legacy-group-icon.png', $payload['icon_image']);
    }

    public function test_empty_icon_fields_fall_back_to_abbr_with_half_width_limit(): void
    {
        $payload = ProductGroupIcon::payload($this->group(['name' => 'GameCloud']), false);

        $this->assertSame('abbr', $payload['icon_resolved']['mode']);
        $this->assertSame('Game', $payload['icon_resolved']['abbr']);

        $mixed = ProductGroupIcon::payload($this->group(['name' => '轻量Game']), false);
        $this->assertSame('轻量', $mixed['icon_resolved']['abbr']);
    }

    public function test_explicit_icon_text_wins_over_setting_fallback_icon(): void
    {
        $payload = ProductGroupIcon::payload($this->group(['icon_text' => '轻量']), false, 'Platform');

        $this->assertSame('text', $payload['icon_resolved']['mode']);
        $this->assertSame('轻量', $payload['icon_resolved']['text']);
        $this->assertNull($payload['icon_resolved']['icon_name']);
    }

    public function test_setting_fallback_icon_is_used_when_group_icon_is_empty(): void
    {
        $payload = ProductGroupIcon::payload($this->group([]), false, 'Platform');

        $this->assertSame('icon', $payload['icon_resolved']['mode']);
        $this->assertSame('control-platform', $payload['icon_resolved']['icon_name']);
        // 原始值保持原样，渲染必须用 icon_resolved.icon_name
        $this->assertSame('Platform', $payload['icon']);
    }

    public function test_null_group_only_uses_fallback_icon_or_abbr(): void
    {
        $icon = ProductGroupIcon::payload(null, false, 'Grid');
        $this->assertSame('icon', $icon['icon_resolved']['mode']);
        $this->assertSame('component-grid', $icon['icon_resolved']['icon_name']);
        $this->assertSame('', $icon['icon_resolved']['abbr']);

        $abbr = ProductGroupIcon::payload(null, false);
        $this->assertSame('abbr', $abbr['icon_resolved']['mode']);
        $this->assertNull($abbr['icon_resolved']['icon_name']);
    }

    public function test_unknown_icon_name_passes_through_for_future_icons(): void
    {
        $payload = ProductGroupIcon::payload($this->group(['icon' => 'brand-new-icon']), false);

        $this->assertSame('icon', $payload['icon_resolved']['mode']);
        $this->assertSame('brand-new-icon', $payload['icon_resolved']['icon_name']);
    }

    public function test_payload_keeps_three_end_contract_keys(): void
    {
        $payload = ProductGroupIcon::payload($this->group(['icon' => 'link']), true);

        $this->assertSame(['icon', 'icon_text', 'icon_image', 'icon_resolved'], array_keys($payload));
        $this->assertSame(
            ['mode', 'image', 'text', 'icon_name', 'abbr'],
            array_keys($payload['icon_resolved'])
        );
    }

    public function test_from_input_only_writes_explicit_icon_text_and_ignores_icon_image(): void
    {
        $this->assertSame([], ProductGroupIcon::fromInput([]));
        $this->assertSame(
            ['icon_text' => '轻量'],
            ProductGroupIcon::fromInput(['icon_text' => ' 轻量 ', 'icon_image' => '/media/old.png'])
        );
        $this->assertSame(
            ['icon_text' => null],
            ProductGroupIcon::fromInput(['icon_text' => '   '])
        );
    }

    public function test_canonical_and_known_icon_name_helpers(): void
    {
        $this->assertSame('control-platform', ProductGroupIcon::canonicalIconName('Platform'));
        $this->assertSame('unknown-icon', ProductGroupIcon::canonicalIconName('unknown-icon'));
        $this->assertNull(ProductGroupIcon::canonicalIconName('  '));

        // 普通文字不是图标别名，knownIconName 返回 null；canonicalIconName 原样返回
        $this->assertNull(ProductGroupIcon::knownIconName('轻量'));
        $this->assertSame('轻量', ProductGroupIcon::canonicalIconName('轻量'));
        $this->assertSame('server', ProductGroupIcon::knownIconName('Node'));
    }
}
