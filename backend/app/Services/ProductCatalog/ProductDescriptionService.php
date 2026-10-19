<?php

declare(strict_types=1);

namespace App\Services\ProductCatalog;

use App\Models\Product;
use App\Models\Setting;
use App\Support\RichHtmlSanitizer;

/**
 * 商品描述服务。
 *
 * 描述只有一个真源：products.description（HTML）。读取时按三段口径产出：
 *
 *   1. 人工填写（manual）：直接清洗后返回。清洗走 RichHtmlSanitizer 的标签 +
 *      属性 + URL 协议三层白名单，与前台 v-html 是配套的——存什么就渲染什么，
 *      不依赖前端再兜一层。
 *   2. 自动生成（auto）：描述留空时，用 ProductSpecHighlightService 的规格维度
 *      识别结果拼 <ul><li>键: 值</li></ul>。识别逻辑只此一份（与列表页的
 *      spec_highlights 同源），这里不新增第二套 CPU/内存判据。
 *   3. 空（empty）：识别不出任何维度时不硬造骨架，前台据此不渲染描述块。
 *
 * 自动生成只读不写库：识别规则改进后历史商品立即跟着变，且不必跑回填命令。
 * 代价是描述不是可导出数据——商家要固化某次结果，在后台存一次即转 manual。
 *
 * 与 remark 的关系：remark 是内部备注、也是回传魔方的 description 字段
 * （见 ZjmfUpstream\ProductService）。历史导入把上游商品描述（转义过的卡片 HTML）
 * 存在 remark 里；products.description 为空时，本服务会解码并消毒这段内容，
 * 让前台展示与上游商品页一致。新导入的数据会直接把上游描述写进 description。
 */
final class ProductDescriptionService
{
    /** 商家在后台自行填写 */
    public const SOURCE_MANUAL = 'manual';

    /** 描述留空、按规格维度自动生成 */
    public const SOURCE_AUTO = 'auto';

    /** 描述留空、回退到 remark 里保存的上游商品描述（魔方导入的卡片 HTML） */
    public const SOURCE_REMARK = 'remark';

    /** 描述留空且识别不出任何维度 */
    public const SOURCE_EMPTY = 'empty';

    /** 全站展示方式取值：商品表格（列表式） */
    public const DISPLAY_MODE_LIST = 'list';

    /** 全站展示方式取值：商品卡片（卡片式） */
    public const DISPLAY_MODE_CARD = 'card';

    /** 站点设置分组与键：与 brand_radius 等基础设置同组 */
    public const SETTING_GROUP_BASIC = 'basic';

    public const SETTING_KEY_DISPLAY_MODE = 'product_display_mode';

    public const DEFAULT_DISPLAY_MODE = self::DISPLAY_MODE_LIST;

    /**
     * 解析某商品的前台描述。
     *
     * $autoItems 允许调用方把已经算出的规格维度传进来：资源类本来就要
     * resolveHighlightsForProduct() 填 spec_highlights，再让它为描述重跑一遍
     * 等于每次请求把「商品名正则 + 全部配置项匹配」做两次。
     *
     * @param  array<int, array{key: string, label: string, value: string}>|null  $autoItems
     * @return array{html: string, source: string}
     */
    public function resolve(Product $product, ?array $autoItems = null): array
    {
        $manual = $this->sanitize((string) ($product->description ?? ''));

        if ($manual !== '') {
            return ['html' => $manual, 'source' => self::SOURCE_MANUAL];
        }

        // 历史导入把上游商品描述（魔方卡片 HTML）存进了 remark。上游它就是商品详情里的
        // 描述文案，前台直接展示这段内容才和上游一致；只有 remark 也为空时才退到
        // 规格维度自动生成。
        $upstream = $this->remarkHtml($product);
        if ($upstream !== '') {
            return ['html' => $upstream, 'source' => self::SOURCE_REMARK];
        }

        $auto = $this->renderList($autoItems ?? $this->autoItems($product));

        return $auto === ''
            ? ['html' => '', 'source' => self::SOURCE_EMPTY]
            : ['html' => $auto, 'source' => self::SOURCE_AUTO];
    }

    /**
     * remark 中保存的上游描述 HTML。
     *
     * 魔方接口返回的描述本身是转义过的 HTML（&lt;li&gt;…），历史导入按原样落库；
     * 这里解码实体、给裸 &lt;li&gt; 片段补上列表容器，再过白名单消毒。
     */
    private function remarkHtml(Product $product): string
    {
        $remark = trim((string) ($product->remark ?? ''));

        if ($remark === '') {
            return '';
        }

        // remark 是 varchar(255)，截断处可能留下半个多字节字符 / 半个标签，
        // 先丢弃非法字节与残缺标签，避免前台拿到非法 UTF-8 或破标签。
        $remark = $this->scrubUtf8($remark);
        $decoded = html_entity_decode($remark, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $decoded = $this->scrubUtf8($decoded);
        $decoded = preg_replace('/<br\s*\/?>/iu', '<br>', $decoded) ?? $decoded;
        $decoded = preg_replace('/<[^>]*$/u', '', $decoded) ?? $decoded;

        if (preg_match('/<li[\s>]/iu', $decoded) === 1 && preg_match('/<ul[\s>]/iu', $decoded) !== 1) {
            $decoded = '<ul>'.$decoded.'</ul>';
        }

        return $this->sanitize($decoded);
    }

    /** 丢弃非法 UTF-8 字节（iconv 不可用时退化为替换字符） */
    private function scrubUtf8(string $value): string
    {
        if (mb_check_encoding($value, 'UTF-8')) {
            return $value;
        }

        $scrubbed = function_exists('iconv')
            ? iconv('UTF-8', 'UTF-8//IGNORE', $value)
            : mb_scrub($value, 'UTF-8');

        return $scrubbed !== false ? $scrubbed : mb_scrub($value, 'UTF-8');
    }

    /**
     * 仅描述 HTML，供不需要区分来源的调用方使用。
     */
    public function html(Product $product): string
    {
        return $this->resolve($product)['html'];
    }

    /**
     * 自动生成将用到的键值对。
     *
     * 直接复用 ProductSpecHighlightService——列表页展示的 spec_highlights 与
     * 这里的描述来自同一次识别，两处不会出现「表格有、卡片没有」的分裂。
     *
     * @return array<int, array{key: string, label: string, value: string}>
     */
    public function autoItems(Product $product): array
    {
        return app(ProductSpecHighlightService::class)->resolveHighlightsForProduct($product);
    }

    /**
     * 清洗商家填写的描述。
     */
    public function sanitize(?string $html): string
    {
        $trimmed = trim((string) $html);

        return $trimmed === '' ? '' : RichHtmlSanitizer::sanitize($trimmed);
    }

    /**
     * 键值对数组渲染成描述 HTML。
     *
     * @param  array<int, array{key?: string, label?: string, value?: string}>  $items
     */
    public function renderList(array $items): string
    {
        $rows = [];
        foreach ($items as $item) {
            $label = trim((string) ($item['label'] ?? $item['key'] ?? ''));
            $value = trim((string) ($item['value'] ?? ''));

            if ($label === '' || $value === '') {
                continue;
            }

            $rows[] = '<li>'.$this->escape($label).': '.$this->escape($value).'</li>';
        }

        if ($rows === []) {
            return '';
        }

        return '<ul>'.implode('', $rows).'</ul>';
    }

    /**
     * 全站展示方式归一化：非法值一律回退列表式。
     *
     * 列表式是既有行为，回退方向必须是它——设置被清空或写脏时站点仍可售。
     */
    public static function normalizeDisplayMode(mixed $value): string
    {
        $normalized = strtolower(trim((string) $value));

        return $normalized === self::DISPLAY_MODE_CARD
            ? self::DISPLAY_MODE_CARD
            : self::DISPLAY_MODE_LIST;
    }

    /**
     * 读取全站展示方式。
     */
    public static function currentDisplayMode(): string
    {
        return self::normalizeDisplayMode(
            Setting::getValue(
                self::SETTING_GROUP_BASIC,
                self::SETTING_KEY_DISPLAY_MODE,
                self::DEFAULT_DISPLAY_MODE
            )
        );
    }

    /**
     * 键值对由配置项文案拼出而非商家书写，仍做一次转义：
     * 配置项文案来自上游模板，属于外部输入。
     */
    private function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}