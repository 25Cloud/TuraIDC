<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin\V2\CouponCampaign;

use App\Http\Requests\Admin\V2\Common\AdminFormRequest;

class ListCouponCampaignsRequest extends AdminFormRequest
{
    public function rules(): array
    {
        return [
            'page' => ['sometimes', 'integer', 'min:1'],
            'page_size' => ['sometimes', 'integer', 'min:1', 'max:100'],
            'per_page' => ['prohibited'],
            'pageSize' => ['prohibited'],
            'keyword' => ['nullable', 'string', 'max:100'],
            'status' => ['nullable', 'in:0,1'],
        ];
    }
}
