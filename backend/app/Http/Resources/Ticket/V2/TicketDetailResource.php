<?php

declare(strict_types=1);

namespace App\Http\Resources\Ticket\V2;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class TicketDetailResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $ticket = is_array($this->resource) ? $this->resource : [];

        $data = [
            'id' => (int) ($ticket['id'] ?? 0),
            'user_id' => (int) ($ticket['user_id'] ?? 0),
            'department' => (string) ($ticket['department'] ?? ''),
            'department_label' => (string) ($ticket['department_label'] ?? ''),
            'subject' => (string) ($ticket['subject'] ?? ''),
            'priority' => (int) ($ticket['priority'] ?? 0),
            'priority_label' => (string) ($ticket['priority_label'] ?? ''),
            'status' => (int) ($ticket['status'] ?? 0),
            'status_label' => (string) ($ticket['status_label'] ?? ''),
            'service_id' => $ticket['service_id'] ?? null,
            'assignee_id' => $ticket['assignee_id'] ?? null,
            'close_reason' => $ticket['close_reason'] ?? null,
            'close_reason_label' => $ticket['close_reason_label'] ?? null,
            'created_at' => $ticket['created_at'] ?? null,
            'updated_at' => $ticket['updated_at'] ?? null,
            'user' => $this->user($ticket['user'] ?? null),
            'service' => $this->service($ticket['service'] ?? null),
            'assignee' => $this->assignee($ticket['assignee'] ?? null),
            'replies_summary' => $this->repliesSummary((array) ($ticket['replies_summary'] ?? [])),
        ];

        if (array_key_exists('upstream_delivery', $ticket)) {
            $data['upstream_delivery'] = $this->upstreamDelivery($ticket['upstream_delivery']);
        }

        return $data;
    }

    private function user(mixed $user): ?array
    {
        if (! is_array($user)) {
            return null;
        }

        return [
            'id' => (int) ($user['id'] ?? 0),
            'email' => (string) ($user['email'] ?? ''),
            'nickname' => (string) ($user['nickname'] ?? ''),
            'display_name' => (string) ($user['display_name'] ?? ''),
        ];
    }

    private function service(mixed $service): ?array
    {
        if (! is_array($service)) {
            return null;
        }

        return [
            'id' => (int) ($service['id'] ?? 0),
            'name' => (string) ($service['name'] ?? ''),
            'display_name' => (string) ($service['display_name'] ?? ''),
            'domain' => (string) ($service['domain'] ?? ''),
            'status' => (int) ($service['status'] ?? 0),
            'status_label' => (string) ($service['status_label'] ?? ''),
            'billing_cycle' => (string) ($service['billing_cycle'] ?? ''),
            'billing_cycle_label' => (string) ($service['billing_cycle_label'] ?? ''),
            'amount' => (string) ($service['amount'] ?? '0.00'),
            'expires_at' => $service['expires_at'] ?? null,
            'specs' => $this->specs((array) ($service['specs'] ?? [])),
        ];
    }

    private function assignee(mixed $assignee): ?array
    {
        if (! is_array($assignee)) {
            return null;
        }

        return [
            'id' => (int) ($assignee['id'] ?? 0),
            'username' => (string) ($assignee['username'] ?? ''),
            'nickname' => (string) ($assignee['nickname'] ?? ''),
        ];
    }

    /**
     * @param  array<int, mixed>  $specs
     * @return list<array<string, string>>
     */
    private function specs(array $specs): array
    {
        return collect($specs)
            ->filter(fn (mixed $item): bool => is_array($item))
            ->map(fn (array $item): array => [
                'key' => (string) ($item['key'] ?? ''),
                'label' => (string) ($item['label'] ?? ''),
                'value' => (string) ($item['value'] ?? ''),
            ])
            ->filter(fn (array $item): bool => $item['key'] !== '' && $item['label'] !== '')
            ->values()
            ->all();
    }

    private function upstreamDelivery(mixed $delivery): array
    {
        $delivery = is_array($delivery) ? $delivery : [];

        return [
            'configured' => (bool) ($delivery['configured'] ?? false),
            'status' => (string) ($delivery['status'] ?? 'not_configured'),
            'status_label' => (string) ($delivery['status_label'] ?? '未配置'),
            'provider_key' => $delivery['provider_key'] ?? null,
            'supplier_id' => isset($delivery['supplier_id']) ? (int) $delivery['supplier_id'] : null,
            'upstream_department_id' => $delivery['upstream_department_id'] ?? null,
            'upstream_service_id' => $delivery['upstream_service_id'] ?? null,
            'upstream_ticket_id' => $delivery['upstream_ticket_id'] ?? null,
            'attempts' => (int) ($delivery['attempts'] ?? 0),
            'last_attempt_at' => $delivery['last_attempt_at'] ?? null,
            'delivered_at' => $delivery['delivered_at'] ?? null,
            'last_error' => $delivery['last_error'] ?? null,
            'last_event' => is_array($delivery['last_event'] ?? null) ? [
                'event' => (string) ($delivery['last_event']['event'] ?? ''),
                'status' => (string) ($delivery['last_event']['status'] ?? ''),
                'reason_code' => $delivery['last_event']['reason_code'] ?? null,
                'message' => $delivery['last_event']['message'] ?? null,
                'occurred_at' => $delivery['last_event']['occurred_at'] ?? null,
            ] : null,
        ];
    }

    /**
     * @param  array<string, mixed>  $summary
     * @return array<string, int>
     */
    private function repliesSummary(array $summary): array
    {
        return [
            'total' => (int) ($summary['total'] ?? 0),
            'default_page_size' => (int) ($summary['default_page_size'] ?? 20),
        ];
    }
}
