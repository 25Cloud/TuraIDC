<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Exceptions\BusinessException;
use PHPUnit\Framework\TestCase;

/**
 * 业务异常在「没有已启动容器」的场景下必须能正常构造。
 *
 * 起因：BusinessException 构造时会查翻译表（Lang::has）。而本文件刻意用裸
 * PHPUnit\Framework\TestCase —— 容器里没有绑定 translator，Lang 门面直接抛
 * BindingResolutionException("Target class [translator] does not exist")，
 * 于是调用方拿到的是绑定异常而不是业务异常，异常语义整个被换掉。
 *
 * 这不只是测试环境的问题：配置缓存、队列反序列化等 translator 尚未就绪的
 * 早期启动路径同样没有它。取译文只能是锦上添花，绝不能成为构造的前提。
 *
 * 回落语义与「未翻译则回落中文」一致，因此构造失败时返回中文原文是安全的。
 */
class BusinessExceptionWithoutContainerTest extends TestCase
{
    public function test_it_constructs_chinese_message_without_a_booted_translator(): void
    {
        $exception = new BusinessException('当前商品暂无可用库存');

        $this->assertSame('当前商品暂无可用库存', $exception->getMessage());
        $this->assertSame(42200, $exception->getErrorCode());
        $this->assertSame(422, $exception->getCode());
    }

    public function test_it_still_passes_through_non_chinese_messages(): void
    {
        $this->assertSame('upstream timeout', (new BusinessException('upstream timeout'))->getMessage());
    }

    public function test_it_treats_an_empty_message_as_unchanged(): void
    {
        $this->assertSame('', (new BusinessException(''))->getMessage());
    }
}