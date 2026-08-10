<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin\V2\Ticket;

use App\Http\Requests\Admin\V2\Common\AdminFormRequest;
use App\Models\Ticket;

class ShowTicketRequest extends AdminFormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'ticket' => ['required', 'integer', 'min:1'],
            'page' => ['prohibited'],
            'page_size' => ['prohibited'],
            'pageSize' => ['prohibited'],
            'per_page' => ['prohibited'],
        ];
    }

    public function validationData(): array
    {
        $ticket = $this->route('ticket');

        return array_merge(parent::validationData(), [
            'ticket' => $ticket instanceof Ticket ? (int) $ticket->id : $ticket,
        ]);
    }
}
