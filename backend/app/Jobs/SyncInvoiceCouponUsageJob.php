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

    /**
     * 任务用尽重试次数后的兜底：再同步执行一次优惠券占用同步，
     * 缩小"券被重复占用"的双花窗口。
     *
     * 账单可能在此期间已被删除，故与 handle() 一致做 instanceof 判空——
     * 直接把 find() 的结果传给要求 Invoice 的方法会抛 TypeError 并被下面的
     * catch 吞掉，补偿实际不会发生。
     *
     * 补偿本身再失败时只记日志：reserve 侧的"已支付账单"检查会兜底拦截二次占用。
     */
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
            // 账单可能已被删除。此处此前直接把 find() 的结果（可能为 null）传给
            // syncInvoiceCouponUsage(Invoice $invoice)，会抛 TypeError 并被下面的
            // catch 吞掉——补偿在"账单已删"这一支实际从未发生。与 handle() 保持一致判空。
            $invoice = Invoice::query()->find($this->invoiceId);

            if ($invoice instanceof Invoice) {
                app(CouponService::class)->syncInvoiceCouponUsage($invoice);
            }
        } catch (\Throwable $fallbackException) {
            Log::error('[账单优惠券同步] 失败补偿同步未成功', [
                'invoice_id' => $this->invoiceId,
                'message' => $fallbackException->getMessage(),
                'exception' => $fallbackException::class,
            ]);
        }
    }
}
