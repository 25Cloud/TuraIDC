<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Symfony\Component\Process\Process;

class ServeBackendStackCommand extends Command
{
    private const DEFAULT_INTERNAL_HTTP_HOST = '127.0.0.1';

    private const DEFAULT_INTERNAL_HTTP_PORT = 18000;

    private const DEFAULT_INTERNAL_RELAY_HOST = '127.0.0.1';

    private const DEFAULT_INTERNAL_RELAY_PORT = 8100;

    private const MAX_HEADER_BYTES = 65536;

    protected $signature = 'app:serve
        {--host=127.0.0.1 : 对外监听地址}
        {--port=8000 : 对外监听端口}
        {--internal-http-host=127.0.0.1 : Laravel 内部 HTTP 服务监听地址}
        {--internal-http-port=18000 : Laravel 内部 HTTP 服务监听端口}
        {--internal-relay-host=127.0.0.1 : VNC Relay 内部监听地址}
        {--internal-relay-port=8100 : VNC Relay 内部监听端口}
        {--without-queue : 不启动队列 Worker}
        {--with-schedule : 额外启动调度 Worker}';

    protected $description = '启动统一后端入口，对外代理 HTTP 与 VNC WebSocket';

    /** @var resource|null */
    private $server = null;

    /** @var array<int, array<string, mixed>> */
    private array $connections = [];

    private ?Process $httpProcess = null;

    private ?Process $relayProcess = null;

    private ?Process $queueProcess = null;

    private ?Process $scheduleProcess = null;

    public function handle(): int
    {
        $publicHost = trim((string) $this->option('host')) ?: '127.0.0.1';
        $publicPort = (int) $this->option('port');
        $internalHttpHost = trim((string) $this->option('internal-http-host')) ?: self::DEFAULT_INTERNAL_HTTP_HOST;
        $internalHttpPort = (int) $this->option('internal-http-port');
        $internalRelayHost = trim((string) $this->option('internal-relay-host')) ?: self::DEFAULT_INTERNAL_RELAY_HOST;
        $internalRelayPort = (int) $this->option('internal-relay-port');

        if ($publicHost === '' || $publicPort <= 0 || $publicPort > 65535) {
            $this->error('对外监听地址或端口无效。');

            return self::INVALID;
        }

        if ($internalHttpHost === '' || $internalHttpPort <= 0 || $internalHttpPort > 65535) {
            $this->error('内部 HTTP 地址或端口无效。');

            return self::INVALID;
        }

        if ($internalRelayHost === '' || $internalRelayPort <= 0 || $internalRelayPort > 65535) {
            $this->error('内部 Relay 地址或端口无效。');

            return self::INVALID;
        }

        if (
            $publicHost === $internalHttpHost
            && $publicPort === $internalHttpPort
        ) {
            $this->error('对外端口不能与内部 HTTP 端口相同。');

            return self::INVALID;
        }

        if (
            $publicHost === $internalRelayHost
            && $publicPort === $internalRelayPort
        ) {
            $this->error('对外端口不能与内部 Relay 端口相同。');

            return self::INVALID;
        }

        $this->startWorkers($internalHttpHost, $internalHttpPort, $internalRelayHost, $internalRelayPort);

        $gatewayEndpoint = sprintf('tcp://%s:%d', $publicHost, $publicPort);
        $errno = 0;
        $errstr = '';

        $this->server = @stream_socket_server($gatewayEndpoint, $errno, $errstr, STREAM_SERVER_BIND | STREAM_SERVER_LISTEN);
        if (! is_resource($this->server)) {
            $this->stopWorkers();
            $this->error(sprintf('统一入口启动失败：%s (%d)', $errstr ?: '未知错误', $errno));

            return self::FAILURE;
        }

        stream_set_blocking($this->server, false);

        $relayPath = $this->normalizeRelayPath((string) config('idc.vnc_relay.path', '/ws/vnc'));
        $this->info(sprintf('统一入口已启动：http://%s:%d', $publicHost, $publicPort));
        $this->info(sprintf('VNC WebSocket 入口：ws://%s:%d%s', $publicHost, $publicPort, $relayPath));
        $this->line($this->scheduleProcess instanceof Process
            ? '已同时托管 Laravel HTTP、VNC Relay、调度 Worker；队列由每分钟心跳消费。'
            : '已托管 Laravel HTTP、VNC Relay、队列 Worker；计划任务需独立运行。');
        $this->line('对外只需要这一组地址，内部 HTTP 与 Relay 端口不需要暴露。');
        $this->line('按 Ctrl+C 可同时停止整个后端栈。');

        try {
            while ($this->workersRunning()) {
                $this->flushWorkerOutputs();
                $this->pumpGateway();
            }

            $this->flushWorkerOutputs();
        } finally {
            $this->shutdownGateway();
            $this->stopWorkers();
        }

        if (! $this->httpProcess?->isSuccessful()) {
            $this->error('Laravel HTTP 服务已退出。');

            return self::FAILURE;
        }

        if (! $this->relayProcess?->isSuccessful()) {
            $this->error('VNC Relay 服务已退出。');

            return self::FAILURE;
        }

        if ($this->queueProcess instanceof Process && ! $this->queueProcess->isSuccessful()) {
            $this->error('队列 Worker 已退出。');

            return self::FAILURE;
        }

        if ($this->scheduleProcess instanceof Process && ! $this->scheduleProcess->isSuccessful()) {
            $this->error('调度 Worker 已退出。');

            return self::FAILURE;
        }

        return self::SUCCESS;
    }

    private function startWorkers(string $httpHost, int $httpPort, string $relayHost, int $relayPort): void
    {
        $phpBinary = PHP_BINARY;
        $artisan = base_path('artisan');
        $phpIniFile = $this->resolveLoadedPhpIniFile();
        $phpProcessArgs = $this->buildPhpProcessArgs($phpBinary, $phpIniFile);

        $this->httpProcess = new Process([
            ...$phpProcessArgs,
            '-S',
            sprintf('%s:%d', $httpHost, $httpPort),
            '-t',
            base_path('public'),
            base_path('vendor/laravel/framework/src/Illuminate/Foundation/resources/server.php'),
        ], base_path('public'));

        $this->relayProcess = new Process([
            ...$phpProcessArgs,
            $artisan,
            'vnc:relay',
            '--host='.$relayHost,
            '--port='.$relayPort,
        ], base_path());

        if (! (bool) $this->option('without-queue') && ! (bool) $this->option('with-schedule')) {
            $this->queueProcess = new Process([
                ...$phpProcessArgs,
                $artisan,
                'queue:work',
                '--queue='.(string) config('queue.EwyFinance_worker_queues', 'provision,referral,notification,coupon,default'),
                '--sleep=1',
                '--tries='.(string) config('queue.EwyFinance_worker_tries', 3),
                '--timeout='.(string) config('queue.EwyFinance_worker_timeout', 1200),
            ], base_path());
        }

        if ((bool) $this->option('with-schedule')) {
            $this->scheduleProcess = new Process([
                ...$phpProcessArgs,
                $artisan,
                'schedule:work',
            ], base_path());
        }

        foreach ($this->runningProcesses() as $process) {
            $process->setTimeout(null);
            $process->start();
        }

        register_shutdown_function(function () {
            $this->shutdownGateway();
            $this->stopWorkers();
        });
    }

    private function workersRunning(): bool
    {
        foreach ($this->runningProcesses() as $process) {
            if (! $process->isRunning()) {
                return false;
            }
        }

        return true;
    }

    private function flushWorkerOutputs(): void
    {
        $this->flushWorkerOutput('HTTP', $this->httpProcess);
        $this->flushWorkerOutput('Relay', $this->relayProcess);
        $this->flushWorkerOutput('Queue', $this->queueProcess);
        $this->flushWorkerOutput('Schedule', $this->scheduleProcess);
    }

    private function flushWorkerOutput(string $label, ?Process $process): void
    {
        if (! $process instanceof Process) {
            return;
        }

        $stdout = $process->getIncrementalOutput();
        if ($stdout !== '') {
            $this->printWorkerOutput($label, $stdout);
        }

        $stderr = $process->getIncrementalErrorOutput();
        if ($stderr !== '') {
            $this->printWorkerOutput($label, $stderr);
        }
    }

    private function printWorkerOutput(string $label, string $output): void
    {
        $lines = preg_split("/\r\n|\n|\r/", $output);
        if (! is_array($lines)) {
            return;
        }

        foreach ($lines as $line) {
            $line = rtrim((string) $line);
            if ($line === '') {
                continue;
            }

            $this->line(sprintf('[%s] %s', $label, $line));
        }
    }

    private function pumpGateway(): void
    {
        if (! is_resource($this->server)) {
            usleep(100000);

            return;
        }

        $read = [$this->server];
        $write = [];
        $except = [];
        $socketMap = [];

        foreach ($this->connections as $id => $connection) {
            if (is_resource($connection['client'] ?? null)) {
                $client = $connection['client'];
                $read[] = $client;
                $socketMap[(int) $client] = [$id, 'client'];

                if (($connection['client_out'] ?? '') !== '') {
                    $write[] = $client;
                }
            }

            if (is_resource($connection['upstream'] ?? null)) {
                $upstream = $connection['upstream'];
                $read[] = $upstream;
                $socketMap[(int) $upstream] = [$id, 'upstream'];

                if (($connection['upstream_out'] ?? '') !== '') {
                    $write[] = $upstream;
                }
            }
        }

        $ready = @stream_select($read, $write, $except, 0, 200000);
        if ($ready === false) {
            usleep(100000);

            return;
        }

        foreach ($read as $socket) {
            if ($socket === $this->server) {
                $this->acceptClient();

                continue;
            }

            $socketId = (int) $socket;
            if (! isset($socketMap[$socketId])) {
                continue;
            }

            [$connectionId, $side] = $socketMap[$socketId];
            $this->readSocket($connectionId, $side);
        }

        foreach ($write as $socket) {
            $socketId = (int) $socket;
            if (! isset($socketMap[$socketId])) {
                continue;
            }

            [$connectionId, $side] = $socketMap[$socketId];
            $this->flushSocketBuffer($connectionId, $side);
        }
    }

    private function acceptClient(): void
    {
        if (! is_resource($this->server)) {
            return;
        }

        $client = @stream_socket_accept($this->server, 0);
        if (! is_resource($client)) {
            return;
        }

        stream_set_blocking($client, false);

        $id = (int) $client;
        $this->connections[$id] = [
            'client' => $client,
            'upstream' => null,
            'state' => 'routing',
            'client_in' => '',
            'client_out' => '',
            'upstream_out' => '',
            'client_closed' => false,
            'upstream_closed' => false,
            'client_close_after_flush' => false,
        ];
    }

    private function readSocket(int $connectionId, string $side): void
    {
        $connection = $this->connections[$connectionId] ?? null;
        if (! is_array($connection)) {
            return;
        }

        $socket = $side === 'client' ? ($connection['client'] ?? null) : ($connection['upstream'] ?? null);
        if (! is_resource($socket)) {
            $this->closeConnection($connectionId);

            return;
        }

        $chunk = @fread($socket, 8192);
        $eof = feof($socket);

        if (! is_string($chunk)) {
            if ($eof) {
                $this->markSocketClosed($connectionId, $side);
            }

            return;
        }

        if ($chunk === '') {
            if ($eof) {
                $this->markSocketClosed($connectionId, $side);
            }

            return;
        }

        if ($side === 'client' && ($connection['state'] ?? 'routing') === 'routing') {
            $connection['client_in'] .= $chunk;

            if (strlen($connection['client_in']) > self::MAX_HEADER_BYTES) {
                $this->writeHttpError($connectionId, 431, 'Request Header Fields Too Large', '请求头过大');

                return;
            }

            $this->connections[$connectionId] = $connection;

            if (str_contains($connection['client_in'], "\r\n\r\n")) {
                $this->openUpstreamForConnection($connectionId);
            }

            if ($eof) {
                $this->markSocketClosed($connectionId, $side);
            }

            return;
        }

        $targetBufferKey = $side === 'client' ? 'upstream_out' : 'client_out';
        $connection[$targetBufferKey] .= $chunk;
        $this->connections[$connectionId] = $connection;

        if ($eof) {
            $this->markSocketClosed($connectionId, $side);
        }
    }

    private function openUpstreamForConnection(int $connectionId): void
    {
        $connection = $this->connections[$connectionId] ?? null;
        if (! is_array($connection)) {
            return;
        }

        $requestBuffer = (string) ($connection['client_in'] ?? '');
        [$targetHost, $targetPort] = $this->resolveUpstreamTarget($requestBuffer);

        if ($targetHost === '' || $targetPort <= 0) {
            $this->writeHttpError($connectionId, 400, 'Bad Request', '无法识别代理目标');

            return;
        }

        $errno = 0;
        $errstr = '';
        $targetEndpoint = sprintf('tcp://%s:%d', $targetHost, $targetPort);
        $upstream = @stream_socket_client($targetEndpoint, $errno, $errstr, 2);

        if (! is_resource($upstream)) {
            $this->writeHttpError($connectionId, 502, 'Bad Gateway', '后端代理目标不可用');

            return;
        }

        stream_set_blocking($upstream, false);

        $connection['upstream'] = $upstream;
        $connection['state'] = 'proxying';
        $connection['upstream_out'] .= $requestBuffer;
        $connection['client_in'] = '';
        $this->connections[$connectionId] = $connection;
    }

    /**
     * @return array{0:string,1:int}
     */
    private function resolveUpstreamTarget(string $requestBuffer): array
    {
        $requestLine = trim((string) strtok($requestBuffer, "\r\n"));
        if ($requestLine === '') {
            return ['', 0];
        }

        if (! preg_match('#^[A-Z]+\s+(\S+)\s+HTTP/1\.[01]$#', $requestLine, $matches)) {
            return ['', 0];
        }

        $target = (string) ($matches[1] ?? '');
        $parsed = parse_url($target);
        $path = (string) ($parsed['path'] ?? '/');
        $relayPath = $this->normalizeRelayPath((string) config('idc.vnc_relay.path', '/ws/vnc'));

        if ($this->matchesRelayPath($path, $relayPath)) {
            return [
                trim((string) $this->option('internal-relay-host')),
                (int) $this->option('internal-relay-port'),
            ];
        }

        return [
            trim((string) $this->option('internal-http-host')),
            (int) $this->option('internal-http-port'),
        ];
    }

    private function matchesRelayPath(string $requestPath, string $relayPath): bool
    {
        if ($requestPath === $relayPath) {
            return true;
        }

        return str_starts_with($requestPath, rtrim($relayPath, '/').'/');
    }

    private function normalizeRelayPath(string $path): string
    {
        $path = trim($path);
        if ($path === '') {
            return '/ws/vnc';
        }

        return str_starts_with($path, '/') ? $path : '/'.$path;
    }

    private function flushSocketBuffer(int $connectionId, string $side): void
    {
        $connection = $this->connections[$connectionId] ?? null;
        if (! is_array($connection)) {
            return;
        }

        $bufferKey = $side === 'client' ? 'client_out' : 'upstream_out';
        $socketKey = $side === 'client' ? 'client' : 'upstream';

        if (($connection[$bufferKey] ?? '') === '' || ! is_resource($connection[$socketKey] ?? null)) {
            $this->finalizeConnectionIfPossible($connectionId);

            return;
        }

        $written = @fwrite($connection[$socketKey], $connection[$bufferKey]);
        if ($written === false) {
            $this->closeConnection($connectionId);

            return;
        }

        if ($written > 0) {
            $connection[$bufferKey] = (string) substr($connection[$bufferKey], $written);
            $this->connections[$connectionId] = $connection;
        }

        $this->finalizeConnectionIfPossible($connectionId);
    }

    private function writeHttpError(int $connectionId, int $status, string $statusText, string $message): void
    {
        $connection = $this->connections[$connectionId] ?? null;
        if (! is_array($connection) || ! is_resource($connection['client'] ?? null)) {
            $this->closeConnection($connectionId);

            return;
        }

        $body = $message."\n";
        $response = implode("\r\n", [
            sprintf('HTTP/1.1 %d %s', $status, $statusText),
            'Content-Type: text/plain; charset=utf-8',
            'Content-Length: '.strlen($body),
            'Connection: close',
            '',
            $body,
        ]);

        $connection['client_out'] .= $response;
        $connection['client_close_after_flush'] = true;
        $connection['client_closed'] = true;
        $this->connections[$connectionId] = $connection;
    }

    private function markSocketClosed(int $connectionId, string $side): void
    {
        $connection = $this->connections[$connectionId] ?? null;
        if (! is_array($connection)) {
            return;
        }

        if ($side === 'client') {
            $connection['client_closed'] = true;
        } else {
            $connection['upstream_closed'] = true;
        }

        $this->connections[$connectionId] = $connection;
        $this->finalizeConnectionIfPossible($connectionId);
    }

    private function finalizeConnectionIfPossible(int $connectionId): void
    {
        $connection = $this->connections[$connectionId] ?? null;
        if (! is_array($connection)) {
            return;
        }

        $clientOut = (string) ($connection['client_out'] ?? '');
        $upstreamOut = (string) ($connection['upstream_out'] ?? '');

        if (($connection['client_close_after_flush'] ?? false) && $clientOut === '') {
            $this->closeConnection($connectionId);

            return;
        }

        if (($connection['upstream_closed'] ?? false) && $clientOut === '') {
            $this->closeConnection($connectionId);

            return;
        }

        if (($connection['client_closed'] ?? false) && $upstreamOut === '' && ! is_resource($connection['upstream'] ?? null)) {
            $this->closeConnection($connectionId);
        }
    }

    private function closeConnection(int $connectionId): void
    {
        $connection = $this->connections[$connectionId] ?? null;
        if (! is_array($connection)) {
            return;
        }

        foreach (['client', 'upstream'] as $socketKey) {
            if (is_resource($connection[$socketKey] ?? null)) {
                @fclose($connection[$socketKey]);
            }
        }

        unset($this->connections[$connectionId]);
    }

    private function shutdownGateway(): void
    {
        foreach (array_keys($this->connections) as $connectionId) {
            $this->closeConnection($connectionId);
        }

        if (is_resource($this->server)) {
            @fclose($this->server);
        }
    }

    private function stopWorkers(): void
    {
        foreach ($this->runningProcesses() as $process) {
            if ($process->isRunning()) {
                $process->stop(1);
            }
        }
    }

    /**
     * @return array<int, Process>
     */
    private function runningProcesses(): array
    {
        return array_values(array_filter([
            $this->httpProcess,
            $this->relayProcess,
            $this->queueProcess,
            $this->scheduleProcess,
        ], static fn ($process) => $process instanceof Process));
    }

    /**
     * @return array<int, string>
     */
    private function buildPhpProcessArgs(string $phpBinary, string $phpIniFile): array
    {
        $args = [$phpBinary];

        if ($phpIniFile !== '' && is_file($phpIniFile)) {
            $args[] = '-c';
            $args[] = $phpIniFile;
        }

        return $args;
    }

    private function resolveLoadedPhpIniFile(): string
    {
        $loadedFile = php_ini_loaded_file();
        if (is_string($loadedFile) && $loadedFile !== '') {
            return $loadedFile;
        }

        return '';
    }
}
