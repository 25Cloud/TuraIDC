<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\TicketReply;
use App\Models\TicketReplyDelivery;
use App\Services\Ticket\TicketDeliveryService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

final class DeliverTicketReplyToUpstreamJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $timeout = 120;

    public int $uniqueFor = 900;

    public array $backoff = [30, 120, 300];

    public function __construct(public readonly int $replyId)
    {
        $this->afterCommit();
    }

    public function middleware(): array
    {
        return [
            (new WithoutOverlapping("job:ticket-upstream:reply:{$this->replyId}"))
                ->releaseAfter(10)
                ->expireAfter($this->uniqueFor),
        ];
    }

    public function uniqueId(): string
    {
        return (string) $this->replyId;
    }

    public function handle(TicketDeliveryService $delivery): void
    {
        $delivery->deliverReply($this->replyId);
    }

    public function failed(\Throwable $exception): void
    {
        try {
            TicketReplyDelivery::query()
                ->where('ticket_reply_id', $this->replyId)
                ->where('status', 'sending')
                ->update([
                    'status' => 'failed',
                    'last_error' => mb_substr($exception->getMessage(), 0, 2000),
                ]);
        } catch (\Throwable $statusException) {
            Log::warning('写入工单回复失败状态时出错', [
                'reply_id' => $this->replyId,
                'message' => $statusException->getMessage(),
                'exception' => $statusException::class,
            ]);
        }

        $delivery = TicketReplyDelivery::query()->where('ticket_reply_id', $this->replyId)->first();
        $reply = TicketReply::query()->with('ticket.upstreamBinding')->find($this->replyId);
        Log::warning('工单回复同步到上游失败', [
            'reply_id' => $this->replyId,
            'delivery_id' => $delivery?->id,
            'binding_id' => $reply?->ticket?->upstreamBinding?->id,
            'provider_key' => $reply?->ticket?->upstreamBinding?->provider_key,
            'supplier_id' => $reply?->ticket?->upstreamBinding?->supplier_id,
            'attempt' => $delivery?->attempts,
            'operation' => 'ticket.reply',
            'message' => $exception->getMessage(),
        ]);
    }
}
