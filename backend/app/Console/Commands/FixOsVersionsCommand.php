<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * 修复 products.config_options 中 OS 配置项（option_type=5）子项 version 字段：
 * - 为每个 sub 填充 version（格式 `大类^版本`），前端 buildOsGroups 依赖该格式分组
 * - 清理多余 `^`（如 Windows^Windows^Windows7 → Windows^Windows7）
 *
 * 注意：`foreach (($o["sub"] ?? []) as &$s)` 中 `??` 会生成副本导致引用修改丢失，
 * 必须先 isset 再引用循环。
 */
class FixOsVersionsCommand extends Command
{
    private const OS_OPTION_TYPE = 5;

    protected $signature = 'app:fix-os-versions
        {--product-ids= : 逗号分隔的商品 ID，仅处理指定商品}
        {--dry-run : 只统计，不写入数据库}
        {--json : 以 JSON 输出结果}';

    protected $description = '修复 OS 配置项 sub[].version（大类^版本）并清理多余 ^';

    public function handle(): int
    {
        if (! DB::getSchemaBuilder()->hasTable('products') || ! DB::getSchemaBuilder()->hasColumn('products', 'config_options')) {
            $this->error('products.config_options 不存在');

            return self::FAILURE;
        }

        $dryRun = (bool) $this->option('dry-run');
        $json = (bool) $this->option('json');
        $idsRaw = trim((string) $this->option('product-ids'));

        $query = DB::table('products')
            ->select(['id', 'config_options'])
            ->whereNotNull('config_options')
            ->where('config_options', '<>', '')
            ->whereRaw('JSON_LENGTH(config_options) > 0');

        if ($idsRaw !== '') {
            $ids = array_filter(array_map('intval', explode(',', $idsRaw)));
            if ($ids === []) {
                $this->error('--product-ids 参数格式错误');

                return self::INVALID;
            }
            $query->whereIn('id', $ids);
        }

        $rows = $query->orderBy('id')->get();

        $osItems = 0;
        $missingVersion = 0;
        $updated = 0;

        foreach ($rows as $row) {
            $decoded = json_decode((string) $row->config_options, true);
            if (! is_array($decoded) || $decoded === []) {
                continue;
            }

            $changed = false;
            foreach ($decoded as $index => $item) {
                if (! is_array($item) || (int) ($item['option_type'] ?? -1) !== self::OS_OPTION_TYPE) {
                    continue;
                }

                $osItems++;
                if (! isset($item['sub']) || ! is_array($item['sub'])) {
                    continue;
                }

                // 先 isset 再引用循环，避免 `?? []` 副本导致引用修改丢失
                foreach ($item['sub'] as $subIndex => $sub) {
                    if (! is_array($sub)) {
                        continue;
                    }

                    $raw = trim((string) ($sub['version'] ?? $sub['option_name'] ?? ''));
                    $normalized = $this->normalizeOsVersion($raw);
                    $hasVersion = isset($sub['version']) && trim((string) $sub['version']) !== '';
                    if (! $hasVersion || $normalized !== trim((string) $sub['version'])) {
                        $decoded[$index]['sub'][$subIndex]['version'] = $normalized;
                        $changed = true;
                    }
                    if ($normalized === '' || str_ends_with($normalized, '^')) {
                        $missingVersion++;
                    }
                }
            }

            if (! $changed) {
                continue;
            }

            $updated++;
            if (! $dryRun) {
                DB::table('products')
                    ->where('id', (int) $row->id)
                    ->update(['config_options' => json_encode($decoded, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)]);
            }
        }

        if ($json) {
            $this->line(json_encode([
                'os_items' => $osItems,
                'missing_version' => $missingVersion,
                'products_updated' => $updated,
                'dry_run' => $dryRun,
            ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));

            return self::SUCCESS;
        }

        $this->info($dryRun ? '=== OS version 修复预检（--dry-run）===' : '=== OS version 修复完成 ===');
        $this->line('os_items: '.$osItems);
        $this->line('missing_version: '.$missingVersion);
        $this->line('products_updated: '.$updated);

        return self::SUCCESS;
    }

    private function normalizeOsVersion(string $raw): string
    {
        $text = trim($raw);
        if ($text === '') {
            return '';
        }

        // 去掉 `数字|` 前缀（老站 id 前缀），如 `12|CentOS^CentOS-7.6.1810-x64`
        $parts = explode('|', $text, 2);
        if (count($parts) === 2 && preg_match('/^\d+$/', trim($parts[0])) === 1) {
            $text = trim($parts[1]);
        }

        // 合并连续 ^
        while (str_contains($text, '^^')) {
            $text = str_replace('^^', '^', $text);
        }

        $segments = array_values(array_filter(array_map('trim', explode('^', $text)), static fn (string $part): bool => $part !== ''));
        if ($segments === []) {
            return '';
        }

        if (count($segments) === 1) {
            return $segments[0].'^';
        }

        return $segments[0].'^'.implode('^', array_slice($segments, 1));
    }
}
