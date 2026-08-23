<?php

declare(strict_types=1);

namespace App\Services\Install;

use App\Models\AdminUser;
use App\Models\Role;
use App\Services\Admin\Rbac\BuiltinAdminRoleService;
use Database\Seeders\SettingsSeeder;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use PDO;
use Redis;
use RedisException;
use RuntimeException;
use Throwable;

/**
 * 安装服务：Web 向导与 app:install 命令共用的唯一安装实现。
 *
 * 流程对齐 scripts/install_db.py：建库 → 导入 schema baseline → 增量迁移
 * → 同步内置角色 → 创建管理员 → 种入默认配置 → 写安装锁。
 * 全程进程内执行，不依赖 mysql 客户端与 Python。
 */
class InstallService
{
    /** 安装锁文件（存在即视为已安装） */
    public const LOCK_FILE = 'install.lock';

    /** schema baseline 真源 */
    private const SCHEMA_FILE = 'database/schema/mysql-schema.sql';

    /** .env 生成模板 */
    private const ENV_TEMPLATE = '.env.example';

    /** 管理员密码最小长度（对齐 install_db.py 生产环境要求） */
    public const ADMIN_PASSWORD_MIN_LENGTH = 12;

    /** 生成 .env 时需要替换的键（保持 .env.example 其余行原样） */
    private const REPLACEABLE_KEYS = [
        'APP_NAME', 'APP_ENV', 'APP_DEBUG', 'APP_URL',
        'FRONTEND_URL', 'CLIENT_CONSOLE_URL', 'ADMIN_URL',
        'DB_HOST', 'DB_PORT', 'DB_DATABASE', 'DB_USERNAME', 'DB_PASSWORD',
        'REDIS_HOST', 'REDIS_PORT', 'REDIS_PASSWORD',
        'TICKET_UPSTREAM_CALLBACK_SECRET',
    ];

    /**
     * 是否已安装：安装锁存在或已有管理员账号。
     */
    public function isInstalled(): bool
    {
        if (is_file($this->lockPath())) {
            return true;
        }

        // 锁文件丢失（如手动清理 storage）时以管理员表兜底，避免已装站点暴露向导。
        try {
            return Schema::hasTable((new AdminUser())->getTable())
                && AdminUser::query()->exists();
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * 环境检测项。
     *
     * @return list<array{name: string, required: bool, passed: bool, message: string}>
     */
    public function requirements(): array
    {
        $items = [
            ['name' => 'PHP 版本 >= 8.3', 'required' => true, 'passed' => PHP_VERSION_ID >= 80300, 'message' => '当前 '.PHP_VERSION],
            ['name' => 'PHP 扩展 pdo_mysql', 'required' => true, 'passed' => extension_loaded('pdo_mysql'), 'message' => ''],
            ['name' => 'PHP 扩展 redis', 'required' => true, 'passed' => extension_loaded('redis'), 'message' => ''],
            ['name' => 'PHP 扩展 openssl', 'required' => true, 'passed' => extension_loaded('openssl'), 'message' => ''],
            ['name' => 'PHP 扩展 mbstring', 'required' => true, 'passed' => extension_loaded('mbstring'), 'message' => ''],
            ['name' => 'PHP 扩展 fileinfo', 'required' => true, 'passed' => extension_loaded('fileinfo'), 'message' => ''],
            ['name' => 'PHP 扩展 json', 'required' => true, 'passed' => extension_loaded('json'), 'message' => ''],
            ['name' => 'Composer 依赖已安装（vendor/）', 'required' => true, 'passed' => is_file(base_path('vendor/autoload.php')), 'message' => ''],
            ['name' => 'schema baseline 存在', 'required' => true, 'passed' => is_file($this->schemaPath()), 'message' => ''],
            ['name' => '.env 模板存在', 'required' => true, 'passed' => is_file(base_path(self::ENV_TEMPLATE)), 'message' => ''],
            ['name' => 'storage/ 目录可写', 'required' => true, 'passed' => is_writable(storage_path()), 'message' => ''],
            ['name' => 'bootstrap/cache/ 目录可写', 'required' => true, 'passed' => is_writable(base_path('bootstrap/cache')), 'message' => ''],
            ['name' => '.env 可写（不存在或可写入）', 'required' => true, 'passed' => $this->envFileWritable(), 'message' => ''],
        ];

        foreach ($items as &$item) {
            $item['message'] = trim($item['message']);
        }

        return $items;
    }

    public function requirementsPassed(): bool
    {
        foreach ($this->requirements() as $item) {
            if ($item['required'] && ! $item['passed']) {
                return false;
            }
        }

        return true;
    }

    /**
     * 测试数据库连接（不写任何配置）。
     *
     * @param  array{host: string, port: int, database: string, username: string, password: string}  $config
     * @return array{ok: bool, message: string, database_exists: bool, database_empty: bool}
     */
    public function testDatabase(array $config): array
    {
        try {
            $pdo = $this->makePdo($config['host'], (int) $config['port'], '', $config['username'], $config['password']);
        } catch (Throwable $exception) {
            return ['ok' => false, 'message' => '数据库连接失败：'.$exception->getMessage(), 'database_exists' => false, 'database_empty' => false];
        }

        $database = trim((string) $config['database']);
        if ($database === '') {
            return ['ok' => false, 'message' => '数据库名不能为空', 'database_exists' => false, 'database_empty' => false];
        }

        $exists = $this->databaseExists($pdo, $database);
        $tableCount = 0;
        if ($exists) {
            $statement = $pdo->prepare('SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = ?');
            $statement->execute([$database]);
            $tableCount = (int) $statement->fetchColumn();
        }

        return [
            'ok' => true,
            'message' => $exists
                ? sprintf('连接成功，库已存在（%d 张表）', $tableCount)
                : '连接成功，库不存在（将自动创建）',
            'database_exists' => $exists,
            'database_empty' => $tableCount === 0,
        ];
    }

    /**
     * 测试 Redis 连接（不写任何配置）。
     *
     * @param  array{host: string, port: int, password: string}  $config
     * @return array{ok: bool, message: string}
     */
    public function testRedis(array $config): array
    {
        if (! extension_loaded('redis')) {
            return ['ok' => false, 'message' => 'PHP redis 扩展未安装'];
        }

        try {
            $redis = new Redis();
            $connected = $redis->connect($config['host'], (int) $config['port'], 3.0);
            if (! $connected) {
                return ['ok' => false, 'message' => 'Redis 连接失败'];
            }
            $password = trim((string) $config['password']);
            if ($password !== '' && ! $redis->auth($password)) {
                return ['ok' => false, 'message' => 'Redis 密码认证失败'];
            }
            $pong = (string) $redis->ping();
        } catch (RedisException|Throwable $exception) {
            return ['ok' => false, 'message' => 'Redis 连接失败：'.$exception->getMessage()];
        }

        return ['ok' => true, 'message' => 'Redis 连接正常（'.$pong.'）'];
    }

    /**
     * 执行安装主流程。
     *
     * @param  array<string, mixed>  $payload  已通过 validatePayload 校验的配置
     * @param  callable(string): void  $logger  步骤日志回调
     * @return array{admin_username: string, admin_email: string}
     *
     * @throws InstallException 安装失败（消息可直接展示给用户）
     */
    public function install(array $payload, callable $logger): array
    {
        if ($this->isInstalled()) {
            throw new InstallException('系统已安装，如需重装请先删除 '.$this->lockPath());
        }
        if (! $this->requirementsPassed()) {
            throw new InstallException('环境检测未通过，请先处理未满足的必选项');
        }

        // 写 .env 并让当前进程立即使用新配置（后续 migrate / 种子都依赖）。
        $logger('生成 .env 配置文件');
        $this->writeEnvironmentFile($payload);
        $this->refreshRuntimeConfig($payload);
        $appKey = $this->ensureAppKey();
        $this->refreshRuntimeConfig($payload, $appKey);

        // 数据库：确保库存在。
        $logger('连接数据库并确认目标库');
        $database = (string) $payload['db_database'];
        try {
            $adminPdo = $this->makePdo($payload['db_host'], (int) $payload['db_port'], '', $payload['db_username'], $payload['db_password']);
        } catch (Throwable $exception) {
            throw new InstallException('数据库连接失败：'.$exception->getMessage());
        }
        if (! $this->databaseExists($adminPdo, $database)) {
            $logger('创建数据库 '.$database);
            $adminPdo->exec(
                sprintf(
                    'CREATE DATABASE IF NOT EXISTS `%s` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci',
                    str_replace('`', '``', $database)
                )
            );
        }

        // 空库导入 schema baseline；非空库跳过（由增量迁移对齐）。
        try {
            $dbPdo = $this->makePdo($payload['db_host'], (int) $payload['db_port'], $database, $payload['db_username'], $payload['db_password']);
        } catch (Throwable $exception) {
            throw new InstallException('目标库连接失败：'.$exception->getMessage());
        }
        $statement = $dbPdo->prepare('SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = ?');
        $statement->execute([$database]);
        $tableCount = (int) $statement->fetchColumn();
        if ($tableCount === 0) {
            $logger('导入数据库结构（schema baseline）');
            $sql = file_get_contents($this->schemaPath());
            if ($sql === false || trim($sql) === '') {
                throw new InstallException('schema baseline 文件为空：'.$this->schemaPath());
            }
            $dbPdo->exec($sql);
        } else {
            $logger(sprintf('目标库已有 %d 张表，跳过 baseline 导入', $tableCount));
        }

        // 增量迁移（进程内 Artisan，配置已刷新）。
        $logger('执行数据库增量迁移');
        try {
            Artisan::call('migrate', ['--force' => true]);
            $output = trim(Artisan::output());
            if ($output !== '') {
                $logger($output);
            }
        } catch (Throwable $exception) {
            throw new InstallException('数据库迁移失败：'.$exception->getMessage());
        }

        // 默认配置与通知模板。
        $logger('写入系统默认配置与通知模板');
        try {
            SettingsSeeder::seed();
        } catch (Throwable $exception) {
            throw new InstallException('默认配置写入失败：'.$exception->getMessage());
        }

        // 管理员账号。
        $logger('创建管理员账号');
        $this->createAdmin(
            (string) $payload['admin_username'],
            (string) $payload['admin_password'],
            (string) $payload['admin_email']
        );

        $logger('清理缓存并写入安装锁');
        $this->clearCaches();
        $this->writeLock();

        Log::info('[install] 系统安装完成', ['database' => $database]);

        return [
            'admin_username' => (string) $payload['admin_username'],
            'admin_email' => (string) $payload['admin_email'],
        ];
    }

    /**
     * 校验安装参数，失败抛 InstallException。
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed> 规范化后的 payload
     */
    public function validatePayload(array $payload): array
    {
        $get = static fn (string $key, mixed $default = '') => is_string($payload[$key] ?? null) ? trim($payload[$key]) : $default;

        $normalized = [
            'app_name' => $get('app_name') ?: '图拉云',
            'app_url' => rtrim($get('app_url'), '/'),
            'frontend_url' => rtrim($get('frontend_url'), '/'),
            'client_console_url' => rtrim($get('client_console_url'), '/'),
            'admin_url' => rtrim($get('admin_url'), '/'),
            'db_host' => $get('db_host') ?: '127.0.0.1',
            'db_port' => (int) ($get('db_port') ?: 3306),
            'db_database' => $get('db_database'),
            'db_username' => $get('db_username'),
            'db_password' => (string) ($payload['db_password'] ?? ''),
            'redis_host' => $get('redis_host') ?: '127.0.0.1',
            'redis_port' => (int) ($get('redis_port') ?: 6379),
            'redis_password' => (string) ($payload['redis_password'] ?? ''),
            'admin_username' => $get('admin_username'),
            'admin_email' => $get('admin_email'),
            'admin_password' => (string) ($payload['admin_password'] ?? ''),
        ];

        foreach (['app_url', 'frontend_url', 'client_console_url', 'admin_url'] as $urlKey) {
            $value = (string) $normalized[$urlKey];
            if ($value === '' || ! filter_var($value, FILTER_VALIDATE_URL)) {
                throw new InstallException('地址配置无效：'.$urlKey.' 需为完整 URL（如 https://api.example.com）');
            }
        }

        foreach (['db_database' => '数据库名', 'db_username' => '数据库用户名'] as $key => $label) {
            if ((string) $normalized[$key] === '') {
                throw new InstallException($label.'不能为空');
            }
        }

        $username = (string) $normalized['admin_username'];
        if (! preg_match('/\A[a-zA-Z][a-zA-Z0-9_]{2,31}\z/', $username)) {
            throw new InstallException('管理员用户名需以字母开头，3-32 位字母数字下划线');
        }

        $email = (string) $normalized['admin_email'];
        if (! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new InstallException('管理员邮箱格式无效');
        }

        $password = (string) $normalized['admin_password'];
        if (strlen($password) < self::ADMIN_PASSWORD_MIN_LENGTH) {
            throw new InstallException('管理员密码长度不能少于 '.self::ADMIN_PASSWORD_MIN_LENGTH.' 位');
        }

        return $normalized;
    }

    /**
     * 基于 .env.example 生成 .env，仅替换白名单键，其余注释与默认值原样保留。
     *
     * @param  array<string, mixed>  $payload
     */
    private function writeEnvironmentFile(array $payload): void
    {
        $templatePath = base_path(self::ENV_TEMPLATE);
        $template = file_get_contents($templatePath);
        if ($template === false) {
            throw new InstallException('无法读取 .env 模板：'.$templatePath);
        }

        $values = [
            'APP_NAME' => (string) $payload['app_name'],
            'APP_ENV' => 'production',
            'APP_DEBUG' => 'false',
            'APP_URL' => (string) $payload['app_url'],
            'FRONTEND_URL' => (string) $payload['frontend_url'],
            'CLIENT_CONSOLE_URL' => (string) $payload['client_console_url'],
            'ADMIN_URL' => (string) $payload['admin_url'],
            'DB_HOST' => (string) $payload['db_host'],
            'DB_PORT' => (string) $payload['db_port'],
            'DB_DATABASE' => (string) $payload['db_database'],
            'DB_USERNAME' => (string) $payload['db_username'],
            'DB_PASSWORD' => (string) $payload['db_password'],
            'REDIS_HOST' => (string) $payload['redis_host'],
            'REDIS_PORT' => (string) $payload['redis_port'],
            'REDIS_PASSWORD' => (string) $payload['redis_password'],
            // 随机生成回调签名密钥，避免空密钥下上游回调验签失效。
            'TICKET_UPSTREAM_CALLBACK_SECRET' => Str::random(48),
        ];

        $replaced = [];
        $lines = preg_split('/\r\n|\r|\n/', $template) ?: [];
        foreach ($lines as &$line) {
            $trimmed = ltrim($line);
            if ($trimmed === '' || str_starts_with($trimmed, '#')) {
                continue;
            }
            $equalsPosition = strpos($trimmed, '=');
            if ($equalsPosition === false) {
                continue;
            }
            $key = substr($trimmed, 0, $equalsPosition);
            if (! in_array($key, self::REPLACEABLE_KEYS, true) || isset($replaced[$key])) {
                continue;
            }
            $indent = substr($line, 0, strlen($line) - strlen($trimmed));
            $line = $indent.$key.'='.$this->formatEnvValue((string) $values[$key]);
            $replaced[$key] = true;
        }
        unset($line);

        // 模板中不存在的白名单键（理论上不会发生）追加到文件末尾。
        foreach ($values as $key => $value) {
            if (! isset($replaced[$key])) {
                $lines[] = $key.'='.$this->formatEnvValue((string) $value);
            }
        }

        if (file_put_contents(base_path('.env'), implode(PHP_EOL, $lines).PHP_EOL) === false) {
            throw new InstallException('写入 .env 失败，请检查 backend 目录权限');
        }
    }

    private function formatEnvValue(string $value): string
    {
        if ($value === '') {
            return '';
        }
        // 含空格、#、引号或 $ 的值必须加双引号并转义，与 dotenv 解析规则一致。
        if (preg_match('/[\s#"\'$]/', $value) === 1) {
            return '"'.str_replace(['\\', '"'], ['\\\\', '\\"'], $value).'"';
        }

        return $value;
    }

    /**
     * 生成 APP_KEY 并写回 .env；返回生成值。
     */
    private function ensureAppKey(): string
    {
        $envPath = base_path('.env');
        $content = (string) file_get_contents($envPath);

        $currentKey = '';
        if (preg_match('/^APP_KEY=(.*)$/m', $content, $matches) === 1) {
            $currentKey = trim(trim($matches[1]), '"\'');
        }

        if (str_starts_with($currentKey, 'base64:')) {
            return $currentKey;
        }

        $key = 'base64:'.base64_encode(random_bytes(32));
        $updated = preg_match('/^APP_KEY=.*$/m', $content) === 1
            ? preg_replace('/^APP_KEY=.*$/m', 'APP_KEY='.$key, $content)
            : $content.PHP_EOL.'APP_KEY='.$key.PHP_EOL;

        if (file_put_contents($envPath, (string) $updated) === false) {
            throw new InstallException('写入 APP_KEY 失败');
        }

        return $key;
    }

    /**
     * 让当前进程立即应用新 .env 的关键连接配置（Artisan::call 不会重读 .env）。
     *
     * @param  array<string, mixed>  $payload
     */
    private function refreshRuntimeConfig(array $payload, ?string $appKey = null): void
    {
        config()->set('database.connections.mysql.host', (string) $payload['db_host']);
        config()->set('database.connections.mysql.port', (int) $payload['db_port']);
        config()->set('database.connections.mysql.database', (string) $payload['db_database']);
        config()->set('database.connections.mysql.username', (string) $payload['db_username']);
        config()->set('database.connections.mysql.password', (string) $payload['db_password']);

        $redisPassword = (string) $payload['redis_password'] !== '' ? (string) $payload['redis_password'] : null;
        // 项目使用 default / cache / volatile 三个 Redis 连接，全部指向同一实例。
        foreach (['default', 'cache', 'volatile'] as $connection) {
            config()->set('database.redis.'.$connection.'.host', (string) $payload['redis_host']);
            config()->set('database.redis.'.$connection.'.port', (int) $payload['redis_port']);
            config()->set('database.redis.'.$connection.'.password', $redisPassword);
        }

        if ($appKey !== null && $appKey !== '') {
            config()->set('app.key', $appKey);
        }

        DB::purge('mysql');
    }

    /**
     * 同步内置角色并创建超级管理员（对齐 install_db.py 的 bootstrap 逻辑）。
     */
    private function createAdmin(string $username, string $password, string $email): void
    {
        try {
            app(BuiltinAdminRoleService::class)->sync();

            $superAdminRole = Role::query()->where('name', 'super_admin')->first();
            if (! $superAdminRole instanceof Role) {
                throw new RuntimeException('super_admin 角色不存在');
            }

            $admin = AdminUser::query()->firstOrNew(['username' => $username]);
            if ($admin->exists) {
                throw new InstallException('管理员用户名已存在：'.$username);
            }

            $admin->forceFill([
                'role_id' => (int) $superAdminRole->id,
                'nickname' => '超级管理员',
                'email' => $email,
                'password' => $password,
                'status' => 1,
            ])->save();

            if (Schema::hasTable('admin_user_roles')) {
                DB::table('admin_user_roles')->updateOrInsert(
                    [
                        'admin_user_id' => (int) $admin->id,
                        'role_id' => (int) $superAdminRole->id,
                    ],
                    []
                );
            }
        } catch (InstallException $installException) {
            throw $installException;
        } catch (Throwable $exception) {
            throw new InstallException('管理员创建失败：'.$exception->getMessage());
        }
    }

    private function clearCaches(): void
    {
        foreach (['config:clear', 'cache:clear', 'route:clear', 'view:clear'] as $command) {
            try {
                Artisan::call($command);
            } catch (Throwable) {
                // 清缓存失败不影响安装结果。
            }
        }
    }

    private function writeLock(): void
    {
        $payload = json_encode([
            'installed_at' => now()->toIso8601String(),
            'version' => (string) config('app.version', ''),
        ], JSON_UNESCAPED_UNICODE);

        if (file_put_contents($this->lockPath(), (string) $payload.PHP_EOL) === false) {
            throw new InstallException('写入安装锁失败：'.$this->lockPath());
        }
    }

    private function lockPath(): string
    {
        return storage_path('app/'.self::LOCK_FILE);
    }

    private function schemaPath(): string
    {
        return base_path(self::SCHEMA_FILE);
    }

    private function envFileWritable(): bool
    {
        $envPath = base_path('.env');

        return ! is_file($envPath) || is_writable($envPath);
    }

    private function databaseExists(PDO $pdo, string $database): bool
    {
        $statement = $pdo->prepare('SELECT COUNT(*) FROM information_schema.schemata WHERE schema_name = ?');

        return $statement->execute([$database]) && (int) $statement->fetchColumn() > 0;
    }

    private function makePdo(string $host, int $port, string $database, string $username, string $password): PDO
    {
        $dsn = sprintf(
            'mysql:host=%s;port=%d%s;charset=utf8mb4',
            $host,
            $port,
            $database !== '' ? ';dbname='.$database : ''
        );

        return new PDO($dsn, $username, $password, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_TIMEOUT => 5,
        ]);
    }
}
