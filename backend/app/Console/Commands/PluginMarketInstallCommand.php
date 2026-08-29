<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Integrations\Plugins\PluginMarketService;
use Illuminate\Console\Command;
use Throwable;

/**
 * 从插件市场安装插件。
 *
 * 默认按索引条目下载 tag（或 sha）固定引用的 GitHub archive；
 * --zip 指定本地插件包（zip），slug 取包内 manifest 并与参数比对。
 */
class PluginMarketInstallCommand extends Command
{
    protected $signature = 'plugin:market:install
        {slug : 插件标识（snake_case，与插件包 manifest slug 一致）}
        {--zip= : 从本地插件包（zip）手动安装，跳过市场下载}
        {--force : 目标插件目录已存在时覆盖}';

    protected $description = '从插件市场下载并安装插件';

    public function handle(PluginMarketService $market): int
    {
        $slug = trim((string) $this->argument('slug'));
        $zip = $this->option('zip') !== null ? trim((string) $this->option('zip')) : null;

        if ($slug === '') {
            $this->error('请提供插件 slug。');

            return self::FAILURE;
        }

        $this->line($zip === null
            ? "正在从市场下载并安装：{$slug}（仅下载索引锁定的版本）"
            : "正在从本地插件包安装：{$slug} <- {$zip}");

        try {
            $result = $market->install($slug, $zip, (bool) $this->option('force'));
        } catch (Throwable $exception) {
            $this->error('安装失败：'.$exception->getMessage());

            return self::FAILURE;
        }

        $plugin = $result['plugin'];
        $this->info("安装成功：{$plugin['name']}（{$plugin['slug']} v{$plugin['version']}）");
        $this->line('下一步：管理端 → 插件中心 → 启用该插件并填写配置。');

        return self::SUCCESS;
    }
}
