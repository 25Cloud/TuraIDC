<?php

declare(strict_types=1);

namespace App\Services\ZjmfUpstream;

use App\Models\Service;
use App\Models\User;
use App\Services\ClientServiceConsole\ServicePowerService;
use App\Services\ClientServiceConsole\ServiceVncService;
use Illuminate\Support\Facades\Log;

/**
 * 上游 dcim 控制接口（被魔方财务对接）。
 *
 * 魔方财务把 TuraIDC 的 Service id 存为本地 host.dcimid，入参 id 即 Service id。
 * 电源/救援/密码/重装/状态等复用服务控制台既有能力；KVM/BMC/流量图等
 * 供应商协议未覆盖的能力返回 400 业务失败，魔方财务会按不支持处理。
 */
class DcimService
{
    public function __construct(
        private readonly ServicePowerService $power,
        private readonly ServiceVncService $vnc,
    ) {}

    public function on(User $user, int $serviceId): array
    {
        return $this->safeRun(fn () => $this->power->powerActionForUser($user, $serviceId, 'on'), '开机失败');
    }

    public function off(User $user, int $serviceId): array
    {
        return $this->safeRun(fn () => $this->power->powerActionForUser($user, $serviceId, 'off'), '关机失败');
    }

    public function reboot(User $user, int $serviceId): array
    {
        return $this->safeRun(fn () => $this->power->powerActionForUser($user, $serviceId, 'reboot'), '重启失败');
    }

    public function hardOff(User $user, int $serviceId): array
    {
        return $this->safeRun(fn () => $this->power->powerActionForUser($user, $serviceId, 'hard_off'), '硬关机失败');
    }

    public function hardReboot(User $user, int $serviceId): array
    {
        return $this->safeRun(fn () => $this->power->powerActionForUser($user, $serviceId, 'hard_reboot'), '硬重启失败');
    }

    public function novnc(User $user, int $serviceId): array
    {
        try {
            $result = $this->vnc->getVncUrlForUser($user, $serviceId);
            $credentials = is_array($result['vnc_credentials'] ?? null) ? $result['vnc_credentials'] : [];
            $url = (string) ($result['url'] ?? '');

            return [
                'status' => 200,
                'msg' => (string) ($result['message'] ?? '获取VNC链接成功'),
                'url' => $url,
                'data' => [
                    'url' => $url,
                    'password' => (string) ($credentials['password'] ?? ''),
                ],
            ];
        } catch (\Throwable $exception) {
            return ['status' => 400, 'msg' => $exception->getMessage() ?: 'vnc启动失败'];
        }
    }

    public function rescue(User $user, int $serviceId, string $system): array
    {
        $system = in_array($system, ['1', '2'], true) ? $system : '1';

        return $this->safeRun(
            fn () => $this->power->rescueForUser($user, $serviceId, ['system' => $system]),
            '救援系统发起失败'
        );
    }

    public function crackPass(User $user, int $serviceId, string $password): array
    {
        if (trim($password) === '') {
            return ['status' => 400, 'msg' => '密码不能为空'];
        }

        return $this->safeRun(
            fn () => $this->power->resetPasswordForUser($user, $serviceId, ['password' => $password]),
            '重置密码发起失败'
        );
    }

    public function reinstall(User $user, int $serviceId, string $osId): array
    {
        if (trim($osId) === '') {
            return ['status' => 400, 'msg' => '操作系统错误'];
        }

        return $this->safeRun(
            fn () => $this->power->reinstallForUser($user, $serviceId, ['os_id' => $osId]),
            '重装系统发起失败'
        );
    }

    /**
     * 取消进行中的任务：TuraIDC 无任务取消语义，直接受理并返回重装类型。
     */
    public function cancelTask(User $user, int $serviceId): array
    {
        if (! $this->findUserService($user, $serviceId)) {
            return ['status' => 400, 'msg' => '服务不存在'];
        }

        return ['status' => 200, 'msg' => '取消成功', 'task_type' => 0];
    }

    /**
     * 重装状态查询：映射魔方财务 last_result 结构。
     *
     * @return array<string, mixed>
     */
    public function reinstallStatus(User $user, int $serviceId): array
    {
        try {
            $status = $this->power->getModuleStatusForUser($user, $serviceId, 'reinstall');

            return [
                'status' => 200,
                'msg' => '获取成功',
                'data' => [
                    'progress' => (int) ($status['progress'] ?? 0),
                    'task_type' => 0,
                    'last_result' => $this->buildLastResult($status),
                ],
            ];
        } catch (\Throwable $exception) {
            Log::info('[zjmf-upstream] 查询重装状态无进行中任务', [
                'user_id' => (int) $user->id,
                'service_id' => $serviceId,
                'message' => $exception->getMessage(),
            ]);

            return ['status' => 200, 'msg' => '获取成功', 'data' => []];
        }
    }

    /**
     * 交换机列表：TuraIDC 无交换机维度，返回空列表。
     *
     * @return array<string, mixed>
     */
    public function detail(User $user, int $serviceId): array
    {
        if (! $this->findUserService($user, $serviceId)) {
            return ['status' => 400, 'msg' => '服务不存在'];
        }

        return ['status' => 200, 'msg' => '请求成功', 'data' => ['switch' => []]];
    }

    /**
     * 刷新电源状态。
     *
     * @return array<string, mixed>
     */
    public function refreshPowerStatus(User $user, int $serviceId): array
    {
        try {
            $status = $this->power->getModuleStatusForUser($user, $serviceId, 'host');
            $power = strtolower(trim((string) ($status['status'] ?? 'unknown')));

            return [
                'status' => 200,
                'msg' => '刷新成功',
                'data' => [
                    'status' => $power,
                    'des' => $this->powerDescription($power),
                    'power' => $power,
                ],
            ];
        } catch (\Throwable $exception) {
            return ['status' => 400, 'msg' => $exception->getMessage() ?: '刷新失败'];
        }
    }

    /**
     * 批量刷新电源状态（管理页列表用）。
     *
     * @param  array<int, int>  $serviceIds
     * @return array<string, mixed>
     */
    public function refreshAllPowerStatus(User $user, array $serviceIds): array
    {
        $list = [];
        foreach ($serviceIds as $serviceId) {
            $serviceId = (int) $serviceId;
            try {
                $status = $this->power->getModuleStatusForUser($user, $serviceId, 'host');
                $power = strtolower(trim((string) ($status['status'] ?? 'unknown')));
                $list[] = [
                    'id' => $serviceId,
                    'status' => $power,
                    'msg' => $this->powerDescription($power),
                ];
            } catch (\Throwable $exception) {
                $list[] = [
                    'id' => $serviceId,
                    'status' => 'error',
                    'msg' => '获取失败',
                ];
            }
        }

        return ['status' => 200, 'msg' => '请求成功', 'data' => $list];
    }

    /**
     * 隐藏上次任务结果：无持久化任务结果，直接受理。
     */
    public function hideResult(User $user, int $serviceId): array
    {
        if (! $this->findUserService($user, $serviceId)) {
            return ['status' => 400, 'msg' => '服务不存在'];
        }

        return ['status' => 200, 'msg' => '操作成功'];
    }

    /**
     * 重装前检查：TuraIDC 不限制次数，直接放行。
     *
     * @return array<string, mixed>
     */
    public function checkReinstall(User $user, int $serviceId): array
    {
        if (! $this->findUserService($user, $serviceId)) {
            return ['status' => 400, 'msg' => '服务不存在'];
        }

        return ['status' => 200, 'msg' => '可以重装', 'max_times' => 0];
    }

    public function traffic(): array
    {
        return ['status' => 400, 'msg' => '不支持流量图', 'data' => ['support' => false]];
    }

    public function trafficUsage(): array
    {
        return ['status' => 400, 'msg' => '不支持流量统计'];
    }

    public function kvm(): array
    {
        return ['status' => 400, 'msg' => '当前实例不支持KVM'];
    }

    public function ikvm(): array
    {
        return ['status' => 400, 'msg' => '当前实例不支持iKVM'];
    }

    public function bmc(): array
    {
        return ['status' => 400, 'msg' => '当前实例不支持重置BMC'];
    }

    public function buyReinstallTimes(): array
    {
        return ['status' => 400, 'msg' => '暂不支持购买重装次数'];
    }

    public function buyFlowPacket(): array
    {
        return ['status' => 400, 'msg' => '暂不支持购买流量包'];
    }

    private function findUserService(User $user, int $serviceId): ?Service
    {
        return Service::query()
            ->where('user_id', (int) $user->id)
            ->find($serviceId);
    }

    /**
     * 转换供应商状态载荷为魔方财务 last_result。
     *
     * @param  array<string, mixed>  $status
     * @return array{act:string,status:int,msg:string}|null
     */
    private function buildLastResult(array $status): ?array
    {
        if (empty($status['is_finished'])) {
            return null;
        }

        return [
            'act' => (string) ($status['type_label'] ?? '重装系统'),
            'status' => ! empty($status['is_success']) ? 1 : 2,
            'msg' => ! empty($status['is_success']) ? '成功' : '失败',
        ];
    }

    private function powerDescription(string $power): string
    {
        return match ($power) {
            'on' => '开机',
            'off' => '关机',
            'suspend', 'suspended' => '暂停',
            'waiting', 'wait_reboot' => '等待重启',
            'process', 'task' => '任务执行中',
            'paused' => '挂起',
            default => '未知',
        };
    }

    /**
     * @param  callable(): array<string, mixed>  $callback
     * @return array{status:int,msg:string}
     */
    private function safeRun(callable $callback, string $failMessage): array
    {
        try {
            $result = $callback();

            return [
                'status' => 200,
                'msg' => (string) ($result['message'] ?? '操作成功'),
            ];
        } catch (\Throwable $exception) {
            Log::warning('[zjmf-upstream] dcim 命令执行失败', [
                'error' => $exception->getMessage(),
            ]);

            return ['status' => 400, 'msg' => $exception->getMessage() ?: $failMessage];
        }
    }
}
