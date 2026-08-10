<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin\V2\CouponCampaign;

use App\Http\Requests\Admin\V2\Common\AdminFormRequest;

class UpdateCouponCampaignStatusRequest extends AdminFormRequest
{
    public function rules(): array
    {
        return [
            'enabled' => ['required', 'boolean'],
            'per_page' => ['prohibited'],
            'page' => ['prohibited'],
            'page_size' => ['prohibited'],
            'pageSize' => ['prohibited'],
        ];
    }

    public function enabled(): bool
    {
        return (bool) $this->validated()['enabled'];
    }
}
