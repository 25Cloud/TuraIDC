<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\Service;
use App\Services\ClientServiceConsole\ServiceDetailService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * 实例详情异步同步任务
 *
 * 详情/运行时状态请求改为"本地快照优先"后，上游同步由本任务在后台完成：
 * 1. 拉取上游主机详情 + 运行时状态 + NAT 远程信息；
 * 2. 写回本地快照（services.provision_data / 关联快照表）；
 * 3. 对比同步前后快照签名，若信息发生变化写入 60 秒"变更标记"，
 *    前端下一次详情请求读取标记后提示用户刷新页面并自动更新。
 */
class ServiceConsoleSyncJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 1;

    public int $timeout = 60;

    public array $backoff = [10];

    public function __construct(public int $serviceId, public int $userId)
    {
        // 使用默认业务队列，避免为单个任务引入新队列导致部署侧需要额外配置 worker
        $this->afterCommit();
    }

    /**
     * @return array<int, WithoutOverlapping>
     */
    public function middleware(): array
    {
        return [
            (new WithoutOverlapping("job:console-sync:{$this->serviceId}"))
                ->releaseAfter(10)
                ->expireAfter(300),
        ];
    }

    public function handle(ServiceDetailService $detailService): void
    {
        $service = Service::query()
            ->with([
                'product:id,product_type,service_type_code,product_group_id,console_template,updated_at,config_options,pricing,purchase_requires',
                'product.productGroup.secondProductGroup.firstProductGroup',
                'product.supplier',
            ])
            ->where('id', $this->serviceId)
            ->where('user_id', $this->userId)
            ->first();

        if (! $service instanceof Service || ! $detailService->canManageService($service)) {
            return;
        }

        try {
            $remote = $detailService->fetchRemoteState($service);

            if (! empty($remote['host']) || ! empty($remote['runtime']) || ! empty($remote['nat'])) {
                $before = $detailService->buildSnapshotSignature($service);
                $detailService->syncServiceFromRemote(
                    $service,
                    $remote['host'] ?? [],
                    $remote['runtime'] ?? [],
                    $remote['nat'] ?? []
                );
                $service->refresh();
                $after = $detailService->buildSnapshotSignature($service);

                if ($before !== $after) {
                    Cache::put(
                        "service_console:changed:{$service->id}",
                        now()->format('Y-m-d H:i:s'),
                        now()->addSeconds(60)
                    );
                }
            }

            $detailService->forgetDetailCaches($service);
        } catch (\Throwable $exception) {
            Log::warning('[实例详情] 异步同步任务失败', [
                'service_id' => $this->serviceId,
                'user_id' => $this->userId,
                'message' => $exception->getMessage(),
                'exception' => $exception::class,
            ]);
        }
    }

    public function failed(\Throwable $exception): void
    {
        Log::error('[实例详情] 异步同步任务异常退出', [
            'service_id' => $this->serviceId,
            'user_id' => $this->userId,
            'message' => $exception->getMessage(),
            'exception' => $exception::class,
        ]);
    }
}
