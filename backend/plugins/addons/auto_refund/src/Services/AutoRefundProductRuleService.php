<?php

declare(strict_types=1);

namespace TuraIDC\Plugins\Addons\AutoRefund\Services;

use App\Exceptions\BusinessException;
use App\Models\AdminUser;
use App\Models\Product;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use TuraIDC\Plugins\Addons\AutoRefund\Models\AutoRefundProductRule;

/**
 * 商品退款策略的管理端读写（`GET/PUT /api/v2/admin/auto-refund/product-rules`）。
 *
 * 列表以**商品**为左表：策略是商品的附加配置，「没配过策略的商品」必须也能列出来，
 * 否则运营根本看不到哪些商品还没配置——那正是这个页面存在的意义。
 *
 * 一级分组名的取值链路是 `products.product_group_id → third_product_groups →
 * second_product_groups → first_product_groups`，与 `Product::scopeInFirstProductGroup`
 * 同一口径（商品实际挂的是三级分组，一级分组是分类导航层）。
 */
final class AutoRefundProductRuleService
{
    public const STATUS_ALL = 'all';

    public const STATUS_CONFIGURED = 'configured';

    public const STATUS_UNCONFIGURED = 'unconfigured';

    /**
     * @param  array<string, mixed>  $filters
     */
    public function paginate(array $filters, int $perPage): LengthAwarePaginator
    {
        $query = $this->baseQuery();

        $keyword = trim((string) ($filters['keyword'] ?? ''));
        if ($keyword !== '') {
            // products 表没有 `name` 列：商品展示名就是 `custom_display_name`
            $query->where(function ($builder) use ($keyword): void {
                $builder->where('p.custom_display_name', 'like', '%'.$keyword.'%');

                if (ctype_digit($keyword)) {
                    $builder->orWhere('p.id', (int) $keyword);
                }
            });
        }

        $status = trim((string) ($filters['status'] ?? self::STATUS_ALL));
        if ($status === self::STATUS_CONFIGURED) {
            $query->whereNotNull('r.id');
        } elseif ($status === self::STATUS_UNCONFIGURED) {
            $query->whereNull('r.id');
        }

        $paginator = $query
            ->orderBy('p.id')
            ->paginate(perPage: $perPage, page: max(1, (int) ($filters['page'] ?? 1)));

        $paginator->setCollection(
            $paginator->getCollection()->map(fn (object $row): array => $this->presentRow($row)),
        );

        return $paginator;
    }

    /**
     * 保存单个商品的策略（幂等：同一商品只有一行）。
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public function save(AdminUser $admin, int $productId, array $data): array
    {
        $product = Product::query()->find($productId);
        if (! $product instanceof Product) {
            throw new BusinessException('商品不存在', 40400, 404);
        }

        $rule = AutoRefundProductRule::query()->firstOrNew(['product_id' => $productId]);
        $rule->fill([
            'refund_type' => (string) ($data['refund_type'] ?? AutoRefundProductRule::TYPE_MANUAL),
            'refund_rule' => (string) ($data['refund_rule'] ?? AutoRefundProductRule::RULE_DURATION),
            'requirement' => (string) ($data['requirement'] ?? AutoRefundProductRule::REQUIREMENT_PRODUCT_FIRST),
            'window_hours' => (int) ($data['window_hours'] ?? 0),
            'enabled' => (bool) ($data['enabled'] ?? true),
            'operator_id' => (int) $admin->id,
        ]);
        $rule->product_id = $productId;
        $rule->save();

        $row = $this->baseQuery()->where('p.id', $productId)->first();
        if ($row === null) {
            throw new BusinessException('商品策略保存后读取失败', 50000);
        }

        return $this->presentRow($row);
    }

    /**
     * 商品 + 策略 + 一级分组名的统一投影。
     */
    private function baseQuery(): Builder
    {
        return DB::table('products as p')
            ->leftJoin('auto_refund_product_rules as r', 'r.product_id', '=', 'p.id')
            ->leftJoin('third_product_groups as t', 't.id', '=', 'p.product_group_id')
            ->leftJoin('second_product_groups as s', 's.id', '=', 't.second_product_group_id')
            ->leftJoin('first_product_groups as f', 'f.id', '=', 's.first_product_group_id')
            ->select([
                'p.id as product_id',
                'p.custom_display_name as product_display_name',
                'f.id as first_group_id',
                'f.name as first_group_name',
                'r.id as rule_id',
                'r.refund_type',
                'r.refund_rule',
                'r.requirement',
                'r.window_hours',
                'r.enabled',
                'r.operator_id',
                'r.updated_at',
            ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function presentRow(object $row): array
    {
        $configured = ($row->rule_id ?? null) !== null;
        $refundType = $configured
            ? (string) ($row->refund_type ?? AutoRefundProductRule::TYPE_MANUAL)
            : null;
        $refundRule = $configured
            ? (string) ($row->refund_rule ?? AutoRefundProductRule::RULE_DURATION)
            : null;
        $requirement = $configured
            ? (string) ($row->requirement ?? AutoRefundProductRule::REQUIREMENT_PRODUCT_FIRST)
            : null;

        $displayName = trim((string) ($row->product_display_name ?? ''));

        return [
            'product_id' => (int) $row->product_id,
            'product_name' => $displayName !== '' ? $displayName : '商品 #'.(int) $row->product_id,
            'first_group_id' => (int) ($row->first_group_id ?? 0) ?: null,
            'first_group_name' => (string) ($row->first_group_name ?? ''),
            'configured' => $configured,
            'refund_type' => $refundType,
            'refund_type_label' => $refundType === null
                ? null
                : (AutoRefundProductRule::TYPE_LABELS[$refundType] ?? $refundType),
            'refund_rule' => $refundRule,
            'refund_rule_label' => $refundRule === null
                ? null
                : (AutoRefundProductRule::RULE_LABELS[$refundRule] ?? $refundRule),
            'requirement' => $requirement,
            'requirement_label' => $requirement === null
                ? null
                : (AutoRefundProductRule::REQUIREMENT_LABELS[$requirement] ?? $requirement),
            'window_hours' => (int) ($row->window_hours ?? 0),
            'enabled' => $configured ? (bool) $row->enabled : false,
            'enabled_label' => $configured && (bool) $row->enabled ? '启用' : ($configured ? '停用' : '未配置'),
            'operator_id' => ($row->operator_id ?? null) === null ? null : (int) $row->operator_id,
            'updated_at' => $row->updated_at === null ? null : (string) $row->updated_at,
        ];
    }
}
