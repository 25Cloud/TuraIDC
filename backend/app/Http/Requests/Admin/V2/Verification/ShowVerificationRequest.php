<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin\V2\Verification;

use App\Http\Requests\Admin\V2\Common\AdminFormRequest;

class ShowVerificationRequest extends AdminFormRequest
{
    public function rules(): array
    {
        return [
            'page' => ['prohibited'],
            'page_size' => ['prohibited'],
            'per_page' => ['prohibited'],
            'pageSize' => ['prohibited'],
        ];
    }
}
