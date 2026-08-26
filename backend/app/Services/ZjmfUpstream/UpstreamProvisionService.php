<?php

declare(strict_types=1);

namespace App\Services\ZjmfUpstream;

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
        if ((int) $service->status === ServiceStatus::ACTIVE) {
            return ['status' => 200, 'msg' => '服务已开通'];
        }

        try {
            $invoice = $service->invoice;
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
