<?php

declare(strict_types=1);

namespace App\Support;

use App\Constants\ProductType;
use App\Models\FirstProductGroup;
use App\Models\SecondProductGroup;
use App\Models\ThirdProductGroup;
use Illuminate\Database\Eloquent\Builder;

/**
 * 站点可见商品分组（二级/三级）的统一查询入口。
 *
 * 站点侧的分层语义是「一级菜单 = first_product_groups.code」，
 * 二级、三级都是各自一级菜单下的子节点。因此：
 * - 传入一级菜单 code 时必须严格按 code 命中，绝不能用 product_type 兜底。
 *   多个一级菜单可以共用同一个商品类型（product_type），
 *   一旦用 product_type 兜底，大陆云服与轻量服务器这种同类型菜单会被并进同一份列表，
 *   前端再也分不清某个二级分类到底挂在哪个一级菜单下。
 * - 只有显式传入 product_type 时，才展开成该业务类型下的全部一级菜单 code，
 *   仍然逐条返回各自的 first_product_group_code，不做合并。
 */
final class SiteProductGroupQuery
{
    /**
     * 站点可见的二级分组。
     *
     * @param  string|null  $firstGroupCode  一级菜单 code（精确匹配）
     * @param  string|null  $businessType  业务商品类型（展开为该类型下的一级菜单 code 集合）
     */
    public static function visibleSecondGroups(
        ?string $firstGroupCode = null,
        ?string $businessType = null,
    ): Builder {
        $query = self::baseSecondGroupQuery();
        $codes = self::resolveFirstGroupCodes($firstGroupCode, $businessType);

        if ($codes === []) {
            return $query->whereRaw('1 = 0');
        }

        return $query->whereIn('first_product_groups.code', $codes);
    }

    /**
     * 站点可见的三级分组，可选限定在某个二级分组下。
     */
    public static function visibleThirdGroups(?int $secondGroupId = null): Builder
    {
        return ThirdProductGroup::query()
            ->select('third_product_groups.*')
            ->join('second_product_groups', 'second_product_groups.id', '=', 'third_product_groups.second_product_group_id')
            ->join('first_product_groups', 'first_product_groups.id', '=', 'second_product_groups.first_product_group_id')
            ->where('third_product_groups.is_visible', 1)
            ->where('second_product_groups.is_visible', 1)
            ->where('first_product_groups.is_visible', 1)
            ->whereIn('first_product_groups.code', ProductType::visibleValues())
            ->when($secondGroupId !== null && $secondGroupId > 0, fn (Builder $query): Builder => $query->where(
                'third_product_groups.second_product_group_id',
                $secondGroupId
            ));
    }

    private static function baseSecondGroupQuery(): Builder
    {
        $visibleValues = ProductType::visibleValues();

        if ($visibleValues === []) {
            return SecondProductGroup::query()->whereRaw('1 = 0');
        }

        return SecondProductGroup::query()
            ->select('second_product_groups.*')
            ->join('first_product_groups', 'first_product_groups.id', '=', 'second_product_groups.first_product_group_id')
            ->where('second_product_groups.is_visible', 1)
            ->where('first_product_groups.is_visible', 1)
            ->whereIn('first_product_groups.code', $visibleValues);
    }

    /**
     * @return array<int, string>
     */
    private static function resolveFirstGroupCodes(?string $firstGroupCode, ?string $businessType): array
    {
        $visibleValues = ProductType::visibleValues();
        $normalizedCode = trim((string) $firstGroupCode);
        $normalizedType = trim((string) $businessType);

        if ($normalizedCode !== '' && in_array($normalizedCode, $visibleValues, true)) {
            return [$normalizedCode];
        }

        if ($normalizedType === '') {
            return $normalizedCode !== '' ? [$normalizedCode] : $visibleValues;
        }

        $businessValue = ProductType::normalizeBusinessValue($normalizedType);

        return FirstProductGroup::query()
            ->whereIn('code', $visibleValues)
            ->get(['code', 'product_type'])
            ->filter(fn (FirstProductGroup $group): bool => $group->code !== $normalizedCode
                && ProductType::businessValueForFirstGroup($group, $group->code) === $businessValue)
            ->pluck('code')
            ->map(fn ($code): string => (string) $code)
            ->values()
            ->all();
    }
}