<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin\V2\UserService;

use App\Http\Requests\Admin\V2\Common\AdminFormRequest;

class UpdateServiceAutoRenewRequest extends AdminFormRequest
{
    public function rules(): array
    {
        return [
            'auto_renew' => ['required', 'in:0,1'],
        ];
    }
}