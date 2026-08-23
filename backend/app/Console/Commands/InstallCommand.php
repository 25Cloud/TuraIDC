<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Install\InstallException;
use App\Services\Install\InstallService;
use Illuminate\Console\Command;
use Throwable;

/**
 * 交互式安装命令：php artisan app:install
 *
 * 与 Web 向导（/install）共用 InstallService，行为完全一致。
 * 支持非交互模式（CI / 脚本）：显式传入全部必要选项。
 */
final class InstallCommand extends Command
{
    protected $signature = 'app:install
        {--app-url= : 后端 API 公开地址}
        {--frontend-url= : 官网门户地址}
        {--console-url= : 用户控制台地址}
        {--admin-url= : 管理端地址}
        {--db-host=127.0.0.1 : 数据库主机}
        {--db-port=3306 : 数据库端口}
        {--db-database= : 数据库名}
        {--db-username= : 数据库用户名}
        {--db-password= : 数据库密码}
        {--redis-host=127.0.0.1 : Redis 主机}
        {--redis-port=6379 : Redis 端口}
        {--redis-password= : Redis 密码}
        {--admin-username= : 管理员用户名}
        {--admin-email= : 管理员邮箱}
        {--admin-password= : 管理员密码（至少 12 位）}';

    protected $description = '交互式安装 TuraIDC（生成 .env、导入数据库、创建管理员）';

    public function handle(InstallService $installer): int
    {
        if ($installer->isInstalled()) {
            $this->components->error('系统已安装。如需重装，请先手动删除 storage/app/'.InstallService::LOCK_FILE.' 并清理数据库。');

            return self::FAILURE;
        }

        $this->info('=== TuraIDC 安装程序（CLI）===');
        $this->newLine();

        // 环境检测。
        $failed = [];
        $rows = [];
        foreach ($installer->requirements() as $item) {
            $rows[] = [$item['name'], $item['passed'] ? '通过' : '未通过', $item['message']];
            if ($item['required'] && ! $item['passed']) {
                $failed[] = $item['name'];
            }
        }
        $this->table(['检测项', '结果', '说明'], $rows);
        $this->newLine();

        if ($failed !== []) {
            $this->components->error('环境检测未通过：'.implode('；', $failed));

            return self::FAILURE;
        }

        try {
            $payload = $this->collectPayload($installer);
        } catch (Throwable $exception) {
            $this->components->error($exception->getMessage());

            return self::FAILURE;
        }

        if (! $this->confirm('确认使用以上配置开始安装？', true)) {
            $this->info('已取消安装。');

            return self::SUCCESS;
        }

        try {
            $result = $installer->install($payload, function (string $message): void {
                $this->line('[install] '.$message);
            });
        } catch (InstallException $exception) {
            $this->components->error('安装失败：'.$exception->getMessage());

            return self::FAILURE;
        }

        $this->newLine();
        $this->info('安装完成。');
        $this->line('管理员账号：'.$result['admin_username']);
        $this->line('管理员邮箱：'.$result['admin_email']);
        $this->line('请妥善保存以上凭据，并尽快访问管理端验证登录。');

        return self::SUCCESS;
    }

    /**
     * 收集安装参数：显式选项优先，缺失项进入交互问答。
     *
     * @return array<string, mixed>
     */
    private function collectPayload(InstallService $installer): array
    {
        $payload = [
            'app_name' => '图拉云',
            'app_url' => (string) $this->option('app-url'),
            'frontend_url' => (string) $this->option('frontend-url'),
            'client_console_url' => (string) $this->option('console-url'),
            'admin_url' => (string) $this->option('admin-url'),
            'db_host' => (string) $this->option('db-host'),
            'db_port' => (string) $this->option('db-port'),
            'db_database' => (string) $this->option('db-database'),
            'db_username' => (string) $this->option('db-username'),
            'db_password' => (string) $this->option('db-password'),
            'redis_host' => (string) $this->option('redis-host'),
            'redis_port' => (string) $this->option('redis-port'),
            'redis_password' => (string) $this->option('redis-password'),
            'admin_username' => (string) $this->option('admin-username'),
            'admin_email' => (string) $this->option('admin-email'),
            'admin_password' => (string) $this->option('admin-password'),
        ];

        $askUrl = function (string $key, string $label) use (&$payload): void {
            if ((string) $payload[$key] !== '') {
                return;
            }
            $payload[$key] = $this->ask($label.'（如 https://api.example.com）') ?? '';
        };

        $askUrl('app_url', '后端 API 公开地址');
        $askUrl('frontend_url', '官网门户地址');
        $askUrl('client_console_url', '用户控制台地址');
        $askUrl('admin_url', '管理端地址');

        if ((string) $payload['db_database'] === '') {
            $payload['db_database'] = $this->ask('数据库名') ?? '';
        }
        if ((string) $payload['db_username'] === '') {
            $payload['db_username'] = $this->ask('数据库用户名') ?? '';
        }
        if ($this->didntReceiveOption('db-password')) {
            $payload['db_password'] = (string) $this->secret('数据库密码（可为空）');
        }

        $test = $installer->testDatabase($payload);
        $this->line('数据库检测：'.$test['message']);
        if (! $test['ok']) {
            throw new InstallException($test['message']);
        }

        $redisTest = $installer->testRedis($payload);
        $this->line('Redis 检测：'.$redisTest['message']);
        if (! $redisTest['ok']) {
            throw new InstallException($redisTest['message']);
        }

        if ((string) $payload['admin_username'] === '') {
            $payload['admin_username'] = $this->ask('管理员用户名（字母开头，3-32 位）') ?? '';
        }
        if ((string) $payload['admin_email'] === '') {
            $payload['admin_email'] = $this->ask('管理员邮箱') ?? '';
        }
        if ($this->didntReceiveOption('admin-password')) {
            $payload['admin_password'] = (string) $this->secret('管理员密码（至少 '.InstallService::ADMIN_PASSWORD_MIN_LENGTH.' 位）');
        }

        return $installer->validatePayload($payload);
    }

    /**
     * 判断选项是否由命令行显式传入（区分“传了空值”与“未传”）。
     */
    private function didntReceiveOption(string $name): bool
    {
        return ! array_key_exists($name, $this->options()) || $this->option($name) === null;
    }
}
