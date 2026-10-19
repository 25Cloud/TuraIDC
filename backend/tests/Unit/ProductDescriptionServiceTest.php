<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Models\Product;
use App\Services\ProductCatalog\ProductDescriptionService;
use Tests\TestCase;

/**
 * 商品描述回归。
 *
 * 描述是前台卡片式展示的唯一内容来源，两个口径必须锁死：
 *
 *   1. 人工填写的描述优先，且落库即消毒——前台直接 v-html 渲染这一列，
 *      存什么就该是什么，危险标签不许留在库里。
 *   2. 描述留空时按规格维度自动生成 <ul><li>键: 值</li></ul>，识别逻辑
 *      复用 ProductSpecHighlightService，不另起一套 CPU/内存判据。
 *
 * 第二条尤其关键：虚拟主机与 CDN 的全部配置项 field 都是 cpu，若描述另写
 * 一套按 field 判 CPU 的逻辑，「12T WEB空间」会被算成内存 12T。
 */
class ProductDescriptionServiceTest extends TestCase
{
    public function test_it_prefers_manual_description_and_reports_its_source(): void
    {
        $service = new ProductDescriptionService();

        $resolved = $service->resolve(new Product([
            'description' => '<ul><li>CPU: 8核</li><li>带宽: 100M</li></ul>',
            'remark' => '<li>备注里的旧内容</li>',
        ]));

        $this->assertSame(ProductDescriptionService::SOURCE_MANUAL, $resolved['source']);
        $this->assertSame('<ul><li>CPU: 8核</li><li>带宽: 100M</li></ul>', $resolved['html']);
    }

    public function test_it_strips_dangerous_markup_from_manual_description(): void
    {
        $service = new ProductDescriptionService();

        $resolved = $service->resolve(new Product([
            'description' => '<p onclick="steal()">正文</p><script>alert(1)</script><img src=x onerror=alert(1)>',
        ]));

        $this->assertStringNotContainsString('script', $resolved['html']);
        $this->assertStringNotContainsString('onclick', $resolved['html']);
        $this->assertStringNotContainsString('onerror', $resolved['html']);
        // 内容本身保留，只剥标签与危险属性
        $this->assertStringContainsString('正文', $resolved['html']);
    }

    public function test_it_auto_generates_a_list_from_spec_dimensions_when_description_is_empty(): void
    {
        $service = new ProductDescriptionService();

        $resolved = $service->resolve($this->panelProduct());

        $this->assertSame(ProductDescriptionService::SOURCE_AUTO, $resolved['source']);
        $this->assertSame(
            '<ul><li>CPU: 2核</li><li>内存: 4GB</li></ul>',
            $resolved['html']
        );
    }

    /**
     * 「系统」是下单时才选的操作系统配置项，不是商品本身的规格；
     * 自动拼描述时剔除，避免卡片/详情出现「系统: CentOS」这类噪音。
     */
    public function test_auto_description_omits_system_dimension(): void
    {
        $service = new ProductDescriptionService();

        $resolved = $service->resolve(new Product([
            'config_options' => [
                ['id' => 1, 'field' => 'cpu', 'name' => 'CPU', 'sub' => [['option_name' => '4核']]],
                ['id' => 2, 'field' => 'os', 'name' => '操作系统', 'sub' => [['version' => 'CentOS^CentOS-7.9-x64']]],
            ],
        ]));

        $this->assertSame(ProductDescriptionService::SOURCE_AUTO, $resolved['source']);
        $this->assertSame('<ul><li>CPU: 4核</li></ul>', $resolved['html']);
        $this->assertStringNotContainsString('系统', $resolved['html']);
        $this->assertStringNotContainsString('CentOS', $resolved['html']);
    }

    /**
     * 虚拟主机 / CDN 的配置项 field 全部是 cpu（上游允许复用），
     * 识别必须靠名称而不是 field，否则「WEB空间 12T」会被算成内存 12T。
     */
    public function test_it_matches_dimensions_by_name_when_upstream_reuses_field(): void
    {
        $service = new ProductDescriptionService();

        $resolved = $service->resolve(new Product([
            'product_type' => 'hosting',
            'config_options' => [
                ['id' => 1, 'field' => 'cpu', 'name' => '绑定域名数', 'sub' => [['option_name' => '10个']]],
                ['id' => 2, 'field' => 'cpu', 'name' => '内存', 'sub' => [['option_name' => '4GB']]],
                ['id' => 3, 'field' => 'cpu', 'name' => 'WEB空间', 'sub' => [['option_name' => '12T']]],
            ],
        ]));

        $this->assertSame(ProductDescriptionService::SOURCE_AUTO, $resolved['source']);
        $this->assertSame(
            // 顺序跟 ProductSpecHighlightService::DIMENSIONS 的定义序走，不按配置项顺序
            '<ul><li>内存: 4GB</li><li>绑定域名: 10个</li></ul>',
            $resolved['html']
        );
        // WEB 空间没有任何维度语义，宁可不展示，也不能被 field=cpu 拖成内存
        $this->assertStringNotContainsString('12T', $resolved['html']);
    }

    public function test_it_escapes_upstream_sourced_text_in_auto_html(): void
    {
        $service = new ProductDescriptionService();

        $resolved = $service->resolve(new Product([
            'config_options' => [
                ['id' => 1, 'field' => 'cpu', 'name' => '处理器', 'sub' => [['option_name' => '<img src=x onerror=alert(1)>']]],
            ],
        ]));

        $this->assertStringNotContainsString('<img', $resolved['html']);
        $this->assertStringContainsString('&lt;img', $resolved['html']);
    }

    public function test_it_reports_empty_rather_than_rendering_an_empty_shell(): void
    {
        $service = new ProductDescriptionService();

        $resolved = $service->resolve(new Product(['description' => '   ']));

        $this->assertSame(ProductDescriptionService::SOURCE_EMPTY, $resolved['source']);
        $this->assertSame('', $resolved['html']);
    }

    public function test_it_falls_back_to_escaped_upstream_description_in_remark(): void
    {
        $service = new ProductDescriptionService();

        $resolved = $service->resolve(new Product([
            // 魔方 description 历史导入后的形态：整段 HTML 被转义
            'remark' => '&lt;li&gt;配置：4核 4G&lt;/li&gt;&lt;li&gt;硬盘：30G SSD&lt;/li&gt;',
        ]));

        $this->assertSame(ProductDescriptionService::SOURCE_REMARK, $resolved['source']);
        // 解码实体 + 补列表容器，输出与上游商品描述一致
        $this->assertSame(
            '<ul><li>配置：4核 4G</li><li>硬盘：30G SSD</li></ul>',
            $resolved['html']
        );
    }

    public function test_remark_fallback_is_sanitized(): void
    {
        $service = new ProductDescriptionService();

        $resolved = $service->resolve(new Product([
            'remark' => '&lt;li&gt;正文&lt;script&gt;alert(1)&lt;/script&gt;&lt;/li&gt;',
        ]));

        $this->assertSame(ProductDescriptionService::SOURCE_REMARK, $resolved['source']);
        $this->assertStringNotContainsString('script', $resolved['html']);
        $this->assertStringContainsString('正文', $resolved['html']);
    }

    public function test_auto_description_reads_select_sub_values_despite_zero_qty_minimum(): void
    {
        $service = new ProductDescriptionService();

        // 导入数据给每个配置项都补了 qty_minimum（下拉项为 0）；不能因此把值吞掉
        $resolved = $service->resolve(new Product([
            'config_options' => [
                [
                    'id' => 1,
                    'field' => 'cpu',
                    'name' => 'CPU',
                    'qty_minimum' => 0,
                    'sub' => [['option_name' => '4核', 'option_name_first' => '4']],
                ],
                [
                    'id' => 2,
                    'field' => 'memory',
                    'name' => '内存',
                    'qty_minimum' => 0,
                    'sub' => [['version' => '4GB', 'option_name_first' => '4096']],
                ],
            ],
        ]));

        $this->assertSame(ProductDescriptionService::SOURCE_AUTO, $resolved['source']);
        $this->assertStringContainsString('CPU: 4核', $resolved['html']);
        $this->assertStringContainsString('内存: 4GB', $resolved['html']);
    }

    public function test_it_skips_rows_with_a_missing_label_or_value(): void
    {
        $service = new ProductDescriptionService();

        $this->assertSame('', $service->renderList([
            ['key' => 'cpu', 'label' => '', 'value' => '2核'],
            ['key' => 'memory', 'label' => '内存', 'value' => ''],
        ]));
    }

    public function test_display_mode_falls_back_to_list_for_anything_unrecognised(): void
    {
        $this->assertSame(ProductDescriptionService::DISPLAY_MODE_CARD, ProductDescriptionService::normalizeDisplayMode('card'));
        $this->assertSame(ProductDescriptionService::DISPLAY_MODE_CARD, ProductDescriptionService::normalizeDisplayMode(' CARD '));
        $this->assertSame(ProductDescriptionService::DISPLAY_MODE_LIST, ProductDescriptionService::normalizeDisplayMode('list'));
        $this->assertSame(ProductDescriptionService::DISPLAY_MODE_LIST, ProductDescriptionService::normalizeDisplayMode(''));
        $this->assertSame(ProductDescriptionService::DISPLAY_MODE_LIST, ProductDescriptionService::normalizeDisplayMode(null));
        $this->assertSame(ProductDescriptionService::DISPLAY_MODE_LIST, ProductDescriptionService::normalizeDisplayMode('grid'));
    }

    /**
     * 面板型商品没有 CPU/内存语义，商品名里的数字是空间与带宽。
     */
    private function panelProduct(): Product
    {
        return new Product([
            'product_type' => 'hosting',
            'config_options' => [
                ['id' => 1, 'field' => 'cpu', 'name' => '处理器', 'sub' => [['option_name' => '2核']]],
                ['id' => 2, 'field' => 'cpu', 'name' => '内存', 'sub' => [['option_name' => '4GB']]],
            ],
        ]);
    }
}