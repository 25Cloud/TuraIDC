<?php

namespace App\Jobs;

use App\Services\Finance\PaymentService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class SyncPaidInvoiceCouponUsageJob implements ShouldQueue
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

    /**
     * 队列中间件：按账单加互斥锁，避免同一账单的优惠券占用同步并发执行。
     *
     * 互斥键与 SyncInvoiceCouponUsageJob 共用——两者对同一账单做的是同一件事，
     * 各用一把锁则彼此不互斥。
     *
     * @return array<int, WithoutOverlapping>
     */
    public function middleware(): array
    {
        return [
            // 与 SyncInvoiceCouponUsageJob 共用同一互斥键：两者对同一账单做同一件事
            // （同步优惠券占用），此前各用一把锁（job:paid-invoice-coupon: / job:invoice-coupon:），
            // 一旦本任务被接入调度，同一账单的两个任务不会互斥，优惠券占用同步会并发。
            (new WithoutOverlapping("job:invoice-coupon:{$this->invoiceId}"))
                ->releaseAfter(10)
                ->expireAfter(600),
        ];
    }

    public function handle(PaymentService $paymentService): void
    {
        $paymentService->processPaidInvoiceCouponSyncById($this->invoiceId);
    }

    public function failed(\Throwable $exception): void
    {
        Log::error('[支付账单优惠券同步] 队列任务失败', [
            'invoice_id' => $this->invoiceId,
            'message' => $exception->getMessage(),
            'exception' => $exception::class,
        ]);
    }
}
