<?php

declare(strict_types=1);

namespace TuraIDC\Plugins\Addons\AiAssistant\Http\Requests\Client;

use App\Http\Requests\Client\V2\Common\ClientFormRequest;

class ConfirmActionRequest extends ClientFormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'confirm_token' => ['required', 'string', 'max:128'],
        ];
    }
}
