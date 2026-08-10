<?php

declare(strict_types=1);

namespace App\Http\Requests\Client\V2\Service;

use App\Http\Requests\Client\V2\Action\ClientActionRequest;

class ReinstallationRequest extends ClientActionRequest
{
    public function rules(): array
    {
        return array_merge(parent::rules(), [
            'os_id' => ['required', 'string', 'max:50'],
        ]);
    }
}
