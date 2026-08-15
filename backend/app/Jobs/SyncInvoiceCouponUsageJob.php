<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\Invoice;
use App\Services\Finance\CouponService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * 按账单当前状态异步同步优惠券占用，避免阻塞支付响应链路。
 */
class SyncInvoiceCouponUsageJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 3;

    public int $timeout = 300;

    public array $backoff = [30, 120, 300];

    public function __construct(public int $invoiceId)
    {
        $this->onQueue('coupon');
        $this->afterCommit();
    }

    public function middleware(): array
    {
        return [
            (new WithoutOverlapping("job:invoice-coupon:{$this->invoiceId}"))
                ->releaseAfter(10)
                ->expireAfter(600),
        ];
    }

    public function handle(CouponService $service): void
    {
        $invoice = Invoice::query()->find($this->invoiceId);

        if ($invoice instanceof Invoice) {
            $service->syncInvoiceCouponUsage($invoice);
        }
    }

    public function failed(\Throwable $exception): void
    {
        Log::error('[账单优惠券同步] 队列任务失败', [
            'invoice_id' => $this->invoiceId,
            'message' => $exception->getMessage(),
            'exception' => $exception::class,
        ]);

        // 补偿：失败后同步执行一次同步，缩小券重复占用的双花窗口；
        // 同步仍失败时仅记日志，reserve 侧的"已支付账单"检查兜底拦截二次占用
        try {
            app(CouponService::class)->syncInvoiceCouponUsage(
                Invoice::query()->find($this->invoiceId)
            );
        } catch (\Throwable $fallbackException) {
            Log::error('[账单优惠券同步] 失败补偿同步未成功', [
                'invoice_id' => $this->invoiceId,
                'message' => $fallbackException->getMessage(),
                'exception' => $fallbackException::class,
            ]);
        }
    }
}
