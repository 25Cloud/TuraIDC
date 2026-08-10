<?php

declare(strict_types=1);

namespace App\Http\Resources\Ticket\V2;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class TicketReplyResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $reply = is_array($this->resource) ? $this->resource : [];

        return [
            'id' => (int) ($reply['id'] ?? 0),
            'ticket_id' => (int) ($reply['ticket_id'] ?? 0),
            'user_id' => (int) ($reply['user_id'] ?? 0),
            'content' => (string) ($reply['content'] ?? ''),
            'is_staff' => (int) ($reply['is_staff'] ?? 0),
            'sender_name' => (string) ($reply['sender_name'] ?? ''),
            'attachments' => $this->attachments((array) ($reply['attachments'] ?? [])),
            'recalled' => (bool) ($reply['recalled'] ?? false),
            'recalled_at' => $reply['recalled_at'] ?? null,
            'quote' => $this->quote($reply['quote'] ?? null),
            'created_at' => $reply['created_at'] ?? null,
        ];
    }

    /**
     * @param  array<int, mixed>  $attachments
     * @return list<array<string, mixed>>
     */
    private function attachments(array $attachments): array
    {
        return collect($attachments)
            ->filter(fn (mixed $item): bool => is_array($item))
            ->map(fn (array $item): array => [
                'id' => (string) ($item['id'] ?? ''),
                'name' => (string) ($item['name'] ?? ''),
                'type' => (string) ($item['type'] ?? ''),
                'url' => $item['url'] ?? null,
                'deleted' => (bool) ($item['deleted'] ?? false),
            ])
            ->filter(fn (array $item): bool => $item['id'] !== '' || $item['name'] !== '')
            ->values()
            ->all();
    }

    private function quote(mixed $quote): ?array
    {
        if (! is_array($quote)) {
            return null;
        }

        return [
            'id' => (int) ($quote['id'] ?? 0),
            'sender_name' => (string) ($quote['sender_name'] ?? ''),
            'content' => (string) ($quote['content'] ?? ''),
            'recalled' => (bool) ($quote['recalled'] ?? false),
        ];
    }
}
