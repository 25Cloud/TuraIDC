<?php

declare(strict_types=1);

namespace App\Http\Requests\Client\V2\Verification;

use App\Http\Requests\Client\V2\Common\ClientFormRequest;

class InitVerificationRequest extends ClientFormRequest
{
    private const ALLOWED_CERT_TYPES = [
        'IDENTITY_CARD', 'HOME_VISIT_PERMIT_HK_MC', 'HOME_VISIT_PERMIT_TAIWAN',
        'RESIDENCE_PERMIT_HK_MC', 'RESIDENCE_PERMIT_TAIWAN',
    ];

    public function rules(): array
    {
        return [
            'realname' => ['required', 'string', 'max:50'],
            'idcard' => ['required', 'string', 'min:15', 'max:18'],
            'cert_type' => ['nullable', 'string', 'in:'.implode(',', self::ALLOWED_CERT_TYPES)],
        ];
    }
}
