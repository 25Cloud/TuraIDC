<?php

declare(strict_types=1);

namespace App\Http\Requests\Client\V2\Ticket;

use App\Http\Requests\Client\V2\Common\ClientFormRequest;

class ReplyRequest extends ClientFormRequest
{
    public function rules(): array
    {
        return [
            'content' => ['nullable', 'string', 'max:10000'],
            'attachments' => ['nullable', 'array', 'max:9'],
            'attachments.*' => ['required', 'string', 'max:255'],
            'quote_reply_id' => ['nullable', 'integer', 'min:1'],
        ];
    }
}
