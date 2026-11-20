<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\ProductCatalog\ProductSyncService;
use PHPUnit\Framework\Attributes\Test;
use ReflectionClass;
use Tests\TestCase;

/**
 * 每日上游配置同步只允许覆盖「结构」，不得回滚商家在本地的运营决定。
 *
 * 已有先例是 markup 与 pricing：上游目录不提供它们，被远端覆盖就等于商家的
 * 加价与定价静默失效。hidden（后台把配置项下架）是同一类本地状态。
 */
class ProductSyncConfigOptionMergeTest extends TestCase
{
    #[Test]
    public function locally_hidden_option_stays_hidden_after_sync(): void
    {
        $merged = $this->merge(
            [[
                'id' => 1,
                'field' => 'datadisk',
                'hidden' => 1,
                'qty_maximum' => 300,
                'pricing' => [],
                'sub' => [],
            ]],
            [[
                'id' => 1,
                'field' => 'datadisk',
                'hidden' => 0,
                'qty_maximum' => 300,
                'sub' => [],
            ]]
        );

        $this->assertSame(1, (int) $merged[0]['hidden']);
    }

    #[Test]
    public function upstream_hidden_option_still_wins_when_local_is_visible(): void
    {
        $merged = $this->merge(
            [[
                'id' => 1,
                'field' => 'ip_num',
                'hidden' => 0,
                'sub' => [],
            ]],
            [[
                'id' => 1,
                'field' => 'ip_num',
                'hidden' => 1,
                'sub' => [],
            ]]
        );

        $this->assertSame(1, (int) $merged[0]['hidden']);
    }

    #[Test]
    public function structure_still_comes_from_remote(): void
    {
        $merged = $this->merge(
            [[
                'id' => 1,
                'field' => 'system_disk_size',
                'hidden' => 1,
                'qty_minimum' => 15,
                'qty_maximum' => 400,
                'sub' => [],
            ]],
            [[
                'id' => 1,
                'field' => 'system_disk_size',
                'hidden' => 0,
                'qty_minimum' => 20,
                'qty_maximum' => 500,
                'sub' => [],
            ]]
        );

        $this->assertSame(20, (int) $merged[0]['qty_minimum']);
        $this->assertSame(500, (int) $merged[0]['qty_maximum']);
        $this->assertSame(1, (int) $merged[0]['hidden']);
    }

    #[Test]
    public function local_pricing_and_markup_are_preserved(): void
    {
        $merged = $this->merge(
            [[
                'id' => 1,
                'field' => 'datadisk',
                'pricing' => ['monthly' => '3.00'],
                'markup' => ['enabled' => true, 'mode' => 'multiplier', 'multiplier' => 1.5],
                'sub' => [
                    ['id' => 11, 'qty_minimum' => 1, 'qty_maximum' => 300, 'pricing' => ['monthly' => '3.00']],
                ],
            ]],
            [[
                'id' => 1,
                'field' => 'datadisk',
                'sub' => [
                    ['id' => 11, 'qty_minimum' => 1, 'qty_maximum' => 300, 'pricing' => ['monthly' => '0.30']],
                ],
            ]]
        );

        $this->assertSame(['monthly' => '3.00'], $merged[0]['pricing']);
        $this->assertSame('multiplier', $merged[0]['markup']['mode']);
        $this->assertSame(['monthly' => '3.00'], $merged[0]['sub'][0]['pricing']);
    }

    /**
     * @param  array<int, array<string, mixed>>  $local
     * @param  array<int, array<string, mixed>>  $remote
     * @return array<int, array<string, mixed>>
     */
    private function merge(array $local, array $remote): array
    {
        $service = app(ProductSyncService::class);
        $reflection = new ReflectionClass($service);
        $method = $reflection->getMethod('mergeConfigOptionsPreservingPricing');
        $method->setAccessible(true);

        return (array) $method->invoke($service, $local, $remote);
    }
}