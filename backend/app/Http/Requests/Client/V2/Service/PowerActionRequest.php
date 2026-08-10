<?php

declare(strict_types=1);

namespace App\Http\Requests\Client\V2\Service;

use App\Http\Requests\Client\V2\Action\ClientActionRequest;

class PowerActionRequest extends ClientActionRequest
{
    public function rules(): array
    {
        return array_merge(parent::rules(), [
            'action' => ['required', 'string', 'in:on,off,reboot,hard_off,hard_reboot'],
        ]);
    }
}
