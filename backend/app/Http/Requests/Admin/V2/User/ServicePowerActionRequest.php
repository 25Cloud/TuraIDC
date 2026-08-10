<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin\V2\User;

use App\Http\Requests\Admin\V2\Common\AdminFormRequest;

class ServicePowerActionRequest extends AdminFormRequest
{
    public function rules(): array
    {
        return [
            'action' => ['required', 'string', 'in:on,off,reboot,hard_off,hard_reboot'],
            'per_page' => ['prohibited'],
        ];
    }

    public function action(): string
    {
        return (string) $this->validated()['action'];
    }
}
