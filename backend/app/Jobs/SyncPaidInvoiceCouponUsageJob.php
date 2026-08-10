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

    public function middleware(): array
    {
        return [
            (new WithoutOverlapping("job:paid-invoice-coupon:{$this->invoiceId}"))
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
