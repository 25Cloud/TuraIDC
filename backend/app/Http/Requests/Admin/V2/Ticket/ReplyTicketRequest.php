<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin\V2\Ticket;

use App\Http\Requests\Admin\V2\Common\AdminFormRequest;

class ReplyTicketRequest extends AdminFormRequest
{
    public function rules(): array
    {
        return [
            'content' => ['nullable', 'string', 'max:10000'],
            'attachments' => ['nullable', 'array', 'max:9'],
            'attachments.*' => ['required', 'string', 'max:255'],
            'quote_reply_id' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['prohibited'],
            'pageSize' => ['prohibited'],
        ];
    }

    public function content(): ?string
    {
        $content = $this->validated('content');

        return is_string($content) ? $content : null;
    }

    /**
     * @return list<string>
     */
    public function attachments(): array
    {
        return array_values(array_filter(
            (array) $this->validated('attachments', []),
            static fn (mixed $item): bool => is_string($item) && trim($item) !== ''
        ));
    }

    public function quoteReplyId(): ?int
    {
        $id = $this->validated('quote_reply_id');

        return $id === null ? null : (int) $id;
    }
}
