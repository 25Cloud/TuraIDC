<?php

declare(strict_types=1);

namespace App\Services\Ai\Tools\Ticket;

use App\Models\Ticket;
use App\Services\Ai\Tools\AiTool;
use App\Services\Ai\Tools\AiToolContext;
use App\Services\Ai\Tools\Concerns\FormatsToolResults;
use App\Services\Ticket\TicketService;

/**
 * 我的工单列表：只读。
 */
final class ListMyTicketsTool extends AiTool
{
    use FormatsToolResults;

    public function __construct(private readonly TicketService $tickets) {}

    public function name(): string
    {
        return 'list_my_tickets';
    }

    public function description(): string
    {
        return '列出当前用户的工单（标题、状态、创建时间）。用户问「我的工单处理到哪了」时先用它。';
    }

    public function parameters(): array
    {
        return $this->objectSchema([
            'limit' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 20, 'description' => '返回条数，默认 10'],
        ]);
    }

    public function run(AiToolContext $context, array $arguments): array
    {
        $limit = (int) ($arguments['limit'] ?? 10);
        $paginator = $this->tickets->clientList($context->userId(), [], $limit);

        $items = [];
        foreach ($this->toList($paginator) as $row) {
            if (! $row instanceof Ticket) {
                continue;
            }
            $items[] = [
                'ticket_id' => (int) $row->id,
                'subject' => (string) $row->subject,
                'status' => (int) $row->status,
                'created_at' => (string) ($row->created_at?->format('Y-m-d H:i:s') ?? ''),
            ];
        }

        return [
            'summary' => $items === [] ? '当前没有工单' : '共有 ' . count($items) . ' 条工单',
            'data' => ['items' => $items, 'total' => count($items)],
        ];
    }
}
