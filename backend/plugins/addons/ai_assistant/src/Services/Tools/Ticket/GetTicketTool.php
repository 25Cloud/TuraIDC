<?php

declare(strict_types=1);

namespace TuraIDC\Plugins\Addons\AiAssistant\Services\Tools\Ticket;

use App\Exceptions\BusinessException;
use App\Models\Ticket;
use TuraIDC\Plugins\Addons\AiAssistant\Services\Tools\AiTool;
use TuraIDC\Plugins\Addons\AiAssistant\Services\Tools\AiToolContext;
use TuraIDC\Plugins\Addons\AiAssistant\Services\Tools\Concerns\FormatsToolResults;
use App\Services\Ticket\TicketService;

/**
 * 工单详情：只读，且必须是本人的工单。
 */
final class GetTicketTool extends AiTool
{
    use FormatsToolResults;

    public function __construct(private readonly TicketService $tickets) {}

    public function name(): string
    {
        return 'get_ticket';
    }

    public function description(): string
    {
        return '查看某条工单的详细内容与回复记录，用于回答「客服说了什么」。只能查看当前用户自己的工单。';
    }

    public function parameters(): array
    {
        return $this->objectSchema([
            'ticket_id' => ['type' => 'integer', 'minimum' => 1, 'description' => '工单编号'],
        ], ['ticket_id']);
    }

    public function run(AiToolContext $context, array $arguments): array
    {
        $ticket = Ticket::query()
            ->where('user_id', $context->userId())
            ->find((int) $arguments['ticket_id']);

        if (! $ticket instanceof Ticket) {
            throw new BusinessException('工单不存在', 40400, 404);
        }

        $detail = $this->tickets->v2Detail($ticket, false);

        return [
            'summary' => '工单「' . (string) $ticket->subject . '」详情',
            'data' => [
                'ticket_id' => (int) $ticket->id,
                'subject' => (string) $ticket->subject,
                'status' => (int) $ticket->status,
                'detail_excerpt' => $this->excerpt((string) json_encode($detail, JSON_UNESCAPED_UNICODE), 1600),
            ],
        ];
    }
}
