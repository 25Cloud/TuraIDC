<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin\V2\ReferralWithdrawal;

use App\Http\Requests\Admin\V2\Common\AdminFormRequest;

class ApproveReferralWithdrawalRequest extends AdminFormRequest
{
    public function rules(): array
    {
        return [
            'remark' => ['nullable', 'string', 'max:255'],
            'per_page' => ['prohibited'],
            'pageSize' => ['prohibited'],
        ];
    }

    public function remark(): ?string
    {
        $remark = $this->validated()['remark'] ?? null;

        return is_string($remark) && $remark !== '' ? $remark : null;
    }
}
