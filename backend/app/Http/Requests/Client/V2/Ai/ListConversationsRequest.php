<?php

declare(strict_types=1);

namespace App\Http\Requests\Client\V2\Ai;

use App\Http\Requests\Client\V2\Common\ClientFormRequest;

class ListConversationsRequest extends ClientFormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return $this->paginationRules(50);
    }
}
