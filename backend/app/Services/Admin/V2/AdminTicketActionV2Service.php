<?php

declare(strict_types=1);

namespace App\Services\Admin\V2;

use App\Models\Ticket;
use App\Models\TicketReply;
use App\Services\Ticket\TicketService;

class AdminTicketActionV2Service
{
    public function __construct(
        private readonly TicketService $tickets,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function close(Ticket $ticket): array
    {
        $this->tickets->staffClose($ticket);
        $ticket = $ticket->refresh();

        return [
            'id' => (int) $ticket->id,
            'status' => 'completed',
            'message' => '工单已关闭',
            'detail' => [
                'type' => 'closure',
                'ticket' => [
                    'id' => (int) $ticket->id,
                    'status' => (int) $ticket->status,
                    'close_reason' => $ticket->close_reason,
                ],
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function assign(Ticket $ticket, int $assigneeId): array
    {
        $ticket = $this->tickets->assign($ticket, $assigneeId)
            ->load('assignee:id,username,nickname');
        $assignee = $ticket->assignee;

        return [
            'id' => (int) $ticket->id,
            'status' => 'completed',
            'message' => '指派成功',
            'detail' => [
                'type' => 'assignment',
                'ticket' => [
                    'id' => (int) $ticket->id,
                    'assignee_id' => $ticket->assignee_id ? (int) $ticket->assignee_id : null,
                ],
                'assignee' => $assignee ? [
                    'id' => (int) $assignee->id,
                    'username' => (string) $assignee->username,
                    'nickname' => (string) ($assignee->nickname ?? ''),
                ] : null,
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function recallReply(Ticket $ticket, int $replyId, int $operatorId): array
    {
        $this->tickets->recallReply($ticket, $replyId, $operatorId, true);
        $reply = TicketReply::query()->find($replyId);

        return [
            'id' => $replyId,
            'status' => 'completed',
            'message' => '消息已撤回',
            'detail' => [
                'type' => 'recall',
                'ticket_id' => (int) $ticket->id,
                'reply' => [
                    'id' => $replyId,
                    'recalled' => $reply instanceof TicketReply && $reply->recalled_at !== null,
                ],
            ],
        ];
    }
}
