<?php

declare(strict_types=1);

namespace TuraIDC\Plugins\Servers\ZjmfFinance\Lib;

use App\Exceptions\BusinessException;
use App\Models\Setting;
use App\Models\Supplier;

final class ZjmfSecurityService
{
    public function __construct(
        private readonly ZjmfFinanceTransport $transport,
    ) {}

    public function submitCustomModuleAction(Supplier $supplier, string $endpoint, array $payload, ?string $jwt = null): array
    {
        $resolvedJwt = $this->resolveJwt($supplier, $jwt);

        return $this->transport->post(
            $supplier,
            $this->normalizeCustomModuleEndpoint($supplier, $endpoint),
            $payload,
            $resolvedJwt,
            [
                'content-type: application/x-www-form-urlencoded',
                'Authorization: JWT '.$resolvedJwt,
            ]
        );
    }

    private function resolveJwt(Supplier $supplier, ?string $jwt): string
    {
        $jwt = trim((string) $jwt);

        return $jwt !== '' ? $jwt : $this->transport->login($supplier);
    }

    private function normalizeCustomModuleEndpoint(Supplier $supplier, string $endpoint): string
    {
        $endpoint = trim($endpoint);
        $parsedEndpoint = parse_url($endpoint);
        if (! is_array($parsedEndpoint)) {
            throw new BusinessException('安全组模块未返回有效的同系统请求地址', 42200);
        }

        $path = (string) ($parsedEndpoint['path'] ?? '');

        // 自定义面板动作端点：客户区 /provision/custom/{id} 与 API 协议
        // /zjmf_api/provision/custom/{id}（魔方财务 customFunc 路由）均合法。
        if (preg_match('#^/(?:zjmf_api/)?provision/custom/[1-9]\d*$#', $path) !== 1) {
            throw new BusinessException('安全组模块未返回有效的同系统请求地址', 42200);
        }

        if (isset($parsedEndpoint['query'], $parsedEndpoint['fragment'], $parsedEndpoint['user'], $parsedEndpoint['pass'])) {
            throw new BusinessException('安全组模块请求地址不能包含额外参数', 42200);
        }

        $hasAbsoluteUrlParts = isset($parsedEndpoint['scheme']) || isset($parsedEndpoint['host']);
        if (! $hasAbsoluteUrlParts) {
            return $path;
        }

        // 上游为二次对接时，面板片段下发的绝对地址常指向再上游域名：
        // 关闭「同源检测」后放行该绝对地址（路径仍已被上面约束为同系统动作路由）。
        if (! $this->originCheckEnabled()) {
            return $endpoint;
        }

        $configuredEndpoint = parse_url(trim((string) $supplier->api_url));
        if (! is_array($configuredEndpoint)
            || ! isset($parsedEndpoint['scheme'], $parsedEndpoint['host'])
            || $this->normalizedOrigin($parsedEndpoint) !== $this->normalizedOrigin($configuredEndpoint)) {
            throw new BusinessException('安全组模块请求地址必须与供应商接口同源', 42200);
        }

        return $endpoint;
    }

    /**
     * 系统设置 system.console_module_origin_check_enabled 控制是否强制同源比对，默认开启。
     */
    private function originCheckEnabled(): bool
    {
        return in_array(
            Setting::getValue('system', 'console_module_origin_check_enabled', '1'),
            [true, 1, '1', 'true', 'on'],
            true
        );
    }

    private function normalizedOrigin(array $endpoint): string
    {
        $scheme = strtolower(trim((string) ($endpoint['scheme'] ?? '')));
        $host = strtolower(trim((string) ($endpoint['host'] ?? '')));
        $port = (int) ($endpoint['port'] ?? ($scheme === 'https' ? 443 : 80));

        return $scheme.'://'.$host.':'.$port;
    }
}
