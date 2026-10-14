<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Support\ProductConfigOptionPresenter;
use PHPUnit\Framework\TestCase;

/**
 * 配置项表单键（form_key）回归。
 *
 * 上游智简魔方把虚拟主机与 CDN 的全部配置项（绑定域名数、WEB空间、带宽、
 * 流量、连接数…）的 field 都给成 cpu。本系统官网表单、订单计价、上游提交
 * 历来直接拿 field 当键，于是这些项互相覆盖：官网只渲染第一个、其余项消失，
 * 订单只记一个值，计价取到的是最后一项的价格。
 *
 * form_key 是商品内唯一的展示／表单键，提交给上游时仍映射回 field。
 */
class ProductConfigOptionFormKeyTest extends TestCase
{
    public function test_it_keeps_field_when_it_is_unique_within_the_product(): void
    {
        $keys = $this->formKeys([
            ['id' => 1, 'field' => 'cpu', 'name' => '处理器核心'],
            ['id' => 2, 'field' => 'memory', 'name' => '内存'],
        ]);

        // 云主机这类 field 本身唯一的商品行为不变
        $this->assertSame(['cpu', 'memory'], array_column($keys, 'form_key'));
    }

    public function test_it_disambiguates_reused_field_with_upstream_config_id(): void
    {
        $keys = $this->formKeys([
            ['id' => 11, 'config_id' => 7631478, 'field' => 'cpu', 'name' => '绑定域名数'],
            ['id' => 12, 'config_id' => 7631479, 'field' => 'cpu', 'name' => '流量限制'],
            ['id' => 13, 'config_id' => 7631480, 'field' => 'cpu', 'name' => 'WEB空间'],
        ]);

        $this->assertSame(
            ['cpu#7631478', 'cpu#7631479', 'cpu#7631480'],
            array_column($keys, 'form_key')
        );
        // field 原值必须保留：提交上游、计价匹配旧快照都还靠它
        $this->assertSame(['cpu', 'cpu', 'cpu'], array_column($keys, 'field'));
    }

    public function test_it_falls_back_to_local_id_when_upstream_config_id_is_missing(): void
    {
        $keys = $this->formKeys([
            ['id' => 21, 'field' => 'cpu', 'name' => '带宽'],
            ['id' => 22, 'field' => 'cpu', 'name' => '流量'],
        ]);

        $this->assertSame(['cpu#21', 'cpu#22'], array_column($keys, 'form_key'));
    }

    public function test_it_uses_index_when_neither_config_id_nor_id_exists(): void
    {
        $keys = $this->formKeys([
            ['field' => 'cpu', 'name' => '带宽'],
            ['field' => 'cpu', 'name' => '流量'],
        ]);

        $this->assertSame(['cpu#1', 'cpu#2'], array_column($keys, 'form_key'));
    }

    public function test_it_does_not_collide_with_another_option_literal_field(): void
    {
        $keys = $this->formKeys([
            ['id' => 31, 'field' => 'cpu', 'name' => '带宽'],
            ['id' => 32, 'field' => 'cpu', 'name' => '流量'],
            // 另一项的字面 field 恰好等于上一项补位出来的 form_key
            ['id' => 33, 'field' => 'cpu#32', 'name' => '连接数'],
        ]);

        $formKeys = array_column($keys, 'form_key');
        $this->assertSame($formKeys, array_unique($formKeys), 'form_key 必须商品内唯一');
        $this->assertSame('cpu#32-2', $formKeys[2]);
    }

    public function test_it_gives_empty_form_key_to_options_without_field(): void
    {
        $keys = $this->formKeys([
            ['id' => 41, 'field' => '', 'name' => '未知项'],
        ]);

        $this->assertSame('', $keys[0]['form_key']);
    }

    /**
     * 提交上游的键形态，对齐魔方 zjmf376 app/common/logic/Host.php:104-109：
     * 键是配置项 id，单选传子项 id，区间传数量。
     */
    public function test_it_builds_upstream_submission_by_option_id(): void
    {
        $configOptions = [
            [
                'id' => 2336431, 'field' => 'cpu', 'name' => 'WEB空间',
                'option_type' => 6, 'option_mode' => 'select',
                'sub' => [['id' => 10007138, 'option_name' => '500M', 'value' => '500M']],
            ],
            [
                'id' => 2336430, 'field' => 'cpu', 'name' => '数据库空间',
                'option_type' => 6, 'option_mode' => 'select',
                'sub' => [['id' => 10007137, 'option_name' => '100M', 'value' => '100M']],
            ],
        ];

        // 官网按 form_key 提交，两项 field 都是 cpu 也不会互相覆盖
        $this->assertSame(
            [2336431 => 10007138, 2336430 => 10007137],
            ProductConfigOptionPresenter::submitConfigByOptionId($configOptions, [
                'cpu#2336431' => '500M',
                'cpu#2336430' => '100M',
            ])
        );
    }

    public function test_it_submits_range_options_as_quantity(): void
    {
        $configOptions = [[
            'id' => 501, 'field' => 'data_disk_size', 'name' => '数据盘',
            'option_type' => 7, 'option_mode' => 'range',
            'qty_minimum' => 10, 'qty_maximum' => 500,
            'sub' => [],
        ]];

        $this->assertSame(
            [501 => 120],
            ProductConfigOptionPresenter::submitConfigByOptionId($configOptions, ['data_disk_size' => 120])
        );
    }

    public function test_it_never_submits_text_notice_options(): void
    {
        $configOptions = [[
            'id' => 601, 'field' => 'cpu', 'name' => '明确禁止：不允许升级',
            'option_type' => 6, 'option_mode' => 'text',
            'sub' => [['id' => 1, 'option_name' => '不允许升级', 'value' => '不允许升级']],
        ]];

        $this->assertSame(
            [],
            ProductConfigOptionPresenter::submitConfigByOptionId($configOptions, ['cpu#601' => '不允许升级'])
        );
    }

    /**
     * @param  array<int, array<string, mixed>>  $items
     * @return array<int, array<string, mixed>>
     */
    private function formKeys(array $items): array
    {
        return ProductConfigOptionPresenter::present($items);
    }
}