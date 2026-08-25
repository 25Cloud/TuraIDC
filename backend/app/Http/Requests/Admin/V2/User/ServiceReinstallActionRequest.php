<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin\V2\User;

use App\Http\Requests\Admin\V2\Common\AdminFormRequest;

class ServiceReinstallActionRequest extends AdminFormRequest
{
    public function rules(): array
    {
        return [
            'os_id' => ['required', 'string', 'max:120'],
            'per_page' => ['prohibited'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function payload(): array
    {
        return $this->safe()->only([
            'os_id',
        ]);
    }
}
