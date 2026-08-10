<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin\V2\Coupon;

use App\Http\Requests\Admin\V2\Common\AdminFormRequest;

class DeleteCouponRequest extends AdminFormRequest
{
    public function rules(): array
    {
        return [
            'per_page' => ['prohibited'],
            'page' => ['prohibited'],
            'page_size' => ['prohibited'],
            'pageSize' => ['prohibited'],
        ];
    }
}
