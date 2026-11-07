<?php

declare(strict_types=1);

namespace App\Services\ZjmfUpstream;

use App\Constants\ServiceStatus;
use App\Exceptions\BusinessException;
use App\Models\Service;
use App\Models\ZjmfUpstreamBinding;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * 上游 → 下游（魔方财务）状态推送发送方：POST {downstream_url}/api/host/sync。
 *
 * 协议对齐魔方财务 app/zjmf.php 的 pushHostInfo + createSign：
 *   - 载荷：下游 host id（放在 id 字段）、本系统服务信息、可选 type；
 *   - 签名：strtoupper(md5(json_encode(ksort(['id','token','rand_str'], SORT_STRING))))，
 *     其中 token 是下游在结算/改绑时登记的回推凭据，rand_str 随请求回传供下游复算。
 *
 * 推送是旁路：任何失败都只记日志，绝不影响开通、暂停、续费等主流程——
 * 下游仍可通过 host/header 主动同步兜底。
 */
class ZjmfDownstreamPushService
{
    private const TIMEOUT_SECONDS = 15;

    private const CONNECT_TIMEOUT_SECONDS = 8;

    /** 下游 host/sync 的 type 取值（仅 create 会被下游记录去重） */
    public const TYPE_CREATE = 'create';

    public const TYPE_SUSPEND = 'suspend';

    public const TYPE_UNSUSPEND = 'unsuspend';

    public const TYPE_TERMINATE = 'terminate';

    public const TYPE_RENEW = 'renew';

    /**
     * 按服务推送状态；无绑定或下游地址为空时静默跳过。
     *
     * @return bool 是否成功投递（失败与跳过都返回 false，仅用于测试与观测）
     */
    public function pushForService(Service $service, string $type = ''): bool
    {
        try {
            $bindings = ZjmfUpstreamBinding::query()
                ->where('service_id', (int) $service->id)
                ->where('downstream_url', '!=', '')
                ->get();

            if ($bindings->isEmpty()) {
                return false;
            }

            $delivered = false;
            foreach ($bindings as $binding) {
                $delivered = $this->pushToBinding($service, $binding, $type) || $delivered;
            }

            return $delivered;
        } catch (\Throwable $exception) {
            Log::warning('[zjmf-upstream] 下游状态推送异常', [
                'service_id' => (int) $service->id,
                'type' => $type,
                'error' => $exception->getMessage(),
            ]);

            return false;
        }
    }

    private function pushToBinding(Service $service, ZjmfUpstreamBinding $binding, string $type): bool
    {
        $url = rtrim(trim((string) $binding->downstream_url), '/');
        $token = trim((string) $binding->downstream_token);
        $downstreamId = (int) $binding->downstream_id;

        if ($url === '' || $downstreamId <= 0) {
            return false;
        }

        $provisionData = is_array($service->provision_data ?? null) ? $service->provision_data : [];
        $connection = $this->readConnection($provisionData);

        // 下游以 id 认自己的 host，因此 id 用 downstream_id；本系统服务 id 放 host_id
        $payload = [
            'id' => $downstreamId,
            'host_id' => (int) $service->id,
            'domain' => (string) ($service->domain ?? ''),
            'username' => (string) ($connection['username'] ?? ''),
            'password' => (string) ($connection['password'] ?? ''),
            'dedicatedip' => (string) ($provisionData['dedicated_ip'] ?? ''),
            'assignedips' => is_array($provisionData['assigned_ips'] ?? null)
                ? implode(',', array_map('strval', $provisionData['assigned_ips']))
                : '',
            'port' => (int) (($connection['port'] ?? 0) ?: 0),
            'os' => (string) ($provisionData['os'] ?? ''),
            'os_url' => '',
            'domainstatus' => $this->domainStatus((int) $service->status),
            'suspendreason' => (string) ($service->suspended_reason ?? ''),
        ];

        if ($service->expires_at !== null) {
            $payload['nextduedate'] = $service->expires_at->format('Y-m-d');
        }

        if ($type !== '') {
            $payload['type'] = $type;
        }

        // 先按 3.8.0 的 v2 签名发（HMAC-SHA256 + timestamp + nonce）；
        // 下游明确回「签名验证失败」时再回退 legacy（3.7.6 及更早只认 md5）。
        // 顺序不能反：3.8.0 下游默认拒绝 legacy（除非它自己开了 ALLOW_LEGACY_SYNC_SIGNATURES）。
        try {
            $signed = ZjmfSyncSignature::signV2($payload, $token);
        } catch (BusinessException $exception) {
            Log::warning('[zjmf-upstream] 下游推送签名失败', [
                'service_id' => (int) $service->id,
                'binding_id' => (int) $binding->id,
                'error' => $exception->getMessage(),
            ]);

            return false;
        }

        $result = $this->send($url.'/api/host/sync', array_merge($payload, $signed));

        if (! $result['ok'] && ZjmfSyncSignature::isSignatureRejected($result['body'])) {
            Log::info('[zjmf-upstream] 下游拒绝 v2 签名，回退 legacy 重发', [
                'service_id' => (int) $service->id,
                'binding_id' => (int) $binding->id,
                'type' => $type,
            ]);

            $legacy = ZjmfSyncSignature::signLegacy($payload, $token);
            $result = $this->send(
                $url.'/api/host/sync',
                array_merge($payload, $legacy, ['token' => $token])
            );
        }

        if (! $result['ok']) {
            Log::warning('[zjmf-upstream] 下游状态推送未受理', [
                'service_id' => (int) $service->id,
                'binding_id' => (int) $binding->id,
                'type' => $type,
                'http_status' => $result['http_status'],
                'body_status' => (int) ($result['body']['status'] ?? 0),
                'msg' => (string) ($result['body']['msg'] ?? ''),
            ]);

            return false;
        }

        return true;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array{ok: bool, http_status: int, body: array<string, mixed>}
     */
    private function send(string $url, array $payload): array
    {
        try {
            $response = Http::asForm()
                ->connectTimeout(self::CONNECT_TIMEOUT_SECONDS)
                ->timeout(self::TIMEOUT_SECONDS)
                ->post($url, $payload);
        } catch (\Throwable $exception) {
            Log::warning('[zjmf-upstream] 下游状态推送失败', [
                'url' => $url,
                'error' => $exception->getMessage(),
            ]);

            return ['ok' => false, 'http_status' => 0, 'body' => []];
        }

        $body = $response->json();
        $body = is_array($body) ? $body : [];

        return [
            'ok' => $response->status() === 200 && (int) ($body['status'] ?? 0) === 200,
            'http_status' => $response->status(),
            'body' => $body,
        ];
    }

    /**
     * @param  array<string, mixed>  $provisionData
     * @return array<string, mixed>
     */
    private function readConnection(array $provisionData): array
    {
        $payload = trim((string) ($provisionData['connection_secret'] ?? ''));
        if ($payload === '') {
            return [];
        }

        try {
            $decoded = json_decode((string) Crypt::decryptString($payload), true);
        } catch (\Throwable) {
            return [];
        }

        return is_array($decoded) ? $decoded : [];
    }

    private function domainStatus(int $status): string
    {
        return match ($status) {
            ServiceStatus::PENDING => 'Pending',
            ServiceStatus::ACTIVE => 'Active',
            ServiceStatus::SUSPENDED => 'Suspended',
            ServiceStatus::EXPIRED => 'Suspended',
            ServiceStatus::CANCELLED => 'Deleted',
            default => 'Pending',
        };
    }
}
