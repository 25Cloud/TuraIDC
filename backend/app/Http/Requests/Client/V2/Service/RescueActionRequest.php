<?php

declare(strict_types=1);

namespace App\Http\Requests\Client\V2\Service;

use App\Http\Requests\Client\V2\Action\ClientActionRequest;

class RescueActionRequest extends ClientActionRequest
{
    public function rules(): array
    {
        return array_merge(parent::rules(), [
            'system' => ['required', 'string', 'in:1,2'],
        ]);
    }
}
