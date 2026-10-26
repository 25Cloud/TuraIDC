<?php

declare(strict_types=1);

namespace App\Http\Requests\Client\V2\Ai;

use App\Http\Requests\Client\V2\Common\ClientFormRequest;

class UpdateConversationRequest extends ClientFormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'title' => ['sometimes', 'string', 'max:120'],
            'permission_mode' => ['sometimes', 'string', 'in:read_only,confirm,auto_approve'],
        ];
    }
}
