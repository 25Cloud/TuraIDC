<?php

declare(strict_types=1);

namespace TuraIDC\Plugins\Servers\KangHostx\Lib;

use App\Exceptions\BusinessException;
use App\Models\Supplier;
use Illuminate\Support\Facades\Http;

final class KangHostxClient
{
    public function info(Supplier $supplier): array
    {
        return $this->request($supplier, 'info');
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function createVirtualHost(Supplier $supplier, array $payload): array
    {
        return $this->request($supplier, 'add_vh', $payload);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function changePackage(Supplier $supplier, array $payload): array
    {
        $payload['edit'] = 1;

        return $this->request($supplier, 'add_vh', $payload);
    }

    public function getVirtualHost(Supplier $supplier, string $name): array
    {
        return $this->request($supplier, 'getVh', [
            'name' => $name,
            'showpasswd' => 1,
        ]);
    }

    public function updateVirtualHostStatus(Supplier $supplier, string $name, int $status): array
    {
        return $this->request($supplier, 'update_vh', [
            'name' => $name,
            'status' => $status,
        ]);
    }

    public function deleteVirtualHost(Supplier $supplier, string $name): array
    {
        return $this->request($supplier, 'del_vh', ['name' => $name]);
    }

    public function changePassword(Supplier $supplier, string $name, string $password): array
    {
        return $this->request($supplier, 'change_password', [
            'name' => $name,
            'passwd' => $password,
        ]);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function request(Supplier $supplier, string $action, array $payload = []): array
    {
        $action = trim($action);
        throw_if($action === '', new BusinessException('康乐接口动作不能为空', 42200));

        $accessHash = $this->accessHash($supplier);
        $nonce = (string) random_int(100000, 999999);
        $query = array_merge([
            'c' => 'whm',
            'a' => $action,
        ], $payload, [
            'r' => $nonce,
            's' => $this->sign($action, $accessHash, $nonce),
            'json' => 1,
        ]);

        $response = Http::timeout($this->timeout($supplier))
            ->connectTimeout(min($this->timeout($supplier), 10))
            ->acceptJson()
            // 不设置 verify：项目硬规则要求插件不提供 SSL 与 CA 配置，统一依赖系统 CA。
            // 原实现读 supplier.provider_config['ssl_verify']，而该字段并未在
            // supplierFormSchema() 里声明，属于「读了自己 schema 里不存在的键」。
            ->withOptions([
                'allow_redirects' => false,
            ])
            ->get($this->apiEndpoint($supplier), $query);

        if (! $response->successful()) {
            throw new BusinessException('康乐接口请求失败，请检查面板地址和网络连通性', 42200);
        }

        $body = trim((string) $response->body(), "\xEF\xBB\xBF");
        $decoded = json_decode($body, true);
        if (! is_array($decoded)) {
            throw new BusinessException('康乐接口返回异常，未解析到有效数据', 42200);
        }

        return $decoded;
    }

    public function panelLoginUrl(Supplier $supplier): string
    {
        return rtrim($this->rootUrl($supplier), '/').'/vhost/index.php?c=session&a=login';
    }

    public function assertSuccess(array $response, string $actionLabel): void
    {
        $result = (int) ($response['result'] ?? $response['status'] ?? $response['code'] ?? 0);
        if ($result === 200) {
            return;
        }

        $message = $this->responseMessage($response['msg'] ?? $response['message'] ?? null);
        if ($result === 500 && $message === '') {
            $message = '主机名已存在或参数不正确';
        }

        throw new BusinessException($actionLabel.'失败'.($message !== '' ? '：'.$message : ''), 42200);
    }

    public function sign(string $action, string $accessHash, string $nonce): string
    {
        return md5($action.$accessHash.$nonce);
    }

    private function responseMessage(mixed $value): string
    {
        if ($value === null) {
            return '';
        }

        if (is_scalar($value)) {
            return trim((string) $value);
        }

        if ($value instanceof \Stringable) {
            return trim((string) $value);
        }

        if (is_array($value)) {
            foreach (['msg', 'message', 'error', 'reason'] as $key) {
                $message = $this->responseMessage($value[$key] ?? null);
                if ($message !== '') {
                    return $message;
                }
            }

            $encoded = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

            return is_string($encoded) ? $encoded : '';
        }

        return '';
    }

    private function apiEndpoint(Supplier $supplier): string
    {
        $baseUrl = rtrim($this->baseUrl($supplier), '/');
        $path = strtolower((string) parse_url($baseUrl, PHP_URL_PATH));

        if (str_ends_with($path, '/api/index.php')) {
            return $baseUrl;
        }

        return $baseUrl.'/api/index.php';
    }

    private function rootUrl(Supplier $supplier): string
    {
        $baseUrl = $this->baseUrl($supplier);
        $parts = parse_url($baseUrl);
        if (! is_array($parts) || empty($parts['scheme']) || empty($parts['host'])) {
            return rtrim($baseUrl, '/');
        }

        $url = strtolower((string) $parts['scheme']).'://'.strtolower((string) $parts['host']);
        if (isset($parts['port'])) {
            $url .= ':'.(int) $parts['port'];
        }

        return $url;
    }

    private function baseUrl(Supplier $supplier): string
    {
        $config = $this->providerConfig($supplier);
        $baseUrl = trim((string) ($supplier->api_url ?? $config['api_url'] ?? ''));

        if ($baseUrl === '') {
            $serverIp = trim((string) ($config['server_ip'] ?? ''));
            $port = (int) (($config['port'] ?? 0) ?: 0);
            if ($serverIp !== '' && $port > 0) {
                $scheme = $this->truthy($config['use_https'] ?? false) ? 'https' : 'http';
                $baseUrl = "{$scheme}://{$serverIp}:{$port}";
            }
        }

        $parts = parse_url($baseUrl);
        if (! is_array($parts) || empty($parts['scheme']) || empty($parts['host'])) {
            throw new BusinessException('康乐面板接口地址未配置或格式不正确', 42200);
        }

        $scheme = strtolower((string) $parts['scheme']);
        if (! in_array($scheme, ['http', 'https'], true)) {
            throw new BusinessException('康乐面板接口地址仅支持 HTTP 或 HTTPS', 42200);
        }

        return rtrim($baseUrl, '/');
    }

    private function accessHash(Supplier $supplier): string
    {
        $config = $this->providerConfig($supplier);
        $accessHash = trim((string) ($supplier->api_key ?? $config['accesshash'] ?? ''));

        if ($accessHash === '') {
            throw new BusinessException('康乐访问密钥未配置', 42200);
        }

        return $accessHash;
    }

    private function timeout(Supplier $supplier): int
    {
        $config = $this->providerConfig($supplier);

        return max(1, min((int) (($config['timeout'] ?? 15) ?: 15), 60));
    }

    /**
     * @return array<string, mixed>
     */
    private function providerConfig(Supplier $supplier): array
    {
        return is_array($supplier->provider_config ?? null) ? (array) $supplier->provider_config : [];
    }

    private function truthy(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }

        if (is_string($value)) {
            return filter_var($value, FILTER_VALIDATE_BOOL);
        }

        return (bool) $value;
    }
}
