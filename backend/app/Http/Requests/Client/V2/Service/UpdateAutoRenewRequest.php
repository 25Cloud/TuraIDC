<?php

declare(strict_types=1);

namespace App\Http\Requests\Client\V2\Service;

use App\Http\Requests\Client\V2\Common\ClientFormRequest;

class UpdateAutoRenewRequest extends ClientFormRequest
{
    public function rules(): array
    {
        return [
            'auto_renew' => ['required', 'in:0,1'],
        ];
    }
}
