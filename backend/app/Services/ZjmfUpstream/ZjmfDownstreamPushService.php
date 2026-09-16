<?php

declare(strict_types=1);

namespace App\Services\ZjmfUpstream;

use App\Exceptions\BusinessException;
use App\Models\Service;
use App\Models\ZjmfUpstreamBinding;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

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

        $payload = array_merge($payload, $this->sign(['id' => $downstreamId], $token));

        try {
            $response = Http::asForm()
                ->connectTimeout(self::CONNECT_TIMEOUT_SECONDS)
                ->timeout(self::TIMEOUT_SECONDS)
                ->post($url.'/api/host/sync', $payload);
        } catch (\Throwable $exception) {
            Log::warning('[zjmf-upstream] 下游状态推送失败', [
                'service_id' => (int) $service->id,
                'binding_id' => (int) $binding->id,
                'type' => $type,
                'error' => $exception->getMessage(),
            ]);

            return false;
        }

        $body = $response->json();
        $status = is_array($body) ? (int) ($body['status'] ?? 0) : 0;

        if ($response->status() !== 200 || $status !== 200) {
            Log::warning('[zjmf-upstream] 下游状态推送未受理', [
                'service_id' => (int) $service->id,
                'binding_id' => (int) $binding->id,
                'type' => $type,
                'http_status' => $response->status(),
                'body_status' => $status,
                'msg' => is_array($body) ? (string) ($body['msg'] ?? '') : '',
            ]);

            return false;
        }

        return true;
    }

    /**
     * 对齐魔方财务 createSign。
     *
     * @param  array<string, mixed>  $params
     * @return array{signature: string, rand_str: string}
     */
    private function sign(array $params, string $token): array
    {
        // 密钥缺省即拒绝：下游 token 为空时签出来的签名下游必然验不过，
        // 与其发一次注定失败的请求，不如直接拒绝并留下可定位的日志。
        if (trim($token) === '') {
            throw new BusinessException('下游回推凭据未配置，无法签名');
        }

        $randStr = Str::lower(Str::random(6));
        $params['token'] = $token;
        $params['rand_str'] = $randStr;
        ksort($params, SORT_STRING);

        return [
            'signature' => strtoupper(md5((string) json_encode($params))),
            'rand_str' => $randStr,
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
            $decoded = json_decode((string) \Illuminate\Support\Facades\Crypt::decryptString($payload), true);
        } catch (\Throwable) {
            return [];
        }

        return is_array($decoded) ? $decoded : [];
    }

    private function domainStatus(int $status): string
    {
        return match ($status) {
            \App\Constants\ServiceStatus::PENDING => 'Pending',
            \App\Constants\ServiceStatus::ACTIVE => 'Active',
            \App\Constants\ServiceStatus::SUSPENDED => 'Suspended',
            \App\Constants\ServiceStatus::EXPIRED => 'Suspended',
            \App\Constants\ServiceStatus::CANCELLED => 'Deleted',
            default => 'Pending',
        };
    }
}
