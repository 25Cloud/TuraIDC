<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Support\ProductConfigOptionPresenter;
use PHPUnit\Framework\TestCase;

/**
 * 配置项「是否处理器规格」判据回归。
 *
 * 魔方（智简魔方财务）里 field 只是可读标签、允许重复：虚拟主机与 CDN 的
 * 全部配置项 field 都是 cpu（option_type 又都是 6，经 TYPE_FIELD_MAP 同样
 * 映射成 cpu）。只凭 field 判定会让这些项被当成处理器规格——
 * 商品名解析会把「绑定域名数」写进 CPU 位，规格亮点会把「WEB空间」标成 CPU，
 * 虚拟主机「12T高防主机」曾因此被显示成「内存 12T」。
 *
 * 判据与官网前端 machineSpecResolver 的 isCpuConfigKey / isMemoryConfigKey 同口径。
 */
class ProductConfigOptionProcessorSpecTest extends TestCase
{
    public function test_it_rejects_reused_cpu_field_for_non_processor_items(): void
    {
        foreach (['绑定域名数', 'WEB空间', '数据库空间', '流量限制', '带宽', '域名数', 'HTTP端口'] as $name) {
            $this->assertFalse(
                ProductConfigOptionPresenter::isProcessorSpecOption(
                    ['field' => 'cpu', 'name' => $name],
                    ProductConfigOptionPresenter::CPU_KEYWORDS
                ),
                "「{$name}」的 field 虽然是 cpu，但它不是处理器规格"
            );
        }
    }

    public function test_it_accepts_real_processor_items(): void
    {
        foreach (['处理器核心', 'CPU', 'cpu型号', '核心数'] as $name) {
            $this->assertTrue(
                ProductConfigOptionPresenter::isProcessorSpecOption(
                    ['field' => 'cpu', 'name' => $name],
                    ProductConfigOptionPresenter::CPU_KEYWORDS
                ),
                "「{$name}」确实是处理器规格"
            );
        }

        $this->assertTrue(ProductConfigOptionPresenter::isProcessorSpecOption(
            ['field' => 'memory', 'name' => '内存'],
            ProductConfigOptionPresenter::MEMORY_KEYWORDS
        ));
    }

    public function test_it_checks_matched_memory_keywords_only(): void
    {
        // 内存关键词不应被 cpu 的判定误伤，反之亦然
        $this->assertFalse(ProductConfigOptionPresenter::isProcessorSpecOption(
            ['field' => 'cpu', 'name' => '内存'],
            ProductConfigOptionPresenter::CPU_KEYWORDS
        ));
        $this->assertFalse(ProductConfigOptionPresenter::isProcessorSpecOption(
            ['field' => 'memory', 'name' => '处理器核心'],
            ProductConfigOptionPresenter::MEMORY_KEYWORDS
        ));
    }

    public function test_it_falls_back_to_field_only_when_name_is_missing(): void
    {
        // 没有名称就没有第二判据：云主机历史数据里 field 才是唯一线索
        $this->assertTrue(ProductConfigOptionPresenter::isProcessorSpecOption(
            ['field' => 'cpu', 'name' => ''],
            ProductConfigOptionPresenter::CPU_KEYWORDS
        ));
        $this->assertFalse(ProductConfigOptionPresenter::isProcessorSpecOption(
            ['field' => 'bw', 'name' => ''],
            ProductConfigOptionPresenter::CPU_KEYWORDS
        ));
    }

    public function test_it_validates_a_pre_resolved_field(): void
    {
        // option_type=6 经 TYPE_FIELD_MAP 映射后同样是 cpu，
        // 所以判据必须能接收「已解析的 field」而不只是原始字段。
        $this->assertFalse(ProductConfigOptionPresenter::isProcessorSpecField(
            'cpu',
            'WEB空间',
            ProductConfigOptionPresenter::CPU_KEYWORDS
        ));
        $this->assertTrue(ProductConfigOptionPresenter::isProcessorSpecField(
            'cpu',
            '处理器核心',
            ProductConfigOptionPresenter::CPU_KEYWORDS
        ));
        $this->assertFalse(ProductConfigOptionPresenter::isProcessorSpecField(
            '',
            '处理器核心',
            ProductConfigOptionPresenter::CPU_KEYWORDS
        ));
    }
}