<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Support\AdminPermissions;
use Illuminate\Console\Command;
use ReflectionClass;
use Symfony\Component\Console\Command\Command as SymfonyCommand;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Throwable;

/**
 * 后台路由权限审计（只读，不改任何数据）。
 *
 * 背景：`routes/v2-admin.php` 的鉴权完全靠路由分组上挂的 `CheckPermission` 中间件
 * （`permission:` 别名），漏挂不会报错、不会 500，只会静默退化成「登录即可访问」。
 * 288 条路由里手写 `permission:` 的地方只有几十处，其余靠分组中间件覆盖，
 * 过去没有任何办法一眼确认「到底有没有漏」。
 *
 * 做法：读 `route:list --json` 的输出——那里的 `middleware` 是 Laravel **解析后**的
 * 中间件链（分组与别名都已展开成 FQCN + 参数，例如
 * `App\Http\Middleware\CheckPermission:invoice.list`），比逐个读路由文件里的分组更可靠。
 * 然后把 `api/v2/admin/` 下的每条路由归到三档：
 *
 *   1. `missing`     没有权限中间件（白名单之外）、或权限中间件没带码；
 *   2. `duplicates`  同一条路由挂了两个及以上**不同**权限码（语义含糊，必须人工裁决）；
 *   3. `unknown`     权限码不在权限表 `App\Support\AdminPermissions` 里。
 *
 * 权限表就是这个枚举类：仓库里没有 `permissions` 表（`App\Models\Permission` 模型没有任何
 * 引用点），角色权限存的是 JSON 数组，真正的码清单只在 `AdminPermissions` 的常量里。
 * 这里用反射读常量，避免再维护一份副本跟着漂移。
 *
 * 白名单只有登录与「管理员本人」的自服务端点：它们本来就只要求已登录，不该被权限点约束。
 * 用 method + uri 精确匹配而不是前缀匹配——前缀会把将来新增的同类路由一并「洗白」。
 *
 * 退出码：`missing` 或 `unknown` 非空返回 1，否则 0，可直接挂进 CI。
 * 用法：
 *   php artisan audit:admin-routes
 *   php artisan audit:admin-routes --json
 */
class AuditAdminRoutesCommand extends Command
{
    /** 受审计的 uri 前缀（route:list 的 uri 不带前导斜杠）。 */
    private const string ADMIN_URI_PREFIX = 'api/v2/admin/';

    /**
     * 视为「权限中间件」的中间件名（小写比较，已去掉 `:参数`）。
     *
     * 现行唯一实现是 `App\Http\Middleware\CheckPermission`（别名 `permission`）；
     * `can` / `authorize` 是 Laravel 原生的 Gate 与 Authorize 中间件，仓库里暂时没用，
     * 但将来用它做单点授权的路由不该被误报成「缺权限」。
     *
     * @var array<int, string>
     */
    private const array PERMISSION_MIDDLEWARE = [
        'app\http\middleware\checkpermission',
        'checkpermission',
        'permission',
        'can',
        'authorize',
        'illuminate\auth\middleware\authorize',
    ];

    /**
     * 免权限白名单：method + uri 精确匹配。
     *
     * - POST api/v2/admin/login：账号密码登录（未登录态）
     * - POST api/v2/admin/auth/logout：登出
     * - GET  api/v2/admin/auth/info：当前管理员信息
     * - PUT  api/v2/admin/auth/profile：修改本人资料
     * - PUT  api/v2/admin/auth/password：修改本人密码
     *
     * @var array<int, string>
     */
    private const array PERMISSION_FREE_ROUTES = [
        'POST api/v2/admin/login',
        'POST api/v2/admin/auth/logout',
        'GET api/v2/admin/auth/info',
        'PUT api/v2/admin/auth/profile',
        'PUT api/v2/admin/auth/password',
    ];

    protected $signature = 'audit:admin-routes
        {--json : 以 JSON 输出结果}';

    protected $description = '审计 api/v2/admin 路由的权限覆盖：缺权限、权限码重复、未知权限码（缺权限或未知码时退出码为 1）';

    public function handle(): int
    {
        try {
            $routes = $this->routeListJson();
        } catch (Throwable $exception) {
            $this->error('读取 route:list 失败：'.$exception->getMessage());

            return self::FAILURE;
        }

        $report = $this->buildReport($routes);

        if ((bool) $this->option('json')) {
            $this->line((string) json_encode(
                $report,
                JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT
            ));
        } else {
            $this->renderText($report);
        }

        return ($report['missing'] !== [] || $report['unknown'] !== []) ? self::FAILURE : self::SUCCESS;
    }

    /**
     * `route:list --json` 的解析结果。
     *
     * 刻意不写 `Artisan::call('route:list') + Artisan::output()`：那样会把控制台的
     * `lastOutput` 换成内层缓冲区，本命令自己的输出和调用方（测试/CI）拿到的输出都会错位。
     * 这里直接拿命令实例配一个自建的 BufferedOutput 跑一次，互不影响。
     *
     * @return array<int, array<string, mixed>>
     */
    private function routeListJson(): array
    {
        $command = $this->getApplication()?->find('route:list');
        if (! $command instanceof SymfonyCommand) {
            throw new \RuntimeException('找不到 route:list 命令');
        }

        $buffer = new BufferedOutput;
        $exitCode = $command->run(new ArrayInput(['--json' => true]), $buffer);
        if ($exitCode !== 0) {
            throw new \RuntimeException(sprintf('route:list 执行失败（退出码 %d）', $exitCode));
        }

        $decoded = json_decode(trim($buffer->fetch()), true);

        // route:list 在数据库不可达等情况下会把异常栈打到标准输出，这里必须挡住，
        // 否则下面会把「一段异常文本」当成空路由表，反过来报「审计通过」。
        if (! is_array($decoded)) {
            throw new \RuntimeException('route:list --json 的输出不是合法 JSON');
        }

        return array_values(array_filter($decoded, static fn (mixed $route): bool => is_array($route)));
    }

    /**
     * @param  array<int, array<string, mixed>>  $routes
     * @return array<string, mixed>
     */
    private function buildReport(array $routes): array
    {
        $registry = $this->permissionRegistry();

        $total = 0;
        $covered = 0;
        $whitelisted = 0;
        $whitelistedRoutes = [];
        $missing = [];
        $duplicates = [];
        $unknown = [];

        foreach ($routes as $route) {
            $uri = (string) ($route['uri'] ?? '');
            if (! str_starts_with($uri, self::ADMIN_URI_PREFIX)) {
                continue;
            }

            $total++;
            $methods = $this->methods($route);
            $action = (string) ($route['action'] ?? '');
            $method = $this->primaryMethod($methods);

            ['present' => $hasPermissionMiddleware, 'codes' => $codes, 'broken' => $broken] =
                $this->permissionMiddleware($route);

            if ($codes !== []) {
                $covered++;
            }

            // 缺权限：白名单之外没有任何权限中间件，或中间件没带码（等于永远放行）
            if (! $hasPermissionMiddleware || $broken) {
                if ($this->isWhitelisted($methods, $uri)) {
                    $whitelisted++;
                    $whitelistedRoutes[] = ['method' => $method, 'uri' => $uri];
                } else {
                    $missing[] = [
                        'method' => $method,
                        'uri' => $uri,
                        'action' => $action,
                        'reason' => $hasPermissionMiddleware ? 'permission_without_code' : 'no_permission_middleware',
                    ];
                }
            }

            if (count($codes) > 1) {
                $duplicates[] = ['method' => $method, 'uri' => $uri, 'codes' => $codes];
            }

            foreach ($codes as $code) {
                if (! in_array($code, $registry, true)) {
                    $unknown[] = ['method' => $method, 'uri' => $uri, 'code' => $code];
                }
            }
        }

        return [
            'dry_run' => true,
            'checked_at' => now()->toDateTimeString(),
            'total' => $total,
            'covered' => $covered,
            'whitelisted' => $whitelisted,
            'whitelisted_routes' => $whitelistedRoutes,
            'missing' => $missing,
            'duplicates' => $duplicates,
            'unknown' => $unknown,
        ];
    }

    /**
     * 解析一条路由的权限中间件。
     *
     * @param  array<string, mixed>  $route
     * @return array{present: bool, codes: array<int, string>, broken: bool}
     */
    private function permissionMiddleware(array $route): array
    {
        $present = false;
        $broken = false;
        $codes = [];

        foreach ((array) ($route['middleware'] ?? []) as $entry) {
            $middleware = (string) $entry;
            [$name, $params] = array_pad(explode(':', $middleware, 2), 2, '');

            if (! in_array(strtolower(trim($name)), self::PERMISSION_MIDDLEWARE, true)) {
                continue;
            }

            $present = true;
            $params = trim($params);
            if ($params === '') {
                $broken = true;

                continue;
            }

            // 我们自己的 CheckPermission 只吃一个码；逗号分支是防将来有人写成 permission:a,b
            foreach (explode(',', $params) as $code) {
                $code = trim($code);
                if ($code !== '' && ! in_array($code, $codes, true)) {
                    $codes[] = $code;
                }
            }
        }

        return ['present' => $present, 'codes' => $codes, 'broken' => $broken];
    }

    /**
     * 权限表：`AdminPermissions` 的全部常量值（排除超级权限 `*`）。
     *
     * @return array<int, string>
     */
    private function permissionRegistry(): array
    {
        $codes = [];

        foreach ((new ReflectionClass(AdminPermissions::class))->getConstants() as $code) {
            if (! is_string($code) || $code === AdminPermissions::ALL) {
                continue;
            }

            if (! in_array($code, $codes, true)) {
                $codes[] = $code;
            }
        }

        return $codes;
    }

    /**
     * @param  array<int, string>  $methods
     */
    private function isWhitelisted(array $methods, string $uri): bool
    {
        foreach ($methods as $method) {
            if (in_array($method.' '.$uri, self::PERMISSION_FREE_ROUTES, true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<string, mixed>  $route
     * @return array<int, string>
     */
    private function methods(array $route): array
    {
        $raw = (string) ($route['method'] ?? '');

        return array_values(array_filter(array_map(
            static fn (string $method): string => strtoupper(trim($method)),
            explode('|', $raw)
        ), static fn (string $method): bool => $method !== ''));
    }

    /**
     * 报告用主方法：跳过 HEAD/OPTIONS 这类自动附带的重复项。
     *
     * @param  array<int, string>  $methods
     */
    private function primaryMethod(array $methods): string
    {
        foreach ($methods as $method) {
            if ($method !== 'HEAD' && $method !== 'OPTIONS') {
                return $method;
            }
        }

        return $methods[0] ?? 'ANY';
    }

    /**
     * @param  array<string, mixed>  $report
     */
    private function renderText(array $report): void
    {
        $this->line(sprintf(
            '审计 %s 条 api/v2/admin 路由：已挂权限 %d 条，白名单 %d 条，缺权限 %d 条，重复权限码 %d 条，未知权限码 %d 条。',
            $report['total'],
            $report['covered'],
            $report['whitelisted'],
            count($report['missing']),
            count($report['duplicates']),
            count($report['unknown']),
        ));

        if ($report['missing'] !== []) {
            $this->error('缺权限（登录即可访问）：');
            foreach ($report['missing'] as $item) {
                $this->line(sprintf(
                    '- %s %s（%s）原因：%s',
                    $item['method'],
                    $item['uri'],
                    $item['action'],
                    $item['reason'],
                ));
            }
        }

        if ($report['duplicates'] !== []) {
            $this->warn('权限码重复（同一条路由挂了多个不同权限码）：');
            foreach ($report['duplicates'] as $item) {
                $this->line(sprintf('- %s %s：%s', $item['method'], $item['uri'], implode(', ', $item['codes'])));
            }
        }

        if ($report['unknown'] !== []) {
            $this->error('未知权限码（不在 App\Support\AdminPermissions 里）：');
            foreach ($report['unknown'] as $item) {
                $this->line(sprintf('- %s %s：%s', $item['method'], $item['uri'], $item['code']));
            }
        }

        if ($report['missing'] === [] && $report['unknown'] === []) {
            $this->info('审计通过：没有缺权限的 admin 路由，权限码也都在权限表内。');
        }

        if ($report['whitelisted_routes'] !== []) {
            $this->line('白名单（本人自服务端点，只要求已登录）：');
            foreach ($report['whitelisted_routes'] as $item) {
                $this->line(sprintf('- %s %s', $item['method'], $item['uri']));
            }
        }
    }
}
