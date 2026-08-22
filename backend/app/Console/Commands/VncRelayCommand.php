<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\ClientServiceConsole\ClientServiceConsoleService;
use App\Support\PublicUrl;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Throwable;

class VncRelayCommand extends Command
{
    protected $signature = 'vnc:relay
        {--host= : 监听地址，默认读取 VNC_RELAY_HOST}
        {--port= : 监听端口，默认读取 VNC_RELAY_PORT}';

    protected $description = '启动 VNC WebSocket 中转服务，浏览器仅连接本站域名';

    /** @var resource|null */
    private $server = null;

    /** @var array<int, array<string, mixed>> */
    private array $connections = [];

    private bool $running = true;

    /** 防 DoS 上限：并发连接、握手时限、空闲时限、单侧缓冲上限。 */
    private const MAX_CONNECTIONS = 512;

    private const HANDSHAKE_TIMEOUT_SECONDS = 15;

    private const IDLE_TIMEOUT_SECONDS = 300;

    private const MAX_BUFFER_BYTES = 1048576;

    public function handle(ClientServiceConsoleService $consoleService): int
    {
        set_time_limit(0);

        $host = trim((string) ($this->option('host') ?: config('idc.vnc_relay.host', '127.0.0.1')));
        $port = (int) ($this->option('port') ?: config('idc.vnc_relay.port', 8100));

        if ($host === '' || $port <= 0 || $port > 65535) {
            $this->error('VNC Relay 启动失败：监听地址或端口无效。');

            return self::INVALID;
        }

        $endpoint = sprintf('tcp://%s:%d', $host, $port);
        $errno = 0;
        $errstr = '';

        $this->server = @stream_socket_server($endpoint, $errno, $errstr, STREAM_SERVER_BIND | STREAM_SERVER_LISTEN);

        if (! is_resource($this->server)) {
            $this->error(sprintf('VNC Relay 启动失败：%s (%d)', $errstr ?: '未知错误', $errno));

            return self::FAILURE;
        }

        stream_set_blocking($this->server, false);

        $this->info(sprintf('VNC Relay 已启动：%s', $endpoint));
        $this->line(sprintf('前端代理路径：%s', (string) config('idc.vnc_relay.path', '/ws/vnc')));

        while ($this->running) {
            $this->reapStaleConnections();

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

            $ready = @stream_select($read, $write, $except, 1);
            if ($ready === false) {
                usleep(100000);

                continue;
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

                if (! isset($this->connections[$connectionId])) {
                    continue;
                }

                try {
                    if ($side === 'client') {
                        $this->readClientSocket($connectionId, $consoleService);
                    } else {
                        $this->readUpstreamSocket($connectionId);
                    }
                } catch (Throwable $e) {
                    $this->closeConnection($connectionId);
                }
            }

            foreach ($write as $socket) {
                $socketId = (int) $socket;
                if (! isset($socketMap[$socketId])) {
                    continue;
                }

                [$connectionId, $side] = $socketMap[$socketId];

                if (! isset($this->connections[$connectionId])) {
                    continue;
                }

                try {
                    $this->writeSocketBuffer($connectionId, $side);
                } catch (Throwable $e) {
                    $this->closeConnection($connectionId);
                }
            }
        }

        $this->shutdownServer();

        return self::SUCCESS;
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

        if (count($this->connections) >= self::MAX_CONNECTIONS) {
            @fclose($client);

            return;
        }

        stream_set_blocking($client, false);

        $now = time();
        $id = (int) $client;
        $this->connections[$id] = [
            'client' => $client,
            'client_in' => '',
            'client_out' => '',
            'upstream' => null,
            'upstream_in' => '',
            'upstream_out' => '',
            'handshake_done' => false,
            'token' => '',
            'created_at' => $now,
            'last_activity_at' => $now,
        ];
    }

    /**
     * 每轮事件循环清理：握手超时、空闲超时、缓冲超限的连接，
     * 防止半截请求头/不读数据的客户端耗尽 FD 与内存。
     */
    private function reapStaleConnections(): void
    {
        $now = time();

        foreach ($this->connections as $id => $connection) {
            if (! is_resource($connection['client'] ?? null)) {
                $this->closeConnection($id);

                continue;
            }

            // 任一侧转发缓冲超限：视为异常/滥用连接，关闭。
            foreach (['client_in', 'client_out', 'upstream_in', 'upstream_out'] as $bufferKey) {
                if (strlen((string) ($connection[$bufferKey] ?? '')) > self::MAX_BUFFER_BYTES) {
                    $this->closeConnection($id);

                    continue 2;
                }
            }

            $createdAt = (int) ($connection['created_at'] ?? $now);
            $lastActivity = (int) ($connection['last_activity_at'] ?? $createdAt);

            // 握手超时：客户端连接后未在时限内完成 WebSocket 握手。
            if (! ($connection['handshake_done'] ?? false) && ($now - $createdAt) > self::HANDSHAKE_TIMEOUT_SECONDS) {
                $this->closeConnection($id);

                continue;
            }

            // 空闲超时：已建立的双向连接长时间无活动。
            if (($now - $lastActivity) > self::IDLE_TIMEOUT_SECONDS) {
                $this->closeConnection($id);
            }
        }
    }

    private function readClientSocket(int $connectionId, ClientServiceConsoleService $consoleService): void
    {
        $connection = $this->connections[$connectionId] ?? null;
        if (! is_array($connection) || ! is_resource($connection['client'] ?? null)) {
            $this->closeConnection($connectionId);

            return;
        }

        $chunk = @fread($connection['client'], 8192);
        if (($chunk === false || $chunk === '') && feof($connection['client'])) {
            $this->closeConnection($connectionId);

            return;
        }

        if (! is_string($chunk) || $chunk === '') {
            return;
        }

        $connection['client_in'] .= $chunk;
        $connection['last_activity_at'] = time();
        $this->connections[$connectionId] = $connection;

        if (! ($connection['handshake_done'] ?? false)) {
            $this->tryHandshake($connectionId, $consoleService);

            return;
        }

        $this->forwardClientFrames($connectionId);
    }

    private function readUpstreamSocket(int $connectionId): void
    {
        $connection = $this->connections[$connectionId] ?? null;
        if (! is_array($connection) || ! is_resource($connection['upstream'] ?? null)) {
            $this->closeConnection($connectionId);

            return;
        }

        $chunk = @fread($connection['upstream'], 8192);
        if (($chunk === false || $chunk === '') && feof($connection['upstream'])) {
            $this->closeConnection($connectionId);

            return;
        }

        if (! is_string($chunk) || $chunk === '') {
            return;
        }

        $connection['upstream_in'] .= $chunk;
        $connection['last_activity_at'] = time();
        $this->connections[$connectionId] = $connection;

        $this->forwardUpstreamFrames($connectionId);
    }

    private function writeSocketBuffer(int $connectionId, string $side): void
    {
        $connection = $this->connections[$connectionId] ?? null;
        if (! is_array($connection)) {
            return;
        }

        $bufferKey = $side === 'client' ? 'client_out' : 'upstream_out';
        $socketKey = $side === 'client' ? 'client' : 'upstream';

        if (($connection[$bufferKey] ?? '') === '' || ! is_resource($connection[$socketKey] ?? null)) {
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
    }

    private function tryHandshake(int $connectionId, ClientServiceConsoleService $consoleService): void
    {
        $connection = $this->connections[$connectionId] ?? null;
        if (! is_array($connection)) {
            return;
        }

        if (! str_contains($connection['client_in'], "\r\n\r\n")) {
            return;
        }

        [$headerBlock, $extra] = explode("\r\n\r\n", $connection['client_in'], 2);
        $request = $this->parseClientHandshake($headerBlock);

        if ($request === null) {
            $this->writeHttpError($connection['client'], 400, 'Bad Request', 'WebSocket 握手格式无效');
            $this->closeConnection($connectionId);

            return;
        }

        $headers = $request['headers'];
        $wsKey = trim((string) ($headers['sec-websocket-key'] ?? ''));
        $token = trim((string) ($request['query']['token'] ?? ''));
        $clientOrigin = trim((string) ($headers['origin'] ?? ''));

        if ($wsKey === '' || $token === '') {
            $this->writeHttpError($connection['client'], 400, 'Bad Request', '缺少 token 或 WebSocket 握手头');
            $this->closeConnection($connectionId);

            return;
        }

        $params = [];

        try {
            $params = $this->resolveVncTokenForClient($consoleService, $token, $clientOrigin);
            if ($params === null) {
                Log::warning('[VNC Relay] 浏览器 Origin 未获授权', [
                    'token' => $this->maskToken($token),
                    'origin' => $clientOrigin,
                ]);
                $this->writeHttpError($connection['client'], 403, 'Forbidden', 'VNC 请求来源未获授权');
                $this->closeConnection($connectionId);

                return;
            }

            [$upstream, $upstreamExtra] = $this->connectUpstream($params);
        } catch (Throwable $e) {
            Log::warning('[VNC Relay] 上游连接失败', [
                'token' => $this->maskToken($token),
                'message' => $e->getMessage(),
                'exception' => $e::class,
                'params' => [
                    'host' => $params['host'] ?? '',
                    'port' => $params['port'] ?? 0,
                    'path' => $this->maskUpstreamPath((string) ($params['path'] ?? '')),
                    'encrypt' => $params['encrypt'] ?? '0',
                    'origin' => $params['origin'] ?? '',
                ],
            ]);
            $this->writeHttpError($connection['client'], 502, 'Bad Gateway', 'VNC 中转连接上游失败');
            $this->closeConnection($connectionId);

            return;
        }

        $connection['client_in'] = $extra;
        $connection['upstream'] = $upstream;
        $connection['upstream_in'] = $upstreamExtra;
        $connection['handshake_done'] = true;
        $connection['token'] = $token;
        $connection['client_out'] .= $this->buildClientHandshakeResponse($wsKey);
        $this->connections[$connectionId] = $connection;

        if ($extra !== '') {
            $this->forwardClientFrames($connectionId);
        }

        if ($upstreamExtra !== '') {
            $this->forwardUpstreamFrames($connectionId);
        }
    }

    /**
     * @return array{method:string,target:string,headers:array<string,string>,query:array<string,string>}|null
     */
    private function parseClientHandshake(string $headerBlock): ?array
    {
        $lines = preg_split("/\r\n/", trim($headerBlock));
        if (! is_array($lines) || count($lines) === 0) {
            return null;
        }

        $requestLine = trim((string) array_shift($lines));
        if (! preg_match('#^(GET)\s+(\S+)\s+HTTP/1\.[01]$#i', $requestLine, $matches)) {
            return null;
        }

        $target = trim((string) ($matches[2] ?? ''));
        $headers = [];

        foreach ($lines as $line) {
            $line = trim((string) $line);
            if ($line === '' || ! str_contains($line, ':')) {
                continue;
            }

            [$name, $value] = explode(':', $line, 2);
            $headers[strtolower(trim($name))] = trim($value);
        }

        $query = [];
        $parsed = parse_url($target);
        if (is_array($parsed) && isset($parsed['query'])) {
            parse_str((string) $parsed['query'], $query);
        }

        return [
            'method' => 'GET',
            'target' => $target,
            'headers' => $headers,
            'query' => array_map(
                static fn ($value) => is_scalar($value) ? (string) $value : '',
                $query
            ),
        ];
    }

    /**
     * @param  array<string, mixed>  $params
     * @return array{0: resource, 1: string}
     */
    private function connectUpstream(array $params): array
    {
        $host = trim((string) ($params['host'] ?? ''));
        $port = (int) ($params['port'] ?? 0);
        $path = ltrim(trim((string) ($params['path'] ?? '')), '/');
        $secure = (string) ($params['encrypt'] ?? '0') === '1';

        if ($host === '' || $port <= 0) {
            throw new \RuntimeException('上游 VNC 参数无效');
        }

        // SSRF 纵深防护：拒绝内网/保留地址，避免上游或 Redis 受控时把 relay 变成内网 TCP 桥。
        $this->assertPublicUpstreamTarget($host);

        $timeout = max(1, (int) config('idc.vnc_relay.connect_timeout', 10));
        $contextOptions = [];

        if ($secure) {
            $verify = (bool) config('idc.vnc_relay.ssl_verify', config('app.env') !== 'local');
            $contextOptions['ssl'] = [
                'verify_peer' => $verify,
                'verify_peer_name' => $verify,
                'allow_self_signed' => ! $verify,
                'SNI_enabled' => true,
                'peer_name' => $host,
            ];

            $caBundle = trim((string) config('idc.vnc_relay.ca_bundle', ''));
            if ($caBundle !== '') {
                $contextOptions['ssl']['cafile'] = $caBundle;
            }
        }

        $context = stream_context_create($contextOptions);
        $transport = $secure ? 'tls' : 'tcp';
        $address = sprintf('%s://%s:%d', $transport, $host, $port);
        $errno = 0;
        $errstr = '';

        $socket = @stream_socket_client($address, $errno, $errstr, $timeout, STREAM_CLIENT_CONNECT, $context);
        if (! is_resource($socket)) {
            throw new \RuntimeException($errstr ?: '连接上游失败');
        }

        stream_set_blocking($socket, true);
        stream_set_timeout($socket, $timeout);

        $requestPath = $path === '' ? '/' : '/'.$path;
        $hostHeader = $host;
        if ((! $secure && $port !== 80) || ($secure && $port !== 443)) {
            $hostHeader .= ':'.$port;
        }

        $origin = $this->resolveOriginHeader($params);
        $handshake = [
            sprintf('GET %s HTTP/1.1', $requestPath),
            'Host: '.$hostHeader,
            'Upgrade: websocket',
            'Connection: Upgrade',
            'Sec-WebSocket-Version: 13',
            'Sec-WebSocket-Key: '.base64_encode(random_bytes(16)),
        ];

        if ($origin !== '') {
            $handshake[] = 'Origin: '.$origin;
        }

        $payload = implode("\r\n", $handshake)."\r\n\r\n";
        $writeResult = @fwrite($socket, $payload);
        if ($writeResult === false || $writeResult < strlen($payload)) {
            @fclose($socket);
            throw new \RuntimeException('上游握手请求发送失败');
        }

        $response = '';
        while (! str_contains($response, "\r\n\r\n")) {
            $chunk = @fread($socket, 8192);
            if ($chunk === false || $chunk === '') {
                $meta = stream_get_meta_data($socket);
                @fclose($socket);
                throw new \RuntimeException(($meta['timed_out'] ?? false) ? '上游握手超时' : '上游握手响应为空');
            }

            $response .= $chunk;
        }

        [$headerBlock, $extra] = explode("\r\n\r\n", $response, 2);
        if (! preg_match('#^HTTP/1\.[01]\s+101\b#i', $headerBlock)) {
            @fclose($socket);
            throw new \RuntimeException('上游拒绝 WebSocket 握手');
        }

        stream_set_blocking($socket, false);

        return [$socket, $extra];
    }

    /**
     * SSRF 纵深防护：拒绝把中继导向内网/保留地址。
     *
     * 目标 host 虽来自上游 VNC 地址或缓存连接数据（非用户直接输入），但上游或 Redis
     * 受控时可能被导向内网任意主机端口，形成内网 TCP 桥。此处解析目标并拦截内网段。
     */
    private function assertPublicUpstreamTarget(string $host): void
    {
        // 代理 fake-ip 环境（Clash TUN 等）域名统一解析为保留地址，DNS 校验不可靠；
        // 该环境仅限本地联调，Relay 只监听内网端口，默认跳过，生产保持严格。
        if ((bool) config('idc.vnc_relay.allow_private_upstream', false)) {
            return;
        }

        $host = trim($host);
        if ($host === '') {
            return;
        }

        $lower = strtolower($host);
        if (in_array($lower, ['localhost', 'localhost.localdomain', 'ip6-localhost'], true)
            || $lower === '::1'
            || str_starts_with($lower, '0.0.0.0')
            || $lower === '::'
        ) {
            throw new \RuntimeException('上游 VNC 目标为本地地址，已拒绝连接');
        }

        $resolved = $this->resolveTargetIps($host);
        if ($resolved === []) {
            // 无法解析（DNS 不可用或下游有解析能力），交由连接层处理，不阻断合法目标。
            return;
        }

        foreach ($resolved as $ip) {
            if ($this->isPrivateOrReservedIp($ip)) {
                throw new \RuntimeException('上游 VNC 目标解析到内网/保留地址，已拒绝连接');
            }
        }
    }

    /**
     * @return list<string>
     */
    private function resolveTargetIps(string $host): array
    {
        if (filter_var($host, FILTER_VALIDATE_IP)) {
            return [$host];
        }

        $ips = [];
        $ipv4 = @gethostbynamel($host);
        if (is_array($ipv4)) {
            $ips = array_merge($ips, array_values($ipv4));
        }

        if (function_exists('dns_get_record')) {
            try {
                $records = @dns_get_record($host, DNS_AAAA);
                if (is_array($records)) {
                    foreach ($records as $record) {
                        if (($record['type'] ?? '') === 'AAAA' && ! empty($record['ipv6'])) {
                            $ips[] = (string) $record['ipv6'];
                        }
                    }
                }
            } catch (Throwable) {
                // DNS 解析失败不阻断合法目标，交由连接层处理。
            }
        }

        return $ips;
    }

    private function isPrivateOrReservedIp(string $ip): bool
    {
        $ip = $this->normalizeIpForPolicyCheck(trim($ip));

        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            $long = ip2long($ip);
            if ($long === false) {
                return true;
            }
            $long = (int) $long;

            // 0.0.0.0/8、10/8、127/8、169.254/16、172.16/12、192.168/16、100.64/10、192.0.0/24
            $networks = [
                [0x00000000, 0xFF000000],
                [0x0A000000, 0xFF000000],
                [0x7F000000, 0xFF000000],
                [0xA9FE0000, 0xFFFF0000],
                [0xAC100000, 0xFFF00000],
                [0xC0A80000, 0xFFFF0000],
                [0x64400000, 0xFFC00000],
                [0xC0000000, 0xFFFFFF00],
            ];

            foreach ($networks as [$base, $mask]) {
                if (($long & $mask) === ($base & $mask)) {
                    return true;
                }
            }

            return false;
        }

        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
            $lower = strtolower($ip);
            if ($lower === '::1' || $lower === '::') {
                return true;
            }

            $firstGroup = explode(':', $lower)[0] ?? '';

            return preg_match('/^fe[89ab]/', $firstGroup) === 1     // fe80::/10 链路本地
                || preg_match('/^f[cd]/', $firstGroup) === 1;        // fc00::/7 唯一本地
        }

        return true;
    }

    /**
     * 把 IPv4-mapped / IPv4-compatible IPv6 还原成点分十进制，再交给黑名单判定。
     *
     * 不归一化就会被绕过：`::ffff:127.0.0.1` 只通得过 FILTER_FLAG_IPV6，于是落进上面的
     * IPv6 分支；而 explode(':', '::ffff:127.0.0.1')[0] 取到的是**空串**（开头就是 `::`），
     * 既不匹配 fe[89ab] 也不匹配 f[cd]，结果被判成公网直接放行。随后
     * stream_socket_client('tcp://::ffff:127.0.0.1:port') 由内核按 mapped 语义
     * 直连 IPv4 回环，整道内网拦截形同不存在。`::ffff:169.254.169.254`
     * （云元数据端点）、`::ffff:10.0.0.5` 等同理。
     *
     * 归一化后 `::` → 0.0.0.0、`::1` → 0.0.0.1，两者都落在 0.0.0.0/8，判定结果与
     * 原先的字符串特例一致，不改变既有行为。
     */
    private function normalizeIpForPolicyCheck(string $ip): string
    {
        $packed = @inet_pton($ip);
        if ($packed === false || strlen($packed) !== 16) {
            return $ip;
        }

        // ::ffff:a.b.c.d（前 80 位 0 + 16 位 1）与 ::a.b.c.d（前 96 位 0）
        $mappedPrefix = str_repeat("\x00", 10)."\xff\xff";
        $compatiblePrefix = str_repeat("\x00", 12);

        if (! str_starts_with($packed, $mappedPrefix) && ! str_starts_with($packed, $compatiblePrefix)) {
            return $ip;
        }

        $ipv4 = @inet_ntop(substr($packed, 12));

        return is_string($ipv4) && filter_var($ipv4, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)
            ? $ipv4
            : $ip;
    }

    private function resolveOriginHeader(array $params = []): string
    {
        $upstreamOrigin = trim((string) ($params['origin'] ?? ''));
        if ($upstreamOrigin !== '') {
            return $upstreamOrigin;
        }

        return PublicUrl::console();
    }

    /**
     * 先预览 token 并校验 Origin，避免跨域请求消费一次性 VNC 启动链接。
     *
     * @return array<string, mixed>|null
     */
    private function resolveVncTokenForClient(
        ClientServiceConsoleService $consoleService,
        string $token,
        string $origin
    ): ?array {
        $previewParams = $consoleService->previewVncToken($token);
        if (! $this->isAllowedClientOrigin($origin, $previewParams)) {
            return null;
        }

        return $consoleService->resolveVncToken($token);
    }

    private function isAllowedClientOrigin(string $origin, array $params = []): bool
    {
        $allowedOrigin = $this->normalizeOrigin((string) ($params['allowed_origin'] ?? ''));
        if ($allowedOrigin === '') {
            $allowedOrigin = $this->normalizeOrigin(PublicUrl::console());
        }

        $actualOrigin = $this->normalizeOrigin($origin);
        if ($actualOrigin === '') {
            return false;
        }

        return strcasecmp($allowedOrigin, $actualOrigin) === 0;
    }

    private function normalizeOrigin(string $origin): string
    {
        $origin = trim($origin);
        if ($origin === '') {
            return '';
        }

        $parts = parse_url($origin);
        if (! is_array($parts)) {
            return '';
        }

        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        $host = strtolower((string) ($parts['host'] ?? ''));
        if ($scheme === '' || $host === '') {
            return '';
        }

        $port = (int) ($parts['port'] ?? 0);
        $defaultPort = $scheme === 'https' ? 443 : 80;

        if ($port > 0 && $port !== $defaultPort) {
            return sprintf('%s://%s:%d', $scheme, $host, $port);
        }

        return sprintf('%s://%s', $scheme, $host);
    }

    private function maskToken(string $token): string
    {
        $token = trim($token);
        if ($token === '') {
            return '';
        }

        if (strlen($token) <= 8) {
            return str_repeat('*', strlen($token));
        }

        return substr($token, 0, 4).str_repeat('*', max(strlen($token) - 8, 0)).substr($token, -4);
    }

    /**
     * 上游 VNC URL 的 query 可能携带 password/token 等敏感凭据（如
     * wss://vnc.example.test/websockify?password=secret），日志侧只保留路径段，
     * 剥离整个 query，避免实例密码以明文落入日志与告警链路。
     */
    private function maskUpstreamPath(string $path): string
    {
        $path = trim($path);
        if ($path === '') {
            return '';
        }

        $parts = parse_url($path);
        if (is_array($parts)) {
            $pathOnly = trim((string) ($parts['path'] ?? ''));
            if ($pathOnly !== '') {
                return $pathOnly;
            }
        }

        return preg_replace('/[?#].*$/', '', $path) ?? '';
    }

    private function buildClientHandshakeResponse(string $clientKey): string
    {
        $accept = base64_encode(sha1($clientKey.'258EAFA5-E914-47DA-95CA-C5AB0DC85B11', true));

        return implode("\r\n", [
            'HTTP/1.1 101 Switching Protocols',
            'Upgrade: websocket',
            'Connection: Upgrade',
            'Sec-WebSocket-Accept: '.$accept,
            '',
            '',
        ]);
    }

    /**
     * @param  resource  $socket
     */
    private function writeHttpError($socket, int $status, string $statusText, string $message): void
    {
        $body = $message."\n";
        $response = implode("\r\n", [
            sprintf('HTTP/1.1 %d %s', $status, $statusText),
            'Content-Type: text/plain; charset=utf-8',
            'Content-Length: '.strlen($body),
            'Connection: close',
            '',
            $body,
        ]);

        @fwrite($socket, $response);
    }

    private function forwardClientFrames(int $connectionId): void
    {
        $connection = $this->connections[$connectionId] ?? null;
        if (! is_array($connection)) {
            return;
        }

        while (true) {
            $frame = $this->extractFrame($connection['client_in'], true);
            if ($frame === null) {
                break;
            }

            switch ($frame['opcode']) {
                case 0x8:
                    $this->closeConnection($connectionId);

                    return;

                case 0x9:
                    $connection['client_out'] .= $this->encodeFrame(0xA, $frame['payload'], false, true);
                    break;

                case 0xA:
                    break;

                default:
                    $connection['upstream_out'] .= $this->encodeFrame($frame['opcode'], $frame['payload'], true, $frame['fin']);
                    break;
            }
        }

        $this->connections[$connectionId] = $connection;
    }

    private function forwardUpstreamFrames(int $connectionId): void
    {
        $connection = $this->connections[$connectionId] ?? null;
        if (! is_array($connection)) {
            return;
        }

        while (true) {
            $frame = $this->extractFrame($connection['upstream_in'], false);
            if ($frame === null) {
                break;
            }

            switch ($frame['opcode']) {
                case 0x8:
                    $this->closeConnection($connectionId);

                    return;

                case 0x9:
                    $connection['upstream_out'] .= $this->encodeFrame(0xA, $frame['payload'], true, true);
                    break;

                case 0xA:
                    break;

                default:
                    $connection['client_out'] .= $this->encodeFrame($frame['opcode'], $frame['payload'], false, $frame['fin']);
                    break;
            }
        }

        $this->connections[$connectionId] = $connection;
    }

    /**
     * @return array{fin:bool,opcode:int,payload:string}|null
     */
    private function extractFrame(string &$buffer, bool $expectMasked): ?array
    {
        if (strlen($buffer) < 2) {
            return null;
        }

        $byte1 = ord($buffer[0]);
        $byte2 = ord($buffer[1]);
        $fin = ($byte1 & 0x80) !== 0;
        $opcode = $byte1 & 0x0F;
        $masked = ($byte2 & 0x80) !== 0;
        $payloadLength = $byte2 & 0x7F;
        $offset = 2;

        if ($payloadLength === 126) {
            if (strlen($buffer) < $offset + 2) {
                return null;
            }

            $payloadLength = unpack('nlen', substr($buffer, $offset, 2))['len'];
            $offset += 2;
        } elseif ($payloadLength === 127) {
            if (strlen($buffer) < $offset + 8) {
                return null;
            }

            $extended = unpack('Nhigh/Nlow', substr($buffer, $offset, 8));
            $payloadLength = ((int) $extended['high'] << 32) | (int) $extended['low'];
            $offset += 8;
        }

        $maskKey = '';
        if ($masked) {
            if (strlen($buffer) < $offset + 4) {
                return null;
            }

            $maskKey = substr($buffer, $offset, 4);
            $offset += 4;
        } elseif ($expectMasked) {
            throw new \RuntimeException('浏览器发送了未掩码帧');
        }

        if (strlen($buffer) < $offset + $payloadLength) {
            return null;
        }

        $payload = substr($buffer, $offset, $payloadLength);
        $buffer = (string) substr($buffer, $offset + $payloadLength);

        if ($masked) {
            $payload = $this->applyMask($payload, $maskKey);
        }

        return [
            'fin' => $fin,
            'opcode' => $opcode,
            'payload' => $payload,
        ];
    }

    private function encodeFrame(int $opcode, string $payload, bool $masked, bool $fin): string
    {
        $firstByte = ($fin ? 0x80 : 0x00) | ($opcode & 0x0F);
        $length = strlen($payload);
        $frame = chr($firstByte);

        if ($length < 126) {
            $frame .= chr(($masked ? 0x80 : 0x00) | $length);
        } elseif ($length <= 0xFFFF) {
            $frame .= chr(($masked ? 0x80 : 0x00) | 126).pack('n', $length);
        } else {
            $frame .= chr(($masked ? 0x80 : 0x00) | 127)
                .pack('NN', ($length >> 32) & 0xFFFFFFFF, $length & 0xFFFFFFFF);
        }

        if (! $masked) {
            return $frame.$payload;
        }

        $maskKey = random_bytes(4);

        return $frame.$maskKey.$this->applyMask($payload, $maskKey);
    }

    private function applyMask(string $payload, string $maskKey): string
    {
        $result = '';
        $maskLength = strlen($maskKey);
        $payloadLength = strlen($payload);

        for ($index = 0; $index < $payloadLength; $index++) {
            $result .= chr(ord($payload[$index]) ^ ord($maskKey[$index % $maskLength]));
        }

        return $result;
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

    private function shutdownServer(): void
    {
        foreach (array_keys($this->connections) as $connectionId) {
            $this->closeConnection($connectionId);
        }

        if (is_resource($this->server)) {
            @fclose($this->server);
        }
    }
}
