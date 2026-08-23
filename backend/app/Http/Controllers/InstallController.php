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
 */
class InstallController extends Controller
{
    public function __construct(
        private readonly InstallService $installer,
    ) {}

    public function index()
    {
        abort_if($this->installer->isInstalled(), 404);

        return view('install.index');
    }

    public function requirements(): JsonResponse
    {
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

        return response()->json([
            'code' => 0,
            'data' => [
                'database' => $database,
                'redis' => $redis,
            ],
        ]);
    }

    public function run(Request $request): JsonResponse
    {
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
        return response()->json([
            'code' => 50000,
            'message' => $message,
            'data' => ['logs' => $logs],
        ], 422);
    }
}
