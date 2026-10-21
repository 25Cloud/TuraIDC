<?php

declare(strict_types=1);

namespace TuraIDC\Plugins\Servers\RainyunRcs\Lib;

use App\Exceptions\BusinessException;
use App\Models\Supplier;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

final class RainyunClient
{
    public function request(Supplier $supplier, string $method, string $path, array $payload = [], array $query = [], int $timeout = 30): mixed
    {
        $method = strtoupper($method);
        $key = trim((string) $supplier->api_key);
        throw_if($key === '', new BusinessException('请先配置雨云 API 密钥', 42200));
        throw_if(! in_array($method, ['GET', 'POST', 'PATCH', 'DELETE'], true), new BusinessException('雨云请求方法无效', 42200));
        throw_if(! str_starts_with($path, '/product/rcs/') && $path !== '/user/', new BusinessException('雨云接口路径无效', 42200));
        $options = ['query' => $method === 'GET' ? array_merge($payload, $query) : $query];
        if ($method !== 'GET') {
            $options['json'] = $payload === [] ? new \stdClass : $payload;
        }

        try {
            // 写请求没有上游幂等键，禁止在网络异常后自动重发。
            $response = Http::acceptJson()->withHeaders(['x-api-key' => $key])
                ->timeout($timeout)->connectTimeout(min(10, $timeout))
                ->withOptions(['verify' => false, 'allow_redirects' => false])
                ->send($method, $this->baseUrl($supplier).$path, $options);
        } catch (ConnectionException) {
            throw new RainyunApiException('雨云接口连接失败，请检查网络；写操作结果不明时请先核对上游', uncertain: $method !== 'GET');
        }

        $body = $response->json();
        if (! is_array($body) || ! isset($body['code'])) {
            throw new RainyunApiException('雨云接口未返回有效结果，请核对操作状态', uncertain: $method !== 'GET');
        }
        $code = (int) $body['code'];
        if (! $response->successful() || $code !== 200) {
            $message = match ($code) {
                70027 => '请先关闭实例，再执行重装或该维护操作',
                10005 => '雨云实例或资源不存在，请核对绑定',
                default => '雨云操作失败，请检查接口权限、参数和账户状态（错误码 '.$code.'）',
            };
            throw new RainyunApiException($message, $code, $method !== 'GET' && $response->serverError());
        }

        return $body['data'] ?? null;
    }

    public function detail(Supplier $supplier, int $hostId, int $timeout = 30): array
    {
        $data = $this->request($supplier, 'GET', $this->hostPath($hostId), timeout: $timeout);
        throw_if(! is_array($data) || ! is_array($data['Data'] ?? null), new RainyunApiException('雨云实例详情格式不正确'));
        throw_if((int) ($data['Data']['ID'] ?? 0) !== $hostId, new RainyunApiException('雨云实例详情与绑定不一致'));

        return $data;
    }

    public function hostPath(int $hostId, string $suffix = ''): string
    {
        throw_if($hostId <= 0, new BusinessException('雨云实例 ID 无效', 42200));

        return '/product/rcs/'.$hostId.'/'.ltrim($suffix, '/');
    }

    public function baseUrl(Supplier $supplier): string
    {
        $url = rtrim(trim((string) $supplier->api_url), '/');
        $parts = parse_url($url);
        throw_if(! is_array($parts) || empty($parts['host']) || ! in_array($parts['scheme'] ?? '', ['http', 'https'], true)
            || isset($parts['user']) || isset($parts['pass']) || isset($parts['query']) || isset($parts['fragment']), new BusinessException('雨云 API 地址格式不正确', 42200));

        return $url;
    }
}
