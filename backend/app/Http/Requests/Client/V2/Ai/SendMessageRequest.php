<?php

declare(strict_types=1);

namespace App\Http\Requests\Client\V2\Ai;

use App\Http\Requests\Client\V2\Common\ClientFormRequest;

class SendMessageRequest extends ClientFormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'message' => ['required', 'string', 'max:4000'],
            'conversation_id' => ['nullable', 'integer', 'min:1'],
            'channel' => ['nullable', 'string', 'in:console,site,admin'],
            'permission_mode' => ['nullable', 'string', 'in:read_only,confirm,auto_approve'],
            'subject' => ['nullable', 'array'],
            'subject.type' => ['nullable', 'string', 'max:32'],
            'subject.id' => ['nullable', 'integer', 'min:1'],
            'subject.label' => ['nullable', 'string', 'max:120'],
        ];
    }

    public function channel(): string
    {
        return (string) ($this->validated()['channel'] ?? 'console');
    }

    /**
     * @return array{type: string, id: int}|null
     */
    public function subject(): ?array
    {
        $subject = $this->validated()['subject'] ?? null;
        if (! is_array($subject) || empty($subject['type']) || empty($subject['id'])) {
            return null;
        }

        return ['type' => (string) $subject['type'], 'id' => (int) $subject['id']];
    }
}
