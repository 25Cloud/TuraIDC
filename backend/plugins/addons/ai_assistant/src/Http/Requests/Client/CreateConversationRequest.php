<?php

declare(strict_types=1);

namespace TuraIDC\Plugins\Addons\AiAssistant\Http\Requests\Client;

use App\Http\Requests\Client\V2\Common\ClientFormRequest;

class CreateConversationRequest extends ClientFormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'title' => ['nullable', 'string', 'max:120'],
            'channel' => ['nullable', 'string', 'in:console,site,admin'],
            'permission_mode' => ['nullable', 'string', 'in:read_only,confirm,auto_approve'],
            'subject_type' => ['nullable', 'string', 'max:32'],
            'subject_id' => ['nullable', 'integer', 'min:1'],
        ];
    }
}
