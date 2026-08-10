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

class ProcessPaidOrderReferralRewardJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 3;

    public int $timeout = 300;

    public int $uniqueFor = 600;

    public array $backoff = [30, 120, 300];

    public function __construct(
        public int $orderId,
        public ?string $traceId = null,
    ) {
        $this->onQueue('referral');
        $this->afterCommit();
    }

    public function middleware(): array
    {
        return [
            (new WithoutOverlapping("job:paid-order-referral:{$this->orderId}"))
                ->releaseAfter(10)
                ->expireAfter(600),
        ];
    }

    public function uniqueId(): string
    {
        return (string) $this->orderId;
    }

    public function handle(PaymentService $paymentService): void
    {
        $paymentService->processPaidOrderReferralRewardById($this->orderId, $this->traceId);
    }

    public function failed(\Throwable $exception): void
    {
        Log::error('[支付后推荐奖励] 队列任务失败', [
            'order_id' => $this->orderId,
            'trace_id' => $this->traceId,
            'message' => $exception->getMessage(),
            'exception' => $exception::class,
        ]);
    }
}
