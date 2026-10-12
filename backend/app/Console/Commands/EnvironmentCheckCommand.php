<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Support\EnvironmentRequirements;
use Illuminate\Console\Command;

/**
 * 运行环境自检。
 *
 * 与 app:install 共用 EnvironmentRequirements 这份清单，但不受安装向导的
 * isInstalled 限制：站点装完之后体检入口会 404 消失，而升级 PHP、改 php.ini
 * 或换机器都可能让扩展缺失 / 禁用函数被加上，只有这个命令能随时复核。
 */
class EnvironmentCheckCommand extends Command
{
    protected $signature = 'app:doctor {--all : 同时列出建议安装但不阻断的扩展}';

    protected $description = '检查 PHP 版本、扩展与禁用函数是否满足运行要求（安装前后均可执行）';

    public function handle(): int
    {
        $showOptional = (bool) $this->option('all');
        $items = EnvironmentRequirements::inspect();

        $this->info('=== TuraIDC 运行环境自检 ===');
        $this->line('PHP 版本：'.PHP_VERSION.'  SAPI：'.PHP_SAPI);
        $this->newLine();

        $rows = [];
        $failed = [];

        foreach ($items as $item) {
            if (! $item['required'] && ! $showOptional) {
                continue;
            }

            $rows[] = [$item['name'], $item['passed'] ? '通过' : '未通过', $item['message']];

            if ($item['required'] && ! $item['passed']) {
                $failed[] = $item['name'];
            }
        }

        $this->table(['检测项', '结果', '说明'], $rows);
        $this->newLine();

        $disabled = EnvironmentRequirements::disabledFunctions();
        if ($disabled !== []) {
            $this->line('当前 disable_functions：'.implode(', ', $disabled));
            $this->newLine();
        }

        if ($failed !== []) {
            $this->components->error('环境检测未通过：'.implode('；', $failed));

            return self::FAILURE;
        }

        $this->components->info('环境检测通过');

        return self::SUCCESS;
    }
}
