<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin\V2\Product;

use App\Http\Requests\Admin\V2\Common\AdminFormRequest;

class SyncGroupDescriptionRequest extends AdminFormRequest
{
    public function rules(): array
    {
        return [
            'level' => ['required', 'integer', 'in:1,2,3'],
            'overwrite' => ['sometimes', 'boolean'],
        ];
    }
}
