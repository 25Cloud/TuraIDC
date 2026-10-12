<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin\V2\Redis;

use App\Http\Requests\Admin\V2\Common\AdminFormRequest;

class ShowRedisStatusRequest extends AdminFormRequest
{
    public function rules(): array
    {
        return [
            'per_page' => ['prohibited'],
            'pageSize' => ['prohibited'],
        ];
    }
}
