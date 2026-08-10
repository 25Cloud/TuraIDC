<?php

declare(strict_types=1);

namespace App\Http\Requests\Client\V2\Service;

use App\Http\Requests\Client\V2\Common\ClientFormRequest;

class ModuleStatusRequest extends ClientFormRequest
{
    public function rules(): array
    {
        return [
            'type' => ['required', 'string', 'in:host,reinstall,repassword'],
        ];
    }
}
