<?php

namespace App\Jobs;

use App\Models\Supplier;
use App\Services\Supplier\SupplierBalanceService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * 单个供应商的上游余额刷新。
 *
 * 由上游开通完成后触发：开通才是真正扣减上游余额的动作，此时刷新拿到的才是
 * 消耗后的真实余额（若在支付成功、开通之前刷新，读到的仍是扣减前的旧值）。
 *
 * 这是定时同步之外的"额外一次"，不影响 15 分钟定时任务的节奏——定时任务不看
 * "最近是否刚同步过"，到点照常执行。
 */
class SyncSupplierBalanceJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 2;

    public int $timeout = 120;

    public int $uniqueFor = 180;

    public array $backoff = [30, 90];

    public function __construct(public int $supplierId)
    {
        $this->onQueue('provision');
        $this->afterCommit();
    }

    public function middleware(): array
    {
        return [
            (new WithoutOverlapping("job:supplier-balance-sync:{$this->supplierId}"))
                ->releaseAfter(10)
                ->expireAfter(180),
        ];
    }

    public function uniqueId(): string
    {
        return (string) $this->supplierId;
    }

    public function handle(SupplierBalanceService $balanceService): void
    {
        $supplier = Supplier::query()->find($this->supplierId);
        if (! $supplier instanceof Supplier) {
            return;
        }

        $balanceService->sync($supplier);
    }

    public function failed(\Throwable $exception): void
    {
        // 余额刷新失败不该影响任何业务：只记日志，下一个定时槽会自动补上。
        Log::warning('[上游余额同步] 队列任务失败', [
            'supplier_id' => $this->supplierId,
            'message' => $exception->getMessage(),
            'exception' => $exception::class,
        ]);
    }
}
