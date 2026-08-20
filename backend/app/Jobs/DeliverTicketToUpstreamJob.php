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

final class DeliverTicketToUpstreamJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $timeout = 120;

    public array $backoff = [30, 120, 300];

    public function __construct(public readonly int $ticketId)
    {
        $this->afterCommit();
    }

    public function handle(TicketDeliveryService $delivery): void
    {
        $delivery->deliverTicket($this->ticketId);
    }

    public function failed(\Throwable $exception): void
    {
        Log::warning('工单传递到上游失败', [
            'ticket_id' => $this->ticketId,
            'message' => $exception->getMessage(),
        ]);
    }
}
