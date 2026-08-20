<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Services\Ticket\TicketDeliveryService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

final class DeliverTicketReplyToUpstreamJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $timeout = 120;

    public array $backoff = [30, 120, 300];

    public function __construct(public readonly int $replyId)
    {
        $this->afterCommit();
    }

    public function handle(TicketDeliveryService $delivery): void
    {
        $delivery->deliverReply($this->replyId);
    }

    public function failed(\Throwable $exception): void
    {
        Log::warning('工单回复同步到上游失败', [
            'reply_id' => $this->replyId,
            'message' => $exception->getMessage(),
        ]);
    }
}
