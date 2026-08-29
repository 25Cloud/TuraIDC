<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Integrations\Plugins\PluginMarketService;
use Illuminate\Console\Command;
use Throwable;

/**
 * 同步插件市场索引并列出可安装插件。
 *
 * 索引缓存 5 分钟；--force 强制重新拉取。
 */
class PluginMarketListCommand extends Command
{
    protected $signature = 'plugin:market:list
        {--force : 强制重新拉取索引，忽略缓存}';

    protected $description = '同步插件市场索引并列出可安装插件';

    public function handle(PluginMarketService $market): int
    {
        try {
            $index = $market->fetchIndex((bool) $this->option('force'));
            $entries = $index['plugins'];
        } catch (Throwable $exception) {
            $this->error('拉取插件市场索引失败：'.$exception->getMessage());
            $this->line('可检查网络/加速镜像（config/plugins.php）后重试，或稍后重试。');

            return self::FAILURE;
        }

        $this->line('插件市场索引同步于：'.($index['updated_at'] !== '' ? $index['updated_at'] : '未知'));

        if ($entries === []) {
            $this->warn('当前市场暂无插件。开发者可通过 PR 向 25Cloud/turaidc-plugin-index 提交插件。');

            return self::SUCCESS;
        }

        $rows = array_map(
            fn (array $entry): array => [
                $entry['slug'],
                $entry['domain'],
                $entry['name'],
                $entry['developer'],
                $entry['tag'],
                $entry['license'],
                mb_strimwidth($entry['description'], 0, 40, '…'),
            ],
            $entries
        );

        $this->table(['slug', 'domain', '名称', '开发者', 'tag', '许可', '描述'], $rows);
        $this->line('安装：php artisan plugin:market:install {slug}');

        return self::SUCCESS;
    }
}
