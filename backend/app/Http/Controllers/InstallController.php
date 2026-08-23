<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Services\Install\InstallException;
use App\Services\Install\InstallService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Throwable;

/**
 * Web 安装向导：GET /install。
 *
 * 已安装（安装锁或管理员已存在）后所有入口直接 404，防止向导被重放。
 * 安装期间不依赖 session/cookie，接口统一豁免 CSRF（见 bootstrap/app.php）。
 *
 * 部署级访问控制：必须携带与 env INSTALL_TOKEN 匹配的令牌（请求头
 * X-Install-Token 或 URL 参数 token）；未配置令牌或令牌不匹配一律 404，
 * 避免外部请求者抢先安装创建管理员。令牌不进入安装表单、不写入 .env。
 */
class InstallController extends Controller
{
    public function __construct(
        private readonly InstallService $installer,
    ) {}

    public function index(Request $request)
    {
        $this->assertInstallAccess();

        abort_if($this->installer->isInstalled(), 404);

        return view('install.index', [
            // 与 assertInstallAccess() 取值逻辑一致：header 优先，URL 参数兜底。
            'install_token' => (string) $request->header('X-Install-Token', (string) $request->query('token', '')),
        ]);
    }

    public function requirements(Request $request): JsonResponse
    {
        $this->assertInstallAccess();

        abort_if($this->installer->isInstalled(), 404);

        return response()->json([
            'code' => 0,
            'data' => [
                'items' => $this->installer->requirements(),
                'passed' => $this->installer->requirementsPassed(),
            ],
        ]);
    }

    public function test(Request $request): JsonResponse
    {
        $this->assertInstallAccess();

        abort_if($this->installer->isInstalled(), 404);

        $database = $this->installer->testDatabase([
            'host' => (string) $request->input('db_host', ''),
            'port' => (int) $request->input('db_port', 3306),
            'database' => (string) $request->input('db_database', ''),
            'username' => (string) $request->input('db_username', ''),
            'password' => (string) $request->input('db_password', ''),
        ]);

        $redis = $this->installer->testRedis([
            'host' => (string) $request->input('redis_host', ''),
            'port' => (int) $request->input('redis_port', 6379),
            'password' => (string) $request->input('redis_password', ''),
        ]);

        // 失败时对外只返回固定文案，避免未认证探测端口的开放/拒绝差异；
        // 详细异常由 InstallService 内部以 debug 级别记录。
        return response()->json([
            'code' => 0,
            'data' => [
                'database' => $database['ok']
                    ? $database
                    : ['ok' => false, 'message' => '数据库连接失败，请检查配置', 'database_exists' => false, 'database_empty' => false],
                'redis' => $redis['ok']
                    ? $redis
                    : ['ok' => false, 'message' => 'Redis 连接失败，请检查配置'],
            ],
        ]);
    }

    public function run(Request $request): JsonResponse
    {
        $this->assertInstallAccess();

        abort_if($this->installer->isInstalled(), 404);

        try {
            $payload = $this->installer->validatePayload($request->all());
        } catch (InstallException $exception) {
            return $this->installError($exception->getMessage());
        }

        // 安装包含 schema 导入与迁移，放宽执行时限（FPM 层超时需运维侧放宽）。
        if (function_exists('set_time_limit')) {
            @set_time_limit(0);
        }

        $logs = [];
        try {
            $result = $this->installer->install($payload, static function (string $message) use (&$logs): void {
                $logs[] = $message;
            });
        } catch (InstallException $exception) {
            return $this->installError($exception->getMessage(), $logs);
        } catch (Throwable $exception) {
            report($exception);

            return $this->installError('安装出现意外错误：'.$exception->getMessage(), $logs);
        }

        return response()->json([
            'code' => 0,
            'data' => [
                'logs' => $logs,
                'admin_username' => $result['admin_username'],
                'admin_email' => $result['admin_email'],
                'admin_url' => $payload['admin_url'],
            ],
        ]);
    }

    /**
     * @param  list<string>  $logs
     */
    private function installError(string $message, array $logs = []): JsonResponse
    {
        // 安装期依赖（DB/Redis/.env）未就绪，ApiResponseBuilder 可能不可用，故手写统一结构。
        return response()->json([
            'code' => 50000,
            'message' => $message,
            'data' => ['logs' => $logs],
        ], 422);
    }

    /**
     * 部署级访问控制：校验安装令牌（header X-Install-Token 或 URL 参数 token）。
     * 未配置 INSTALL_TOKEN 或令牌不匹配一律 404，避免暴露安装入口存在性，
     * 防止外部请求者抢先安装创建管理员。
     */
    private function assertInstallAccess(): void
    {
        $expectedToken = trim((string) config('install.token'));
        $providedToken = trim((string) request()->header('X-Install-Token', (string) request()->query('token', '')));

        if ($expectedToken === '' || ! hash_equals($expectedToken, $providedToken)) {
            abort(404);
        }
    }
}
