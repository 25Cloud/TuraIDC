<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\Service;
use App\Services\ZjmfUpstream\ZjmfDownstreamPushService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * 向魔方财务下游推送服务状态（/api/host/sync）。
 *
 * 独立队列任务而不是在生命周期方法里同步请求：推送是旁路通知，
 * 下游不可达时不应拖慢开通/暂停/续费，也不应让主流程失败。
 */
class PushServiceToZjmfDownstreamJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 2;

    public int $timeout = 60;

    public int $backoff = 30;

    public function __construct(
        public int $serviceId,
        public string $type = '',
    ) {
        $this->afterCommit();
    }

    public function handle(ZjmfDownstreamPushService $push): void
    {
        $service = Service::query()->find($this->serviceId);
        if (! $service instanceof Service) {
            return;
        }

        $push->pushForService($service, $this->type);
    }

    public function failed(\Throwable $exception): void
    {
        // 推送失败不影响主流程，仅留痕：下游仍可通过 host/header 主动同步兜底
        Log::warning('[zjmf-upstream] 下游状态推送任务失败', [
            'service_id' => $this->serviceId,
            'type' => $this->type,
            'message' => $exception->getMessage(),
        ]);
    }
}
