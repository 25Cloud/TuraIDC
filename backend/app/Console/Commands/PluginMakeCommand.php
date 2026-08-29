<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Integrations\Plugins\PluginDomain;
use Illuminate\Console\Command;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * 按插件包规范生成插件骨架（config.php manifest + 入口类 + 可选 Provider / 定时任务）。
 *
 * 生成的目录结构与运行期契约（config.php 的 info/config、入口 execute()、lib 自动加载、
 * 心跳定时任务）与 backend/plugins/ 内置插件完全一致，可直接被 PluginScanner 扫描、
 * PluginInstaller 安装启用。独立仓库分发的第三方插件同样以该规范为准。
 */
class PluginMakeCommand extends Command
{
    protected $signature = 'plugin:make
        {domain : 插件域：payment/verification/captcha/mail/sms/upstream/addons}
        {slug : 插件标识（snake_case，如 my_server）}
        {--name= : 插件显示名，缺省取 slug 的可读形式}
        {--provider : 生成 ServiceProvider 骨架并在 manifest 声明}
        {--task : 生成心跳定时任务骨架并在 manifest 声明}
        {--force : 目标目录已存在时覆盖}';

    protected $description = '生成符合插件包规范的插件骨架';

    public function handle(Filesystem $files): int
    {
        try {
            $domain = PluginDomain::assertValid(trim((string) $this->argument('domain')));
        } catch (InvalidArgumentException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $slug = $this->normalizeSlug((string) $this->argument('slug'));
        if ($slug === '') {
            $this->error('slug 必须是 snake_case（小写字母/数字/下划线，以字母开头），例如 my_server。');

            return self::FAILURE;
        }

        $studlyDomain = Str::studly($domain);
        $studlySlug = Str::studly($slug);
        $name = $this->resolveName((string) $this->option('name'), $slug);
        $withProvider = (bool) $this->option('provider');
        $withTask = (bool) $this->option('task');
        $domainDirectory = PluginDomain::directoryName($domain);
        $basePath = base_path('plugins'.DIRECTORY_SEPARATOR.$domainDirectory.DIRECTORY_SEPARATOR.$slug);

        if ($files->isDirectory($basePath) && ! (bool) $this->option('force')) {
            $this->error("插件目录已存在：{$basePath}（加 --force 覆盖）");

            return self::FAILURE;
        }

        $namespace = "TuraIDC\\Plugins\\{$studlyDomain}\\{$studlySlug}";
        $placeholders = [
            '{{domain}}' => $domain,
            '{{slug}}' => $slug,
            '{{name}}' => $name,
            '{{studlyDomain}}' => $studlyDomain,
            '{{studlySlug}}' => $studlySlug,
            '{{namespace}}' => $namespace,
            '{{domain_dir}}' => $domainDirectory,
        ];

        $this->writeStub($files, $basePath.DIRECTORY_SEPARATOR.'config.php', 'config.php.stub', $placeholders, [
            '{{use_statements}}' => $this->buildUseStatements($namespace, $studlySlug, $withProvider, $withTask),
            '{{provider_declaration}}' => $withProvider
                ? "        'provider' => {$studlySlug}ServiceProvider::class,\n"
                : '',
            '{{task_declaration}}' => $withTask
                ? "            'scheduled_tasks' => [\n                {$studlySlug}ScheduledTask::class,\n            ],\n"
                : '',
        ]);

        $this->writeStub($files, $basePath.DIRECTORY_SEPARATOR."{$studlySlug}Plugin.php", 'entry.php.stub', $placeholders);
        $this->writeStub($files, $basePath.DIRECTORY_SEPARATOR.'README.md', 'README.md.stub', $placeholders, [
            '{{provider_row}}' => $withProvider
                ? "| `src/Providers/{$studlySlug}ServiceProvider.php` | ServiceProvider（`--provider` 生成） |\n"
                : '',
            '{{task_row}}' => $withTask
                ? "| `lib/{$studlySlug}ScheduledTask.php` | 心跳定时任务（`--task` 生成） |\n"
                : '',
        ]);

        if ($withProvider) {
            $this->writeStub(
                $files,
                $basePath.DIRECTORY_SEPARATOR.'src'.DIRECTORY_SEPARATOR.'Providers'.DIRECTORY_SEPARATOR."{$studlySlug}ServiceProvider.php",
                'ServiceProvider.php.stub',
                $placeholders
            );
        }

        if ($withTask) {
            $this->writeStub(
                $files,
                $basePath.DIRECTORY_SEPARATOR.'lib'.DIRECTORY_SEPARATOR."{$studlySlug}ScheduledTask.php",
                'ScheduledTask.php.stub',
                $placeholders
            );
        }

        $this->info("插件骨架已生成：{$basePath}");
        $this->line('');
        $this->line('下一步：');
        $this->line('  1. 编辑 config.php 的 capabilities 与 config 配置项；');
        $this->line('  2. 在入口类中按 action 实现业务动作；');
        $this->line('  3. 安装并启用：管理端 → 插件中心，或 php artisan plugin:install 流程（见插件包规范）。');
        $this->line('  4. 独立仓库分发前，先阅读插件包规范中的打包与协议说明。');

        return self::SUCCESS;
    }

    private function normalizeSlug(string $slug): string
    {
        $normalized = trim($slug);
        if ($normalized === '' || preg_match('/^[a-z][a-z0-9]*(_[a-z0-9]+)*$/', $normalized) !== 1) {
            return '';
        }

        return $normalized;
    }

    private function resolveName(string $option, string $slug): string
    {
        $trimmed = trim($option);
        if ($trimmed !== '') {
            return $trimmed;
        }

        return Str::headline(str_replace('_', ' ', $slug));
    }

    /**
     * @return string 按需拼接的 use 语句块（含结尾换行），供 config.php 模板替换。
     */
    private function buildUseStatements(string $namespace, string $studlySlug, bool $withProvider, bool $withTask): string
    {
        $uses = ["use {$namespace}\\{$studlySlug}Plugin;"];

        if ($withTask) {
            $uses[] = "use {$namespace}\\Lib\\{$studlySlug}ScheduledTask;";
        }

        if ($withProvider) {
            $uses[] = "use {$namespace}\\Providers\\{$studlySlug}ServiceProvider;";
        }

        sort($uses);

        return implode("\n", $uses);
    }

    /**
     * @param  array<string, string>  $placeholders
     * @param  array<string, string>  $extraPlaceholders
     */
    private function writeStub(
        Filesystem $files,
        string $targetPath,
        string $stubName,
        array $placeholders,
        array $extraPlaceholders = [],
    ): void {
        $stubPath = base_path('stubs'.DIRECTORY_SEPARATOR.'plugin'.DIRECTORY_SEPARATOR.$stubName);
        $content = $files->get($stubPath);

        foreach (array_merge($placeholders, $extraPlaceholders) as $placeholder => $replacement) {
            $content = str_replace($placeholder, $replacement, $content);
        }

        $files->ensureDirectoryExists(dirname($targetPath));
        $files->put($targetPath, $content);
    }
}
