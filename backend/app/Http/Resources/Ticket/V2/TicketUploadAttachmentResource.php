<?php

declare(strict_types=1);

namespace App\Http\Resources\Ticket\V2;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class TicketUploadAttachmentResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $attachment = is_array($this->resource) ? $this->resource : [];

        return [
            'id' => (string) ($attachment['id'] ?? ''),
            'name' => (string) ($attachment['name'] ?? ''),
            'path' => (string) ($attachment['path'] ?? ''),
            'url' => $attachment['url'] ?? null,
            'size' => (int) ($attachment['size'] ?? 0),
            'mime_type' => (string) ($attachment['mime_type'] ?? ''),
            'type' => (string) ($attachment['type'] ?? 'image'),
        ];
    }
}
