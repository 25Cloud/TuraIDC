<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Support\ProductConfigOptionPresenter;
use PHPUnit\Framework\TestCase;

/**
 * 配置项子项（sub）保真回归。
 *
 * 工单现场：上游「智简魔方财务」的商品区域有两个选项
 * 「美国数据中心【原生】」「美国数据中心【非原生】」，同步到本站后用户端只剩
 * 一个按钮「美国数据中心」——后缀与第二个选项一起消失。
 *
 * 根因在前端（parseSubOptions 只有 >=2 个可见 sub 才用 sub，否则整条回退到
 * parameter 拼串），但同一条链路上任何一处把 sub 合并、裁掉后缀或判成非选项型
 * 都会复现同样的现象。这里锚定后端呈现层的行为：sub 一个都不能丢，option_name
 * 一律保留上游原文（含【】这类后缀），value 必须是上游认的真实传参值。
 */
class ProductConfigOptionSubOptionsTest extends TestCase
{
    public function test_single_sub_keeps_upstream_option_name_and_value(): void
    {
        $items = ProductConfigOptionPresenter::present([
            [
                'id' => 7628001,
                'field' => 'area',
                'name' => '区域',
                'option_type' => 20,
                'parameter' => 'us-nat|美国数据中心【原生】',
                'sub' => [
                    ['id' => 5518001, 'option_name' => '美国数据中心【原生】', 'hidden' => 0],
                ],
            ],
        ]);

        $subs = $items[0]['sub'];

        // 单 sub 不能被判成非选项型（option_mode=text 会整项退出选配）
        $this->assertSame('select', $items[0]['option_mode']);
        $this->assertCount(1, $subs);
        $this->assertSame('美国数据中心【原生】', $subs[0]['option_name']);
        // 真实传参值来自父项 parameter 的反查，不是库里的自增 id
        $this->assertSame('us-nat', $subs[0]['value']);
        $this->assertSame('us-nat', $subs[0]['option_name_first']);
    }

    public function test_two_subs_differing_only_by_suffix_are_both_kept(): void
    {
        $items = ProductConfigOptionPresenter::present([
            [
                'id' => 7628002,
                'field' => 'area',
                'name' => '区域',
                'option_type' => 20,
                'parameter' => 'us-nat|美国数据中心【原生】,us-unnat|美国数据中心【非原生】',
                'sub' => [
                    ['id' => 5518011, 'option_name' => '美国数据中心【原生】', 'hidden' => 0],
                    ['id' => 5518012, 'option_name' => '美国数据中心【非原生】', 'hidden' => 0],
                ],
            ],
        ]);

        $subs = $items[0]['sub'];

        $this->assertCount(2, $subs, '两个变体都必须保留，不能只留一个');
        $this->assertSame(
            ['美国数据中心【原生】', '美国数据中心【非原生】'],
            array_column($subs, 'option_name')
        );
        // 两个 label 只差后缀：后缀是唯一区分依据，必须原样留着
        $this->assertSame(['us-nat', 'us-unnat'], array_column($subs, 'value'));
    }

    public function test_sub_without_value_falls_back_to_option_name_first_then_id(): void
    {
        $items = ProductConfigOptionPresenter::present([
            [
                'id' => 7628003,
                'field' => 'area',
                'name' => '区域',
                'option_type' => 20,
                'parameter' => '',
                'sub' => [
                    ['id' => 5518021, 'option_name' => '美国数据中心【原生】', 'option_name_first' => 'nat-a', 'hidden' => 0],
                    ['id' => 5518022, 'option_name' => '美国数据中心【非原生】', 'hidden' => 0],
                ],
            ],
        ]);

        $subs = $items[0]['sub'];

        // parameter 为空时按 option_name_first 兜底
        $this->assertSame('nat-a', $subs[0]['value']);
        // 连 option_name_first 都没有才用自增 id，避免把 id 丢给上游
        $this->assertSame('5518022', $subs[1]['value']);
    }

    public function test_hidden_sub_is_preserved_for_frontend_filtering(): void
    {
        $items = ProductConfigOptionPresenter::present([
            [
                'id' => 7628004,
                'field' => 'area',
                'name' => '区域',
                'option_type' => 20,
                'sub' => [
                    ['id' => 5518031, 'option_name' => '美国数据中心【原生】', 'hidden' => 0],
                    ['id' => 5518032, 'option_name' => '美国数据中心【非原生】', 'hidden' => 1],
                ],
            ],
        ]);

        $subs = $items[0]['sub'];

        // 呈现层不做可见性裁剪：裁掉的 sub 再也回不来，隐藏与否交给前端决定
        $this->assertCount(2, $subs);
        $this->assertSame([0, 1], array_map(static fn (array $sub): int => (int) $sub['hidden'], $subs));
    }

    public function test_single_sub_region_name_is_not_treated_as_text_notice(): void
    {
        // 「美国数据中心【原生】」是 10 个字符、「美国数据中心【非原生】」是 11 个：
        // 都在提示文案判据（>=12 字符整句中文）之外，不能被吞成购买须知。
        $items = ProductConfigOptionPresenter::present([
            [
                'id' => 7628005,
                'field' => 'area',
                'name' => '区域',
                'option_type' => 20,
                'sub' => [
                    ['id' => 5518042, 'option_name' => '美国数据中心【非原生】', 'hidden' => 0],
                ],
            ],
        ]);

        $this->assertSame('select', $items[0]['option_mode']);
        $this->assertSame('', $items[0]['text_content']);
        $this->assertCount(1, $items[0]['sub']);
    }
}
