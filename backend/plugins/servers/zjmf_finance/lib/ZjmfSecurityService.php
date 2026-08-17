<?php

declare(strict_types=1);

namespace TuraIDC\Plugins\Servers\ZjmfFinance\Lib;

use App\Exceptions\BusinessException;
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

        if (preg_match('#^/provision/custom/[1-9]\d*$#', $path) !== 1) {
            throw new BusinessException('安全组模块未返回有效的同系统请求地址', 42200);
        }

        if (isset($parsedEndpoint['query'], $parsedEndpoint['fragment'], $parsedEndpoint['user'], $parsedEndpoint['pass'])) {
            throw new BusinessException('安全组模块请求地址不能包含额外参数', 42200);
        }

        $hasAbsoluteUrlParts = isset($parsedEndpoint['scheme']) || isset($parsedEndpoint['host']);
        if (! $hasAbsoluteUrlParts) {
            return $path;
        }

        $configuredEndpoint = parse_url(trim((string) $supplier->api_url));
        if (! is_array($configuredEndpoint)
            || ! isset($parsedEndpoint['scheme'], $parsedEndpoint['host'])
            || $this->normalizedOrigin($parsedEndpoint) !== $this->normalizedOrigin($configuredEndpoint)) {
            throw new BusinessException('安全组模块请求地址必须与供应商接口同源', 42200);
        }

        return $endpoint;
    }

    private function normalizedOrigin(array $endpoint): string
    {
        $scheme = strtolower(trim((string) ($endpoint['scheme'] ?? '')));
        $host = strtolower(trim((string) ($endpoint['host'] ?? '')));
        $port = (int) ($endpoint['port'] ?? ($scheme === 'https' ? 443 : 80));

        return $scheme.'://'.$host.':'.$port;
    }
}
