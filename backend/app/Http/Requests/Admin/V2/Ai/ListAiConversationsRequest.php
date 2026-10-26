<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin\V2\Ai;

use App\Http\Requests\Admin\V2\Common\AdminFormRequest;

class ListAiConversationsRequest extends AdminFormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return array_merge($this->paginationRules(100), [
            'user_id' => ['nullable', 'integer', 'min:1'],
            'channel' => ['nullable', 'string', 'in:console,site,admin'],
            'status' => ['nullable', 'integer', 'in:0,1'],
        ]);
    }
}
