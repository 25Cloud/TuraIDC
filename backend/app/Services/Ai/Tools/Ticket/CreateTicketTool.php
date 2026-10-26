<?php

declare(strict_types=1);

namespace App\Services\Ai\Tools\Ticket;

use App\Models\Ticket;
use App\Services\Ai\Tools\AiTool;
use App\Services\Ai\Tools\AiToolContext;
use App\Services\Ai\Tools\Concerns\FormatsToolResults;
use App\Services\Ticket\TicketService;

/**
 * 代用户创建工单：AI 客服解决不了时把问题转人工。
 */
final class CreateTicketTool extends AiTool
{
    use FormatsToolResults;

    public function __construct(private readonly TicketService $tickets) {}

    public function name(): string
    {
        return 'create_ticket';
    }

    public function description(): string
    {
        return '替当前用户提交一条工单（转人工处理）。用户描述完问题后调用，内容要包含现象、影响范围和已尝试的操作。';
    }

    public function parameters(): array
    {
        return $this->objectSchema([
            'subject' => ['type' => 'string', 'maxLength' => 200, 'description' => '工单标题'],
            'content' => ['type' => 'string', 'maxLength' => 4000, 'description' => '工单正文'],
            'department' => [
                'type' => 'string',
                'enum' => ['sales', 'support', 'billing', 'abuse'],
                'description' => '受理部门：sales 售前 / support 技术支持 / billing 财务 / abuse 违规投诉',
            ],
            'priority' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 4, 'description' => '优先级 1 最低 4 最高，默认 2'],
            'service_id' => ['type' => 'integer', 'minimum' => 1, 'description' => '关联实例编号，可选'],
        ], ['subject', 'content', 'department']);
    }

    public function risk(): string
    {
        return self::RISK_WRITE;
    }

    public function title(array $arguments): string
    {
        return '提交工单：' . (string) ($arguments['subject'] ?? '');
    }

    public function impact(array $arguments): string
    {
        return '会以你的账号真实创建一条工单，客服会收到通知。';
    }

    public function run(AiToolContext $context, array $arguments): array
    {
        $data = [
            'subject' => (string) $arguments['subject'],
            'content' => (string) $arguments['content'],
            'department' => (string) $arguments['department'],
            'priority' => (int) ($arguments['priority'] ?? 2),
        ];
        if (isset($arguments['service_id'])) {
            $data['service_id'] = (int) $arguments['service_id'];
        }

        $ticket = $this->tickets->create($context->userId(), $data);

        return [
            'summary' => '已提交工单 ' . ($ticket instanceof Ticket ? ('#' . (int) $ticket->id) : ''),
            'data' => [
                'ticket_id' => $ticket instanceof Ticket ? (int) $ticket->id : 0,
                'subject' => $ticket instanceof Ticket ? (string) $ticket->subject : (string) $arguments['subject'],
                'next_step' => '客服回复后会通过站内信与邮件通知',
            ],
        ];
    }
}
