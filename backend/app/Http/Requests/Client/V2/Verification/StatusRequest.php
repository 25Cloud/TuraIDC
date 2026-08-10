<?php

declare(strict_types=1);

namespace App\Http\Requests\Client\V2\Verification;

use App\Http\Requests\Client\V2\Common\ClientFormRequest;

class StatusRequest extends ClientFormRequest
{
    public function rules(): array
    {
        return ['certify_id' => 'required|string'];
    }
}
