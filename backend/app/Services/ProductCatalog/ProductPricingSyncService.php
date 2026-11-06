<?php

declare(strict_types=1);

namespace App\Services\ProductCatalog;

use App\Constants\BillingCycle;
use App\Exceptions\BusinessException;
use App\Models\Product;
use App\Services\System\OperationLogService;
use App\Support\Money;
use Illuminate\Support\Facades\DB;

/**
 * 商品定价「按月价同步到其他周期」（Issue #77）。
 *
 * 语义固定为**一次性写库**，不是持续联动：
 * 点一次把来源周期的价格 × 目标周期月数写进被勾选的周期，之后各周期仍然各自独立可改，
 * 这里不注册任何观察者/事件，改月付不会反过来改年付。
 *
 * 三条硬约束：
 * 1. 金额只走 `Money::multiply()`（源价 × 整数月数，末尾一次舍入到分），不自己写浮点乘法；
 * 2. 月数只取 `BillingCycle::RENEWABLE_MONTHS`（季 3 / 半年 6 / 年 12），不另写映射；
 * 3. 只写 `to_cycles` 里列出的周期，未勾选的周期一个字节都不动。
 *
 * `overwrite=false` 时只补空档：现有价 > 0 的周期跳过，并在返回里带上原因，
 * 由管理端把「覆盖了谁 / 跳过了谁」分列展示——静默丢弃是绝对不行的。
 */
final class ProductPricingSyncService
{
    public function __construct(
        private readonly SiteCatalogCacheInvalidator $catalogCache,
        private readonly OperationLogService $operationLogService,
    ) {}

    /**
     * @param  array{from_cycle?: string, to_cycles?: array<int, string>, overwrite?: bool}  $payload
     * @param  array<string, mixed>  $context  操作人上下文（operator_id / operator_name / ip_address / trace_id）
     * @return array{
     *     product_id: int,
     *     from_cycle: string,
     *     from_price: string,
     *     pricing: array<string, string>,
     *     updated: list<array<string, mixed>>,
     *     skipped: list<array<string, mixed>>,
     *     message: string
     * }
     */
    public function copy(Product $product, array $payload, array $context = []): array
    {
        $fromCycle = BillingCycle::normalize((string) ($payload['from_cycle'] ?? ''));
        $toCycles = $this->normalizeTargetCycles((array) ($payload['to_cycles'] ?? []), $fromCycle);
        $overwrite = (bool) ($payload['overwrite'] ?? false);

        throw_if($fromCycle === '', new BusinessException('请选择来源计费周期'));
        throw_if($toCycles === [], new BusinessException('请选择要同步的目标周期'));

        // 现有价目表：只保留认识的周期键，非数字一律按 0 处理（脏数据不该打断同步）
        $pricing = $this->normalizePricing((array) ($product->pricing ?? []));
        $sourcePrice = (float) ($pricing[$fromCycle] ?? 0);

        throw_if(
            $sourcePrice <= 0,
            new BusinessException('来源周期「'.BillingCycle::label($fromCycle).'」还没有有效价格，请先填写再同步')
        );

        $updated = [];
        $skipped = [];
        $nextPricing = $pricing;

        foreach ($toCycles as $cycle) {
            $months = (int) (BillingCycle::RENEWABLE_MONTHS[$cycle] ?? 0);
            if ($months <= 0) {
                continue;
            }

            $before = (string) ($pricing[$cycle] ?? '0.00');
            if (! $overwrite && (float) $before > 0) {
                $skipped[] = [
                    'cycle' => $cycle,
                    'label' => BillingCycle::label($cycle),
                    'reason' => 'existing_price',
                    'current' => $before,
                ];

                continue;
            }

            // 源价 × 月数，Money 内部只做一次分位舍入：33.33 × 12 = 399.96，
            // 不会写出 399.9599999999999 这种浮点尾巴。
            $after = Money::format(Money::multiply($sourcePrice, $months));
            $nextPricing[$cycle] = $after;
            $updated[] = [
                'cycle' => $cycle,
                'label' => BillingCycle::label($cycle),
                'months' => $months,
                'before' => $before,
                'after' => $after,
            ];
        }

        if ($updated !== []) {
            DB::transaction(function () use ($product, $nextPricing): void {
                // withoutEvents：价格是数据修正，不该触发商品模型上任何派生逻辑
                Product::withoutEvents(fn () => $product->forceFill(['pricing' => $nextPricing])->save());
            });

            $this->catalogCache->flush();
        }

        // 操作日志：即使一个周期都没改也记一条，事后能解释「点了同步但没生效」是为什么
        $this->operationLogService->write(
            userId: ((int) ($context['operator_id'] ?? 0)) ?: null,
            userType: 'admin',
            action: 'product.pricing.copy',
            module: 'product',
            targetId: (int) $product->id,
            detail: [
                'product_name' => (string) ($product->name ?? ''),
                'from_cycle' => $fromCycle,
                'from_price' => Money::format($sourcePrice),
                'overwrite' => $overwrite,
                'updated' => $updated,
                'skipped' => $skipped,
                'operator_name' => (string) ($context['operator_name'] ?? ''),
                'trace_id' => (string) ($context['trace_id'] ?? ''),
            ],
            ipAddress: ((string) ($context['ip_address'] ?? '')) ?: null,
        );

        return [
            'product_id' => (int) $product->id,
            'from_cycle' => $fromCycle,
            'from_price' => Money::format($sourcePrice),
            'pricing' => $nextPricing,
            'updated' => $updated,
            'skipped' => $skipped,
            'message' => $updated === []
                ? '未改动任何周期：所选周期均已有价格（未勾选「覆盖已有价格」）'
                : sprintf(
                    '已同步 %d 个周期%s',
                    count($updated),
                    $skipped === [] ? '' : sprintf('，跳过 %d 个已有价格', count($skipped))
                ),
        ];
    }

    /**
     * 目标周期归一：仅保留在售周期、去掉来源周期与重复项。
     *
     * 校验层已经挡过一轮，这里再收一次是因为本方法也可能被命令/其他调用方复用，
     * 而写错一个周期键就是往价目表里塞一条没人能下单的价格。
     *
     * @param  array<int, mixed>  $cycles
     * @return list<string>
     */
    private function normalizeTargetCycles(array $cycles, string $fromCycle): array
    {
        $normalized = [];

        foreach ($cycles as $cycle) {
            $value = BillingCycle::normalize(is_string($cycle) ? $cycle : '');
            if (! isset(BillingCycle::RENEWABLE_MONTHS[$value]) || $value === $fromCycle) {
                continue;
            }

            if (! in_array($value, $normalized, true)) {
                $normalized[] = $value;
            }
        }

        return $normalized;
    }

    /**
     * 现有价目表归一：两位小数字符串，未知周期键原样保留（不删别人写进去的东西）。
     *
     * @param  array<string, mixed>  $pricing
     * @return array<string, string>
     */
    private function normalizePricing(array $pricing): array
    {
        $normalized = [];

        foreach ($pricing as $cycle => $amount) {
            $key = (string) $cycle;
            if ($key === '') {
                continue;
            }

            $normalized[$key] = is_numeric($amount) ? number_format((float) $amount, 2, '.', '') : '0.00';
        }

        return $normalized;
    }
}
