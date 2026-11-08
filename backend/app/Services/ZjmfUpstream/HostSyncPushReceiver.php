<?php

declare(strict_types=1);

namespace App\Services\ZjmfUpstream;

use App\Constants\ServiceStatus;
use App\Exceptions\BusinessException;
use App\Models\Service;
use App\Models\ZjmfHostSyncEvent;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * 魔方财务上游 → 本系统（Tura 作为下游）主机推送接收端：POST /api/host/sync。
 *
 * 协议对齐魔方 app/zjmf.php pushHostInfo：
 *   - 载荷：id=本系统服务 id（downstream_id）、host_id=上游主机 id，
 *     domain/username/password/dedicatedip/assignedips/port/os/os_url/nextduedate/domainstatus，可选 type 与 suspendreason；
 *   - 签名校验由中间件完成（兼容 legacy createSign 与 v2 HMAC），token 为开通时经
 *     /host/setdownstream 登记的 downstream_token。
 *
 * 推送是上游权威状态的镜像：成功处理后返回 {status:200}，异常返回 {status:400}，
 * 与魔方 pushHostInfo 的重试记录（zjmf_pushhost）语义对齐。
 */
class HostSyncPushReceiver
{
    public function receive(array $payload): array
    {
        $serviceId = (int) ($payload['id'] ?? 0);
        if ($serviceId <= 0) {
            throw new BusinessException('推送缺少服务 ID', 42200);
        }

        $event = $this->eventDescriptor($payload, $serviceId);

        $response = DB::transaction(function () use ($payload, $serviceId, $event): array {
            $eventId = $this->claimEvent($serviceId, $event);
            if ($eventId === null) {
                return [
                    'status' => 200,
                    'msg' => 'duplicate',
                    'service_id' => $serviceId,
                    'duplicate' => true,
                ];
            }

            $service = Service::query()->lockForUpdate()->find($serviceId);
            if (! $service instanceof Service) {
                throw new BusinessException('服务不存在', 42200);
            }

            $this->applyPayload($service, $payload);

            $response = [
                'status' => 200,
                'msg' => 'ok',
                'service_id' => $serviceId,
                'duplicate' => false,
            ];
            ZjmfHostSyncEvent::query()->whereKey($eventId)->update([
                'status' => 'processed',
                'response_json' => json_encode($response, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'processed_at' => now(),
                'updated_at' => now(),
            ]);

            return $response;
        });

        Log::info('[zjmf-downstream] 上游主机推送已同步', [
            'service_id' => $serviceId,
            'upstream_host_id' => (int) ($payload['host_id'] ?? 0),
            'type' => trim((string) ($payload['type'] ?? '')),
            'domainstatus' => strtolower(trim((string) ($payload['domainstatus'] ?? ''))),
            'duplicate' => (bool) ($response['duplicate'] ?? false),
        ]);

        return $response;
    }

    /**
     * @param  array{event_key: string, signature_version: string, nonce_hash: ?string, payload_hash: string}  $event
     */
    private function claimEvent(int $serviceId, array $event): ?int
    {
        $now = now();
        $affected = DB::table((new ZjmfHostSyncEvent)->getTable())->insertOrIgnore([
            'service_id' => $serviceId,
            'event_key' => $event['event_key'],
            'signature_version' => $event['signature_version'],
            'nonce_hash' => $event['nonce_hash'],
            'payload_hash' => $event['payload_hash'],
            'status' => 'processing',
            'received_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        if ((int) $affected > 0) {
            return (int) ZjmfHostSyncEvent::query()
                ->where('event_key', $event['event_key'])
                ->value('id');
        }

        if ($event['nonce_hash'] !== null && ZjmfHostSyncEvent::query()
            ->where('service_id', $serviceId)
            ->where('nonce_hash', $event['nonce_hash'])
            ->lockForUpdate()
            ->exists()) {
            throw new BusinessException('签名验证失败', 42200);
        }

        $existing = ZjmfHostSyncEvent::query()
            ->where('event_key', $event['event_key'])
            ->lockForUpdate()
            ->first();
        if ($existing !== null) {
            return null;
        }

        throw new \RuntimeException('主机同步事件未能原子抢占');
    }

    /**
     * 事件键优先使用上游显式 event_id/request_id；没有稳定事件号时，
     * 使用去掉验签元数据后的业务载荷指纹。v2 与 legacy 通过版本前缀隔离。
     *
     * @param  array<string, mixed>  $payload
     * @return array{event_key: string, signature_version: string, nonce_hash: ?string, payload_hash: string}
     */
    private function eventDescriptor(array $payload, int $serviceId): array
    {
        $isV2 = ZjmfSyncSignature::isV2($payload);
        $signatureVersion = $isV2 ? ZjmfSyncSignature::VERSION_V2 : 'legacy';
        $eventId = trim((string) ($payload['event_id'] ?? $payload['request_id'] ?? ''));

        $businessPayload = $payload;
        unset(
            $businessPayload['signature'],
            $businessPayload['token'],
            $businessPayload['signature_version'],
            $businessPayload['timestamp'],
            $businessPayload['nonce'],
            $businessPayload['rand_str'],
        );
        $businessHash = hash('sha256', ZjmfSyncSignature::canonicalPayload($businessPayload));
        $payloadHash = hash('sha256', ZjmfSyncSignature::canonicalPayload($payload));
        $identity = $eventId !== '' ? 'event:'.$eventId : 'payload:'.$businessHash;
        $eventKey = hash('sha256', 'host-sync|'.$signatureVersion.'|'.$serviceId.'|'.$identity);
        $nonce = strtolower(trim((string) ($payload['nonce'] ?? '')));

        return [
            'event_key' => $eventKey,
            'signature_version' => $signatureVersion,
            'nonce_hash' => $isV2 && $nonce !== '' ? hash('sha256', $nonce) : null,
            'payload_hash' => $payloadHash,
        ];
    }

    /** @param array<string, mixed> $payload */
    private function applyPayload(Service $service, array $payload): void
    {
        $serviceId = (int) $service->id;

        $provisionData = is_array($service->provision_data ?? null) ? $service->provision_data : [];

        if ((int) ($payload['host_id'] ?? 0) > 0) {
            $provisionData['upstream_host_id'] = (int) $payload['host_id'];
        }
        if (array_key_exists('dedicatedip', $payload)) {
            $provisionData['dedicated_ip'] = trim((string) $payload['dedicatedip']);
        }
        $assignedIps = $this->normalizeAssignedIps($payload['assignedips'] ?? null);
        if ($assignedIps !== null) {
            $provisionData['assigned_ips'] = $assignedIps;
        }
        $os = trim((string) ($payload['os'] ?? ''));
        if ($os !== '') {
            $provisionData['os'] = $os;
        }

        $connection = $this->mergeConnection($provisionData, $payload);
        if ($connection !== null) {
            $provisionData['connection_secret'] = Crypt::encryptString(
                (string) json_encode($connection, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
            );
            $provisionData['connection_cached_at'] = now()->format('Y-m-d H:i:s');
            $provisionData['connection_cached_hostname'] = (string) $connection['hostname'];
        }

        $updates = ['provision_data' => $provisionData];

        $domainStatus = strtolower(trim((string) ($payload['domainstatus'] ?? '')));
        if ($domainStatus !== '') {
            $updates['status'] = $this->mapStatus($domainStatus);
            if ($updates['status'] === ServiceStatus::SUSPENDED) {
                $reason = trim((string) ($payload['suspendreason'] ?? ''));
                if ($reason !== '') {
                    $updates['suspended_reason'] = $reason;
                }
            }
        }

        $nextDueDate = $this->resolveNextDueDate($payload['nextduedate'] ?? null);
        if ($nextDueDate instanceof Carbon) {
            $updates['expires_at'] = $nextDueDate;
        }

        $domain = trim((string) ($payload['domain'] ?? ''));
        if ($domain !== '') {
            $updates['domain'] = $domain;
        }

        $service->fill($updates)->save();
    }

    private function mapStatus(string $domainStatus): int
    {
        return match ($domainStatus) {
            'active' => ServiceStatus::ACTIVE,
            'suspended' => ServiceStatus::SUSPENDED,
            'cancelled', 'deleted' => ServiceStatus::CANCELLED,
            default => ServiceStatus::PENDING,
        };
    }

    private function resolveNextDueDate(mixed $value): ?Carbon
    {
        $raw = trim((string) ($value ?? ''));
        if ($raw === '' || str_starts_with($raw, '0000')) {
            return null;
        }

        try {
            if (ctype_digit($raw) && (int) $raw > 0) {
                return Carbon::createFromTimestamp((int) $raw, config('app.timezone'));
            }

            return Carbon::parse($raw, config('app.timezone'));
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * 魔方 assignedips 在推送中是分隔字符串（历史 host/header 契约要求数组），
     * 统一归一化为 IP 数组后落 provision_data。
     */
    private function normalizeAssignedIps(mixed $value): ?array
    {
        if (is_array($value)) {
            $items = $value;
        } else {
            $raw = trim((string) ($value ?? ''));
            if ($raw === '') {
                return null;
            }
            $items = preg_split('/[,\s]+/u', $raw) ?: [];
        }

        $items = array_values(array_filter(
            array_map(static fn (mixed $item): string => trim((string) $item), $items),
            static fn (string $item): bool => $item !== ''
        ));

        return $items === [] ? null : $items;
    }

    /**
     * 合并推送连接信息与本地加密缓存：空字段沿用现值，避免推送缺字段时清空已有凭据。
     * 全空且无现值时返回 null（无需写 connection_secret）。
     */
    private function mergeConnection(array $provisionData, array $payload): ?array
    {
        $current = $this->readCachedConnection($provisionData);

        $merged = [
            'hostname' => trim((string) ($payload['domain'] ?? '')),
            'username' => trim((string) ($payload['username'] ?? '')),
            'password' => (string) ($payload['password'] ?? ''),
            'port' => (int) ($payload['port'] ?? 0),
            'internal_ip' => '',
        ];

        foreach (['hostname', 'username', 'password'] as $key) {
            if ($merged[$key] === '' && isset($current[$key])) {
                $merged[$key] = (string) $current[$key];
            }
        }
        if ($merged['port'] <= 0) {
            $merged['port'] = (int) (($current['port'] ?? 0) ?: 0);
        }
        $merged['internal_ip'] = (string) ($current['internal_ip'] ?? '');

        if (implode('|', $merged) === '|||0|' && $current === []) {
            return null;
        }

        return $merged;
    }

    /**
     * @return array<string, mixed>
     */
    private function readCachedConnection(array $provisionData): array
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
}
