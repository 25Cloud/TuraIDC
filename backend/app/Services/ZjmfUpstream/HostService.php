<?php

declare(strict_types=1);

namespace App\Services\ZjmfUpstream;

use App\Constants\ServiceStatus;
use App\Models\Service;
use App\Models\User;
use App\Services\Provisioning\ServiceRenewService;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Log;

/**
 * 上游主机接口（被魔方财务对接）：/host/header、/host/renew、/host/cancel。
 *
 * 魔方财务把 TuraIDC 的 Service id 存为本地 host.dcimid，
 * 因此入参 host_id / hostid / id 即 TuraIDC Service id。
 * 上游侧的「同步」即 GET /host/header（协议中没有独立的 upstream /host/sync 路由）。
 */
class HostService
{
    private const DOMAIN_STATUS_MAP = [
        ServiceStatus::PENDING => 'Pending',
        ServiceStatus::ACTIVE => 'Active',
        ServiceStatus::SUSPENDED => 'Suspended',
        ServiceStatus::EXPIRED => 'Expired',
        ServiceStatus::CANCELLED => 'Deleted',
    ];

    public function __construct(
        private readonly ServiceRenewService $renewService,
    ) {}

    /**
     * host/header：返回主机头信息，供下游同步本地 host 记录与渲染控制台。
     *
     * @return array<string, mixed>
     */
    public function header(User $user, int $serviceId): array
    {
        $service = $this->findUserService($user, $serviceId);
        if (! $service instanceof Service) {
            return ['status' => 400, 'msg' => '服务不存在'];
        }

        return [
            'status' => 200,
            'msg' => '请求成功',
            'data' => [
                'host_data' => $this->buildHostData($service),
                // 魔方财务控制台渲染点：全部置空，功能入口统一走 P5 /dcim/* 接口。
                'module_button' => ['control' => [], 'console' => []],
                'module_client_area' => [],
                'module_chart' => [],
                'module_client_main_area' => [],
                'dcimcloud' => ['nat_acl' => '', 'nat_web' => ''],
                'dcim' => ['flowpacket' => []],
                'module_power_status' => false,
                'reinstall_random_port' => false,
            ],
        ];
    }

    /**
     * host/renew：为下游主机创建续费账单，返回 invoiceid 供下游 apply_credit 支付。
     *
     * @return array<string, mixed>
     */
    public function renew(User $user, int $serviceId, string $billingCycle): array
    {
        $service = $this->findUserService($user, $serviceId);
        if (! $service instanceof Service) {
            return ['status' => 400, 'msg' => '服务不存在'];
        }
        if (trim($billingCycle) === '') {
            return ['status' => 400, 'msg' => '计费周期不能为空'];
        }

        try {
            $invoice = $this->renewService->createRenewInvoiceForUser(
                $user,
                $serviceId,
                trim($billingCycle),
            );

            return [
                'status' => 200,
                'msg' => '续费账单已创建',
                'data' => [
                    'invoiceid' => (int) $invoice->id,
                ],
            ];
        } catch (\Throwable $exception) {
            Log::warning('[zjmf-upstream] 创建续费账单失败', [
                'user_id' => (int) $user->id,
                'service_id' => $serviceId,
                'billing_cycle' => $billingCycle,
                'error' => $exception->getMessage(),
            ]);

            return ['status' => 400, 'msg' => $exception->getMessage()];
        }
    }

    /**
     * host/cancel：删除主机（下游在结算删除时调用，type 为 Immediate）。
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public function cancel(User $user, int $serviceId, array $data): array
    {
        $service = $this->findUserService($user, $serviceId);
        if (! $service instanceof Service) {
            return ['status' => 400, 'msg' => '服务不存在'];
        }

        $reason = trim((string) ($data['reason'] ?? ''));
        $service->forceFill([
            'status' => ServiceStatus::CANCELLED,
            'suspended_reason' => $reason !== '' ? $reason : null,
        ])->save();

        return ['status' => 200, 'msg' => '删除成功'];
    }

    private function findUserService(User $user, int $serviceId): ?Service
    {
        return Service::query()
            ->where('user_id', (int) $user->id)
            ->find($serviceId);
    }

    /**
     * 组装魔方财务 host_data 字段（字段名与下游 Host::sync 读取点对齐）。
     *
     * @return array<string, mixed>
     */
    private function buildHostData(Service $service): array
    {
        $provisionData = is_array($service->provision_data ?? null) ? $service->provision_data : [];
        $connection = $this->readCachedConnection($provisionData);
        $expiresAt = 0;
        if ($service->expires_at !== null) {
            $expiresAt = (int) strtotime((string) $service->expires_at);
        }

        return [
            'id' => (int) $service->id,
            'domain' => (string) ($service->domain ?? ''),
            'dedicatedip' => (string) ($provisionData['dedicated_ip'] ?? ''),
            'assignedips' => is_array($provisionData['assigned_ips'] ?? null)
                ? array_values($provisionData['assigned_ips'])
                : [],
            'bwlimit' => (int) ($provisionData['bw_limit'] ?? 0),
            'bwusage' => (float) ($provisionData['bw_usage'] ?? 0),
            'username' => (string) ($connection['username'] ?? ''),
            'password' => (string) ($connection['password'] ?? ''),
            'port' => (int) (($connection['port'] ?? 0) ?: 0),
            'os' => (string) ($provisionData['os'] ?? ''),
            'domainstatus' => $this->domainStatus((int) $service->status),
            'amount' => (float) ($service->amount ?? 0),
            'nextduedate' => $expiresAt,
        ];
    }

    /**
     * 解密缓存的连接凭据（与服务控制台 readCachedConnection 同构）。
     *
     * @param  array<string, mixed>  $provisionData
     * @return array<string, mixed>
     */
    private function readCachedConnection(array $provisionData): array
    {
        $defaults = [
            'hostname' => '',
            'username' => '',
            'password' => '',
            'port' => 0,
            'internal_ip' => '',
        ];

        $payload = trim((string) ($provisionData['connection_secret'] ?? ''));
        if ($payload === '') {
            return $defaults;
        }

        try {
            $decoded = json_decode(Crypt::decryptString($payload), true);

            return is_array($decoded)
                ? array_replace($defaults, array_intersect_key($decoded, $defaults))
                : $defaults;
        } catch (\Throwable) {
            return $defaults;
        }
    }

    private function domainStatus(int $status): string
    {
        return self::DOMAIN_STATUS_MAP[$status] ?? 'Pending';
    }
}
