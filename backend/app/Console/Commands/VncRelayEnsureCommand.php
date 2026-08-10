<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * 确保 VNC Relay 进程在运行。
 *
 * 设计意图：
 *   - 由 schedule:run 每 15 分钟调用一次
 *   - 检测目标端口是否有进程监听
 *   - 如果没有，以后台方式拉起 vnc:relay
 *   - 命令本身立即返回，不阻塞调度器
 */
class VncRelayEnsureCommand extends Command
{
    protected $signature = 'vnc:ensure-relay
        {--host= : Relay 监听地址，默认读取 VNC_RELAY_HOST}
        {--port= : Relay 监听端口，默认读取 VNC_RELAY_PORT}';

    protected $description = '检测并自动拉起 VNC WebSocket 中转服务（适合放入 schedule:run）';

    public function handle(): int
    {
        $host = trim((string) ($this->option('host') ?: config('idc.vnc_relay.host', '127.0.0.1')));
        $port = (int) ($this->option('port') ?: config('idc.vnc_relay.port', 8100));

        if ($host === '' || $port <= 0 || $port > 65535) {
            $this->error('VNC Relay 参数无效');

            return self::INVALID;
        }

        if ($this->isPortListening($host, $port)) {
            $this->line(sprintf('VNC Relay 已在运行 (%s:%d)，跳过', $host, $port));

            return self::SUCCESS;
        }

        $this->info(sprintf('VNC Relay 未运行，正在拉起 (%s:%d)...', $host, $port));

        try {
            $this->spawnRelay($host, $port);
            $this->info('VNC Relay 后台进程已启动');
            Log::info('[VNC Relay] 由 ensure-relay 自动拉起', compact('host', 'port'));
        } catch (Throwable $e) {
            $this->error('启动失败：'.$e->getMessage());
            Log::error('[VNC Relay] ensure-relay 启动失败', [
                'host' => $host,
                'port' => $port,
                'error' => $e->getMessage(),
            ]);

            return self::FAILURE;
        }

        return self::SUCCESS;
    }

    private function isPortListening(string $host, int $port): bool
    {
        $socket = @fsockopen($host, $port, $errno, $errstr, 2);
        if (is_resource($socket)) {
            fclose($socket);

            return true;
        }

        return false;
    }

    private function spawnRelay(string $host, int $port): void
    {
        $phpBinary = PHP_BINARY ?: 'php';
        $artisan = base_path('artisan');
        $logFile = storage_path('logs/vnc-relay.log');

        if (PHP_OS_FAMILY === 'Windows') {
            $cmd = sprintf(
                'start /B "" %s %s vnc:relay --host=%s --port=%d >> %s 2>&1',
                escapeshellarg($phpBinary),
                escapeshellarg($artisan),
                escapeshellarg($host),
                $port,
                escapeshellarg($logFile),
            );
            \pclose(\popen($cmd, 'r'));
        } else {
            $cmd = sprintf(
                'nohup %s %s vnc:relay --host=%s --port=%d >> %s 2>&1 &',
                escapeshellarg($phpBinary),
                escapeshellarg($artisan),
                escapeshellarg($host),
                $port,
                escapeshellarg($logFile),
            );
            exec($cmd);
        }
    }
}
