<?php

namespace App\Jobs;

use App\Services\Finance\PaymentService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class ProcessPaidOrderFulfillmentJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 3;

    public int $timeout = 1200;

    public int $uniqueFor = 1500;

    public array $backoff = [30, 120, 300];

    public function __construct(public int $orderId)
    {
        $this->onQueue('provision');
        $this->afterCommit();
    }

    public function middleware(): array
    {
        return [
            (new WithoutOverlapping("job:paid-order-fulfillment:{$this->orderId}"))
                ->releaseAfter(10)
                ->expireAfter(1500),
        ];
    }

    public function uniqueId(): string
    {
        return (string) $this->orderId;
    }

    public function handle(PaymentService $paymentService): void
    {
        $paymentService->processPaidOrderFulfillmentById($this->orderId);
    }

    public function failed(\Throwable $exception): void
    {
        Log::error('[支付后自动开通] 队列任务失败', [
            'order_id' => $this->orderId,
            'message' => $exception->getMessage(),
            'exception' => $exception::class,
        ]);

        // 重试耗尽 = 自动履约已放弃：把已付未履约的账单标记 requires_refund，
        // 避免用户资金悬挂在 PROCESSING 订单上无人认领（详见
        // PaymentService::markOrderFulfillmentRequiresRefund）。
        try {
            app(PaymentService::class)->markOrderFulfillmentRequiresRefund($this->orderId);
        } catch (\Throwable $markException) {
            Log::error('[支付后自动开通] requires_refund 标记写入失败', [
                'order_id' => $this->orderId,
                'message' => $markException->getMessage(),
                'exception' => $markException::class,
            ]);
        }
    }
}
