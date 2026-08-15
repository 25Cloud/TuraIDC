<?php

declare(strict_types=1);

namespace TuraIDC\Plugins\Addons\ZjmfBridge\Services;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

class ZjmfSignatureService
{
    /**
     * @return array{ok: bool, status?: int, http_status?: int, message?: string, app_id?: string}
     */
    public function verify(Request $request, ?string $scope = null): array
    {
        $ipCheck = $this->verifyIp($request);
        if ($ipCheck !== null) {
            return $ipCheck;
        }

        $secret = trim((string) config('zjmf_bridge.secret', ''));
        if ($secret === '') {
            return ['ok' => false, 'status' => 503, 'http_status' => 503, 'message' => 'ZJMF Bridge 签名密钥未配置'];
        }

        $expectedAppId = trim((string) config('zjmf_bridge.app_id', ''));
        $appId = trim((string) $request->headers->get('X-ZJMF-App-Id', ''));
        if ($expectedAppId !== '' && $appId !== $expectedAppId) {
            return ['ok' => false, 'status' => 401, 'http_status' => 401, 'message' => '应用凭证无效'];
        }

        if (! $this->scopeAllowed($scope)) {
            return ['ok' => false, 'status' => 403, 'http_status' => 403, 'message' => '接口 scope 未授权'];
        }

        $timestamp = (int) $request->headers->get('X-ZJMF-Timestamp', '0');
        $nonce = trim((string) $request->headers->get('X-ZJMF-Nonce', ''));
        $signature = strtolower(trim((string) $request->headers->get('X-ZJMF-Signature', '')));

        if ($timestamp <= 0 || $nonce === '' || $signature === '') {
            return ['ok' => false, 'status' => 401, 'http_status' => 401, 'message' => '签名参数不完整'];
        }

        $tolerance = max(1, (int) config('zjmf_bridge.signature_tolerance', 300));
        if (abs(time() - $timestamp) > $tolerance) {
            return ['ok' => false, 'status' => 401, 'http_status' => 401, 'message' => '签名时间戳已过期'];
        }

        $expected = $this->sign($request, $secret, $timestamp, $nonce);
        if (! hash_equals($expected, $signature)) {
            return ['ok' => false, 'status' => 401, 'http_status' => 401, 'message' => '签名校验失败'];
        }

        $nonceKey = sprintf('zjmf_bridge:nonce:%s:%s', $appId !== '' ? $appId : 'default', $nonce);
        if (! Cache::add($nonceKey, true, $tolerance)) {
            return ['ok' => false, 'status' => 409, 'http_status' => 409, 'message' => '重复请求'];
        }

        return ['ok' => true, 'app_id' => $appId];
    }

    public function sign(Request $request, string $secret, int $timestamp, string $nonce): string
    {
        return hash_hmac('sha256', $this->canonicalString($request, $timestamp, $nonce), $secret);
    }

    public function canonicalString(Request $request, int $timestamp, string $nonce): string
    {
        return implode("\n", [
            strtoupper($request->getMethod()),
            $request->getPathInfo(),
            $this->canonicalQuery($request->query->all()),
            hash('sha256', $request->getContent() ?: ''),
            (string) $timestamp,
            $nonce,
        ]);
    }

    /**
     * @param  array<string, mixed>  $query
     */
    private function canonicalQuery(array $query): string
    {
        ksort($query);

        return http_build_query($query, '', '&', PHP_QUERY_RFC3986);
    }

    /**
     * @return array{ok: false, status: int, http_status: int, message: string}|null
     */
    private function verifyIp(Request $request): ?array
    {
        $allowedIps = (array) config('zjmf_bridge.allowed_ips', []);
        if ($allowedIps === []) {
            return null;
        }

        $ip = (string) $request->ip();
        if (in_array($ip, $allowedIps, true)) {
            return null;
        }

        return ['ok' => false, 'status' => 403, 'http_status' => 403, 'message' => '请求来源 IP 未授权'];
    }

    private function scopeAllowed(?string $scope): bool
    {
        if ($scope === null || $scope === '') {
            return true;
        }

        return in_array($scope, (array) config('zjmf_bridge.system_scopes', []), true);
    }
}
