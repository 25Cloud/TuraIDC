<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Services\Ticket\TicketDeliveryService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

final class DeliverTicketToUpstreamJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $timeout = 120;

    public int $uniqueFor = 900;

    public array $backoff = [30, 120, 300];

    public function __construct(public readonly int $ticketId)
    {
        $this->afterCommit();
    }

    public function middleware(): array
    {
        return [
            (new WithoutOverlapping("job:ticket-upstream:create:{$this->ticketId}"))
                ->releaseAfter(10)
                ->expireAfter($this->uniqueFor),
        ];
    }

    public function uniqueId(): string
    {
        return (string) $this->ticketId;
    }

    public function handle(TicketDeliveryService $delivery): void
    {
        $delivery->deliverTicket($this->ticketId);
    }

    public function failed(\Throwable $exception): void
    {
        try {
            \App\Models\TicketUpstreamBinding::query()
                ->where('ticket_id', $this->ticketId)
                ->where('status', 'sending')
                ->update([
                    'status' => 'failed',
                    'last_error' => mb_substr($exception->getMessage(), 0, 2000),
                ]);
        } catch (\Throwable $statusException) {
            Log::warning('写入工单上游失败状态时出错', [
                'ticket_id' => $this->ticketId,
                'message' => $statusException->getMessage(),
                'exception' => $statusException::class,
            ]);
        }

        $binding = \App\Models\TicketUpstreamBinding::query()->where('ticket_id', $this->ticketId)->first();
        Log::warning('工单传递到上游失败', [
            'ticket_id' => $this->ticketId,
            'binding_id' => $binding?->id,
            'provider_key' => $binding?->provider_key,
            'supplier_id' => $binding?->supplier_id,
            'attempt' => $binding?->attempts,
            'operation' => 'ticket.create',
            'message' => $exception->getMessage(),
        ]);
    }
}
