<?php

declare(strict_types=1);

namespace TuraIDC\Plugins\Addons\ZjmfBridge\Services;

use App\Exceptions\BusinessException;
use App\Models\Ticket;
use App\Models\User;
use App\Services\Ticket\TicketService;

class ZjmfTicketService
{
    public function __construct(
        private readonly TicketService $tickets,
    ) {}

    /**
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>
     */
    public function tickets(User $user, array $filters): array
    {
        $paginator = $this->tickets->clientList(
            (int) $user->id,
            [
                'keyword' => trim((string) ($filters['keyword'] ?? '')),
                'status' => $filters['status'] ?? '',
            ],
            $this->pageSize($filters, 20, 100)
        );

        return [
            'list' => collect($paginator->items())
                ->filter(fn (mixed $ticket): bool => $ticket instanceof Ticket)
                ->map(fn (Ticket $ticket): array => $this->ticketPayload($ticket))
                ->values()
                ->all(),
            'total' => $paginator->total(),
            'page' => $paginator->currentPage(),
            'page_size' => $paginator->perPage(),
        ];
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>
     */
    public function page(User $user, array $filters): array
    {
        $limit = $this->pageSize($filters, 20, 50);
        $keyword = trim((string) ($filters['keyword'] ?? ''));

        return [
            'departments' => collect(TicketService::DEPARTMENTS)
                ->map(fn (string $department): array => [
                    'value' => $department,
                    'label' => TicketService::DEPT_LABELS[$department] ?? $department,
                ])
                ->values()
                ->all(),
            'priorities' => collect(TicketService::PRIORITIES)
                ->map(fn (string $label, int $value): array => [
                    'value' => $value,
                    'label' => $label,
                ])
                ->values()
                ->all(),
            'services' => $this->tickets->clientServiceOptions((int) $user->id, $keyword, $limit),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function ticket(User $user, int $ticketId): array
    {
        return [
            'ticket' => $this->detailPayload($this->findTicket($user, $ticketId)),
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function create(User $user, array $payload): array
    {
        $data = $this->createPayload($payload);
        $ticket = $this->tickets->create((int) $user->id, $data);

        return [
            'ticket' => $this->detailPayload($this->findTicket($user, (int) $ticket->id)),
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function reply(User $user, int $ticketId, array $payload): array
    {
        $ticket = $this->findTicket($user, $ticketId);
        $reply = $this->tickets->clientReply(
            $ticket,
            (int) $user->id,
            array_key_exists('content', $payload) ? (string) $payload['content'] : null,
            $this->stringList($payload['attachments'] ?? []),
            isset($payload['quote_reply_id']) ? (int) $payload['quote_reply_id'] : null
        );

        return [
            'reply' => $reply,
            'ticket' => $this->detailPayload($this->findTicket($user, $ticketId)),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function close(User $user, int $ticketId): array
    {
        $ticket = $this->findTicket($user, $ticketId);
        $this->tickets->clientClose($ticket, (int) $user->id);

        return [
            'ticket' => $this->detailPayload($this->findTicket($user, $ticketId)),
        ];
    }

    private function findTicket(User $user, int $ticketId): Ticket
    {
        $ticket = Ticket::query()
            ->where('user_id', (int) $user->id)
            ->find($ticketId);

        if (! $ticket instanceof Ticket) {
            throw new BusinessException('工单不存在', 40400, 404);
        }

        return $ticket;
    }

    /**
     * @return array<string, mixed>
     */
    private function ticketPayload(Ticket $ticket): array
    {
        return [
            'id' => (int) $ticket->id,
            'ticketid' => (int) $ticket->id,
            'department' => (string) $ticket->department,
            'department_label' => TicketService::DEPT_LABELS[(string) $ticket->department] ?? (string) $ticket->department,
            'subject' => (string) $ticket->subject,
            'priority' => (int) $ticket->priority,
            'priority_label' => TicketService::PRIORITIES[(int) $ticket->priority] ?? (string) $ticket->priority,
            'status' => (int) $ticket->status,
            'status_label' => TicketService::STATUS_LABELS[(int) $ticket->status] ?? (string) $ticket->status,
            'service_id' => $ticket->service_id ? (int) $ticket->service_id : null,
            'created_at' => $ticket->created_at?->format('Y-m-d H:i:s'),
            'updated_at' => $ticket->updated_at?->format('Y-m-d H:i:s'),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function detailPayload(Ticket $ticket): array
    {
        $detail = $this->tickets->detail($ticket);

        return [
            ...$detail,
            'ticketid' => (int) ($detail['id'] ?? $ticket->id),
            'department_label' => TicketService::DEPT_LABELS[(string) ($detail['department'] ?? '')] ?? (string) ($detail['department'] ?? ''),
            'priority_label' => TicketService::PRIORITIES[(int) ($detail['priority'] ?? 0)] ?? (string) ($detail['priority'] ?? ''),
            'status_label' => TicketService::STATUS_LABELS[(int) ($detail['status'] ?? 0)] ?? (string) ($detail['status'] ?? ''),
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function createPayload(array $payload): array
    {
        $department = trim((string) ($payload['department'] ?? 'support'));
        if (! in_array($department, TicketService::DEPARTMENTS, true)) {
            throw new BusinessException('工单部门无效', 42200, 422);
        }

        $subject = trim((string) ($payload['subject'] ?? $payload['title'] ?? ''));
        if ($subject === '') {
            throw new BusinessException('工单标题不能为空', 42200, 422);
        }

        return [
            'department' => $department,
            'subject' => $subject,
            'content' => array_key_exists('content', $payload) ? (string) $payload['content'] : null,
            'priority' => isset($payload['priority']) ? (int) $payload['priority'] : 2,
            'service_id' => isset($payload['service_id']) ? (int) $payload['service_id'] : null,
            'attachments' => $this->stringList($payload['attachments'] ?? []),
        ];
    }

    private function pageSize(array $filters, int $default, int $max): int
    {
        $value = (int) ($filters['page_size'] ?? $filters['limit'] ?? $default);

        return min(max($value, 1), $max);
    }

    /**
     * @return list<string>
     */
    private function stringList(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        return array_values(array_filter(
            array_map(fn (mixed $item): string => trim((string) $item), $value),
            fn (string $item): bool => $item !== ''
        ));
    }
}
