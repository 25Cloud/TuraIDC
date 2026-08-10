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

        return [
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
