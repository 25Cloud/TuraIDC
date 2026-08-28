<?php

declare(strict_types=1);

namespace App\Services\ZjmfUpstream;

use App\Constants\InvoiceStatus;
use App\Constants\ServiceStatus;
use App\Models\Invoice;
use App\Models\Order;
use App\Models\Service;
use App\Models\User;
use App\Services\Provisioning\ProvisionService;
use Illuminate\Support\Facades\Log;

/**
 * 上游模块命令（被魔方财务对接）：/provision/default。
 *
 * 魔方财务通过 func 分发：create/suspend/unsuspend/terminate 等，
 * id 为 TuraIDC 的 Service id（魔方财务本地存为 dcimid）。
 * 下游只做状态管理，供应商侧开通走 TuraIDC 自身 ProvisionService。
 */
class UpstreamProvisionService
{
    public function __construct(
        private readonly ProvisionService $provisioning,
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
            default => ['status' => 200, 'msg' => '命令已受理'],
        };
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
        $reason = trim((string) ($data['reason'] ?? ''));
        $service->forceFill([
            'status' => ServiceStatus::SUSPENDED,
            'suspended_reason' => $reason !== '' ? $reason : null,
        ])->save();

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

        return ['status' => 200, 'msg' => '解除暂停成功'];
    }

    /**
     * @return array{status:int,msg:string}
     */
    private function terminate(Service $service): array
    {
        $service->forceFill(['status' => ServiceStatus::CANCELLED])->save();

        return ['status' => 200, 'msg' => '已删除'];
    }
}
