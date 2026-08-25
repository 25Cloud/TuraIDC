<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin\V2\User;

use App\Http\Requests\Admin\V2\Common\AdminFormRequest;

class ServiceSuspendActionRequest extends AdminFormRequest
{
    public function rules(): array
    {
        return [
            'reason' => ['nullable', 'string', 'max:255'],
            'per_page' => ['prohibited'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function payload(): array
    {
        return $this->safe()->only([
            'reason',
        ]);
    }
}
