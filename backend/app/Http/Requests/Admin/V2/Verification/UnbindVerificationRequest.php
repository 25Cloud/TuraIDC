<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin\V2\Verification;

use App\Http\Requests\Admin\V2\Common\AdminFormRequest;

class UnbindVerificationRequest extends AdminFormRequest
{
    public function rules(): array
    {
        return [
            'reject_reason' => ['required', 'string', 'max:255'],
            'page' => ['prohibited'],
            'page_size' => ['prohibited'],
            'per_page' => ['prohibited'],
            'pageSize' => ['prohibited'],
        ];
    }
}
