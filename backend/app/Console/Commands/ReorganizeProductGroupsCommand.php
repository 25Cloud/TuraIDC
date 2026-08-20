<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\FirstProductGroup;
use App\Models\SecondProductGroup;
use App\Models\ThirdProductGroup;
use App\Services\ProductCatalog\ProductGroupHierarchyService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * 重建三级商品分类目录（幂等）：
 * 1. 强制一级组规范为 ProductType 内置 5 类（vps/dedicated/hosting/domain/other）
 * 2. 重映射二级/三级组归属到规范一级组（老站迁移组降级）
 * 3. 按一级组 code 派生 products.service_type_code（迁移器不迁移该字段）
 * 4. 隐藏无商品的空二级组
 */
class ReorganizeProductGroupsCommand extends Command
{
    protected $signature = 'app:reorganize-product-groups
        {--dry-run : 只输出计划，不写入数据库}
        {--force : 允许重映射已被人工调整的分组归属}
        {--json : 以 JSON 输出结果}';

    protected $description = '重建三级商品分类目录（一级组规范化、归属重映射、service_type_code 派生、隐藏空组）';

    public function handle(ProductGroupHierarchyService $hierarchyService): int
    {
        if (! $hierarchyService->tablesReady()) {
            $this->error('三层商品分类表尚未全部创建，无法执行');

            return self::FAILURE;
        }

        $dryRun = (bool) $this->option('dry-run');
        $force = (bool) $this->option('force');
        $json = (bool) $this->option('json');

        $stats = [
            'first_groups' => 0,
            'second_remapped' => 0,
            'third_synced' => 0,
            'service_type_derived' => 0,
            'empty_groups_hidden' => 0,
            'dry_run' => $dryRun,
        ];

        DB::transaction(function () use (&$stats, $hierarchyService, $dryRun, $force): void {
            // 1. 一级组规范化（复用产品类型同步：确保内置 5 类存在且可见）
            $stats['first_groups'] = $hierarchyService->syncProductTypes()['synced_count'] ?? 0;

            // 2. 重映射二级组：按名称归属到规范一级组（老站迁移组 code 错乱时兜底）
            $stats['second_remapped'] = $this->remapSecondGroups($dryRun, $force);

            // 3. 补齐三级组（二级组数据副本，满足 products.product_group_id 外键指向）
            $stats['third_synced'] = $this->syncThirdGroups($dryRun);

            // 4. 派生 service_type_code（products → 三级 → 二级 → 一级 code）
            $stats['service_type_derived'] = $this->deriveServiceTypeCode($dryRun);

            // 5. 隐藏无商品的空二级组
            $stats['empty_groups_hidden'] = $this->hideEmptySecondGroups($dryRun);
        });

        if ($json) {
            $this->line(json_encode($stats, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));

            return self::SUCCESS;
        }

        $this->info($dryRun ? '=== 分组重建计划（--dry-run）===' : '=== 分组重建完成 ===');
        $this->line('一级组规范化: '.(int) $stats['first_groups']);
        $this->line('二级组重映射: '.(int) $stats['second_remapped']);
        $this->line('三级组补齐: '.(int) $stats['third_synced']);
        $this->line('service_type_code 派生: '.(int) $stats['service_type_derived']);
        $this->line('空二级组隐藏: '.(int) $stats['empty_groups_hidden']);

        return self::SUCCESS;
    }

    private function remapSecondGroups(bool $dryRun, bool $force): int
    {
        $remapped = 0;

        $groups = SecondProductGroup::query()->get();
        foreach ($groups as $group) {
            $target = $this->resolveFirstGroupForSecondGroup($group);
            if ($target === null || (int) $group->first_product_group_id === (int) $target->id) {
                continue;
            }

            if (! $force && (int) ($group->first_product_group_id ?? 0) > 0) {
                // 非 --force 时不改动已被人工归属的组，仅处理孤儿组
                continue;
            }

            if (! $dryRun) {
                $group->forceFill(['first_product_group_id' => (int) $target->id])->save();
            }
            $remapped++;
        }

        return $remapped;
    }

    private function resolveFirstGroupForSecondGroup(SecondProductGroup $group): ?FirstProductGroup
    {
        $name = trim((string) $group->name);

        $rules = [
            'vps' => ['vps', '云服务器', '云主机', 'vps服务器'],
            'dedicated' => ['dedicated', '游戏云', '高防', '游戏', '独立'],
            'hosting' => ['hosting', '虚拟主机', '虚拟空间', '空间'],
            'domain' => ['domain', '域名', '云电脑', '电脑'],
        ];

        foreach ($rules as $code => $keywords) {
            foreach ($keywords as $keyword) {
                if ($name !== '' && mb_stripos($name, $keyword) !== false) {
                    return FirstProductGroup::query()->where('code', $code)->first();
                }
            }
        }

        return FirstProductGroup::query()->where('code', 'other')->first();
    }

    private function syncThirdGroups(bool $dryRun): int
    {
        $synced = 0;

        $secondGroups = SecondProductGroup::query()
            ->select(['id', 'first_product_group_id', 'name', 'slug', 'description', 'sort_order', 'is_visible'])
            ->get();

        foreach ($secondGroups as $group) {
            $exists = ThirdProductGroup::query()->where('id', (int) $group->id)->exists();
            if ($exists) {
                continue;
            }

            if (! $dryRun) {
                ThirdProductGroup::query()->create([
                    'id' => (int) $group->id,
                    'second_product_group_id' => (int) $group->id,
                    'name' => (string) $group->name,
                    'slug' => (string) $group->slug,
                    'description' => $group->description,
                    'sort_order' => (int) $group->sort_order,
                    'is_visible' => (int) $group->is_visible,
                ]);
            }
            $synced++;
        }

        return $synced;
    }

    private function deriveServiceTypeCode(bool $dryRun): int
    {
        $count = DB::table('products as p')
            ->join('third_product_groups as t', 't.id', '=', 'p.product_group_id')
            ->join('second_product_groups as s', 's.id', '=', 't.second_product_group_id')
            ->join('first_product_groups as f', 'f.id', '=', 's.first_product_group_id')
            ->whereNotNull('f.code')
            ->where('f.code', '<>', '')
            ->where(function ($query): void {
                $query->whereNull('p.service_type_code')
                    ->orWhere('p.service_type_code', '=', '');
            })
            ->count();

        if ($dryRun || $count === 0) {
            return $count;
        }

        return DB::table('products as p')
            ->join('third_product_groups as t', 't.id', '=', 'p.product_group_id')
            ->join('second_product_groups as s', 's.id', '=', 't.second_product_group_id')
            ->join('first_product_groups as f', 'f.id', '=', 's.first_product_group_id')
            ->whereNotNull('f.code')
            ->where('f.code', '<>', '')
            ->where(function ($query): void {
                $query->whereNull('p.service_type_code')
                    ->orWhere('p.service_type_code', '=', '');
            })
            ->update(['p.service_type_code' => DB::raw('f.code')]);
    }

    private function hideEmptySecondGroups(bool $dryRun): int
    {
        $emptyIds = SecondProductGroup::query()
            ->whereNotExists(function ($query): void {
                $query->selectRaw('1')
                    ->from('third_product_groups as t')
                    ->whereColumn('t.second_product_group_id', 'second_product_groups.id')
                    ->whereExists(function ($subQuery): void {
                        $subQuery->selectRaw('1')
                            ->from('products as p')
                            ->whereColumn('p.product_group_id', 't.id');
                    });
            })
            ->pluck('id')
            ->all();

        if ($dryRun || $emptyIds === []) {
            return count($emptyIds);
        }

        return SecondProductGroup::query()
            ->whereIn('id', $emptyIds)
            ->where('is_visible', 1)
            ->update(['is_visible' => 0]);
    }
}
