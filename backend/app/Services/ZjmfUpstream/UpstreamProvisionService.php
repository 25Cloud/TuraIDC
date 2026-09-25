<?php

declare(strict_types=1);

namespace App\Services\ZjmfUpstream;

use App\Constants\InvoiceStatus;
use App\Constants\ServiceStatus;
use App\Jobs\PushServiceToZjmfDownstreamJob;
use App\Models\Invoice;
use App\Models\Order;
use App\Models\Service;
use App\Models\User;
use App\Services\ClientServiceConsole\ServicePowerService;
use App\Services\ClientServiceConsole\ServiceVncService;
use App\Services\Provisioning\ProvisionService;
use App\Services\ZjmfUpstream\ZjmfDownstreamPushService;
use Illuminate\Support\Facades\Log;

/**
 * 上游模块命令（被魔方财务对接）：/provision/default。
 *
 * 魔方财务通过 func 分发全部模块命令：create/suspend/unsuspend/terminate 走生命周期，
 * on/off/reboot/hard_off/hard_reboot/vnc/status/reinstall/crack_pass/rescueSystem
 * （参考服务端拼写为 rescue_system，两种都接受）走实例操作。
 * id 为 TuraIDC 的 Service id（魔方财务本地存为 dcimid）。
 *
 * 未知 func 必须返回业务失败：魔方对 status=200 一律判定命令成功，
 * 静默受理会让面板按钮"点了没反应"且无任何提示。
 */
class UpstreamProvisionService
{
    public function __construct(
        private readonly ProvisionService $provisioning,
        private readonly ServicePowerService $power,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     * @return array{status:int,msg:string}
     */
    public function execute(User $user, array $data): array
    {
        $serviceId = (int) ($data['id'] ?? 0);
        $func = strtolower(trim((string) ($data['func'] ?? '')));

        $service = Service::query()
            ->where('user_id', (int) $user->id)
            ->find($serviceId);

        if (! $service instanceof Service) {
            return ['status' => 400, 'msg' => '服务不存在'];
        }

        return match ($func) {
            'create' => $this->create($service),
            'suspend' => $this->suspend($service, $data),
            'unsuspend' => $this->unsuspend($service),
            'terminate' => $this->terminate($service),

            // 电源动作：动作键与 ClientServiceConsoleService::POWER_ACTIONS 一致
            'on', 'off', 'reboot', 'hard_off', 'hard_reboot' => $this->powerAction($service, $func),

            // VNC：魔方读取 data.url
            'vnc' => $this->vnc($user, $service),

            // 电源状态：魔方读取 data.status + data.des
            'status' => $this->status($user, $service),

            // 重装：魔方传 os（上游 OS id）与可选 port/format_data_disk
            'reinstall' => $this->reinstall($user, $service, $data),

            // 改密：魔方传 password
            'crack_pass' => $this->crackPass($user, $service, $data),

            // 救援系统：客户端发 rescueSystem，参考服务端认 rescue_system
            'rescuesystem', 'rescue_system' => $this->rescue($user, $service, $data),

            default => ['status' => 400, 'msg' => '不支持的模块命令：'.$func],
        };
    }

    /**
     * 实例操作统一异常兜底：内层服务抛出的业务异常转成协议内的 400 + 中文 msg。
     *
     * @param  callable(): array<string, mixed>  $callback
     * @return array<string, mixed>
     */
    private function run(callable $callback, string $failMessage): array
    {
        try {
            return $callback();
        } catch (\Throwable $exception) {
            Log::warning('[zjmf-upstream] 模块命令失败', [
                'message' => $exception->getMessage(),
                'exception' => $exception::class,
            ]);

            return [
                'status' => 400,
                'msg' => $exception->getMessage() !== '' ? $exception->getMessage() : $failMessage,
            ];
        }
    }

    /**
     * @return array{status:int,msg:string}
     */
    private function powerAction(Service $service, string $action): array
    {
        $user = $service->user;
        if (! $user instanceof User) {
            return ['status' => 400, 'msg' => '服务所属用户不存在'];
        }

        $result = $this->run(
            fn (): array => $this->power->powerActionForUser($user, (int) $service->id, $action),
            '电源指令提交失败'
        );

        if ((int) ($result['status'] ?? 0) === 400) {
            return ['status' => 400, 'msg' => (string) ($result['msg'] ?? '电源指令提交失败')];
        }

        return ['status' => 200, 'msg' => (string) ($result['message'] ?? '指令已提交')];
    }

    /**
     * @return array{status:int,msg:string,data?:array<string,mixed>}
     */
    private function vnc(User $user, Service $service): array
    {
        $result = $this->run(
            fn (): array => app(ServiceVncService::class)->getVncUrlForUser($user, (int) $service->id),
            '获取VNC链接失败'
        );

        if ((int) ($result['status'] ?? 0) === 400) {
            return ['status' => 400, 'msg' => (string) ($result['msg'] ?? '获取VNC链接失败')];
        }

        $url = trim((string) ($result['url'] ?? ''));
        if ($url === '') {
            return ['status' => 400, 'msg' => '上游未返回VNC链接'];
        }

        return [
            'status' => 200,
            'msg' => (string) ($result['message'] ?? '获取VNC链接成功'),
            'data' => ['url' => $url],
        ];
    }

    /**
     * @return array{status:int,msg:string,data:array<string,mixed>}
     */
    private function status(User $user, Service $service): array
    {
        $result = $this->run(
            fn (): array => $this->power->getModuleStatusForUser($user, (int) $service->id, 'host'),
            '状态读取失败'
        );

        if ((int) ($result['status'] ?? 0) === 400) {
            return [
                'status' => 200,
                'msg' => (string) ($result['msg'] ?? '状态读取失败'),
                'data' => ['status' => 'unknown', 'des' => '未知'],
            ];
        }

        $state = strtolower(trim((string) ($result['status'] ?? '')));
        $description = trim((string) ($result['description'] ?? ''));

        return [
            'status' => 200,
            'msg' => $description,
            'data' => [
                'status' => $state !== '' ? $state : 'unknown',
                'des' => $description !== '' ? $description : '未知',
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array{status:int,msg:string}
     */
    private function reinstall(User $user, Service $service, array $data): array
    {
        $osId = trim((string) ($data['os'] ?? $data['os_id'] ?? ''));
        if ($osId === '' || $osId === '0') {
            return ['status' => 400, 'msg' => '请选择操作系统'];
        }

        $result = $this->run(
            fn (): array => $this->power->reinstallForUser($user, (int) $service->id, ['os_id' => $osId]),
            '重装系统发起失败'
        );

        if ((int) ($result['status'] ?? 0) === 400) {
            return ['status' => 400, 'msg' => (string) ($result['msg'] ?? '重装系统发起失败')];
        }

        return ['status' => 200, 'msg' => (string) ($result['message'] ?? '重装系统任务已提交')];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array{status:int,msg:string}
     */
    private function crackPass(User $user, Service $service, array $data): array
    {
        $password = trim((string) ($data['password'] ?? ''));
        if ($password === '') {
            return ['status' => 400, 'msg' => '密码不能为空'];
        }

        $result = $this->run(
            fn (): array => $this->power->resetPasswordForUser($user, (int) $service->id, ['password' => $password]),
            '重置密码发起失败'
        );

        if ((int) ($result['status'] ?? 0) === 400) {
            return ['status' => 400, 'msg' => (string) ($result['msg'] ?? '重置密码发起失败')];
        }

        return ['status' => 200, 'msg' => (string) ($result['message'] ?? '重置密码指令已提交')];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array{status:int,msg:string}
     */
    private function rescue(User $user, Service $service, array $data): array
    {
        $system = trim((string) ($data['system'] ?? '1'));
        $system = in_array($system, ['1', '2'], true) ? $system : '1';

        $result = $this->run(
            fn (): array => $this->power->rescueForUser($user, (int) $service->id, ['system' => $system]),
            '进入救援模式失败'
        );

        if ((int) ($result['status'] ?? 0) === 400) {
            return ['status' => 400, 'msg' => (string) ($result['msg'] ?? '进入救援模式失败')];
        }

        return ['status' => 200, 'msg' => (string) ($result['message'] ?? '救援模式指令已提交')];
    }

    /**
     * @return array{status:int,msg:string}
     */
    private function create(Service $service): array
    {
        $status = (int) $service->status;

        if ($status === ServiceStatus::ACTIVE) {
            return ['status' => 200, 'msg' => '服务已开通'];
        }

        // 仅允许「开通中」的服务被 create 指令开通。
        // 暂停(SUSPENDED)/到期(EXPIRED)/取消(CANCELLED)的服务不可用该指令复活，避免免费续期与复活已取消服务。
        if ($status !== ServiceStatus::PENDING) {
            return ['status' => 400, 'msg' => '服务状态不支持开通'];
        }

        // 账单已取消/已退款时拒绝开通，防止通过该指令复活已撤销的订单。
        $invoice = $service->invoice;
        if ($invoice instanceof Invoice) {
            $invoiceStatus = (int) $invoice->status;
            if (in_array($invoiceStatus, [InvoiceStatus::CANCELLED, InvoiceStatus::REFUNDED, InvoiceStatus::PARTIALLY_REFUNDED], true)) {
                return ['status' => 400, 'msg' => '账单已取消或已退款，无法开通'];
            }
        }

        try {
            if ($invoice instanceof Invoice) {
                $this->provisioning->processPaidInvoice($invoice);
            } elseif ($service->order instanceof Order) {
                $this->provisioning->processPaidOrder($service->order);
            } else {
                return ['status' => 400, 'msg' => '服务缺少关联账单'];
            }
        } catch (\Throwable $exception) {
            Log::warning('[zjmf-upstream] 开通失败', [
                'service_id' => (int) $service->id,
                'error' => $exception->getMessage(),
            ]);

            return ['status' => 400, 'msg' => $exception->getMessage()];
        }

        return ['status' => 200, 'msg' => '开通指令已受理'];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array{status:int,msg:string}
     */
    private function suspend(Service $service, array $data): array
    {
        $status = (int) $service->status;

        // 已暂停重复受理（下游重试）幂等返回成功；开通中/已到期/已取消不可暂停
        if ($status === ServiceStatus::SUSPENDED) {
            return ['status' => 200, 'msg' => '暂停成功'];
        }

        if ($status !== ServiceStatus::ACTIVE) {
            return ['status' => 400, 'msg' => '服务当前状态不支持暂停'];
        }

        $reason = trim((string) ($data['reason'] ?? ''));

        // 'expired' 是本系统到期欠费停机的保留标记：写入后会同时锁死 unsuspend
        // （provision unsuspend 分支按它拒绝）与自动取消链路（按 SUSPENDED+该标记筛选），
        // 下游传来的同名词必须转义存储
        if ($reason !== '' && $reason === Service::SUSPENDED_REASON_EXPIRED) {
            $reason = 'downstream_expired';
        }

        $service->forceFill([
            'status' => ServiceStatus::SUSPENDED,
            'suspended_reason' => $reason !== '' ? $reason : null,
        ])->save();

        // 旁路通知下游（未登记回推目标时自动跳过）
        PushServiceToZjmfDownstreamJob::dispatch(
            (int) $service->id,
            ZjmfDownstreamPushService::TYPE_SUSPEND
        );

        return ['status' => 200, 'msg' => '暂停成功'];
    }

    /**
     * @return array{status:int,msg:string}
     */
    private function unsuspend(Service $service): array
    {
        if ((int) $service->status !== ServiceStatus::SUSPENDED) {
            return ['status' => 400, 'msg' => '服务当前状态不支持解除暂停'];
        }

        // 到期欠费停机不得由下游解除：这类停机的标记正是 SUSPENDED + suspended_reason='expired'，
        // 只判 status 挡不住它。放行会造成两重后果——服务立刻恢复可用，且 suspended_reason 被清成
        // null 后，ServiceLifecycleAutomationService 的自动取消（按 status=SUSPENDED + 该标记筛选）
        // 再也命中不到它，等于永久规避欠费终止。恢复只能由 TuraIDC 侧在收到续费后自行解除。
        if ((string) $service->suspended_reason === Service::SUSPENDED_REASON_EXPIRED) {
            return ['status' => 400, 'msg' => '服务因到期欠费停机，请续费后由系统自动恢复'];
        }

        $service->forceFill([
            'status' => ServiceStatus::ACTIVE,
            'suspended_reason' => null,
        ])->save();

        PushServiceToZjmfDownstreamJob::dispatch(
            (int) $service->id,
            ZjmfDownstreamPushService::TYPE_UNSUSPEND
        );

        return ['status' => 200, 'msg' => '解除暂停成功'];
    }

    /**
     * @return array{status:int,msg:string}
     */
    private function terminate(Service $service): array
    {
        $service->forceFill(['status' => ServiceStatus::CANCELLED])->save();

        // 删除必须回推：下游删单依赖该推送把本地 host 置 Deleted 并清空凭据
        //（对齐魔方 Host::terminate 成功后的 pushHostInfo），否则下游残留 Active 记录。
        PushServiceToZjmfDownstreamJob::dispatch(
            (int) $service->id,
            ZjmfDownstreamPushService::TYPE_TERMINATE
        );

        return ['status' => 200, 'msg' => '已删除'];
    }
}
