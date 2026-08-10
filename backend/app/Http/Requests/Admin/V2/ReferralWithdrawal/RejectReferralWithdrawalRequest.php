<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin\V2\ReferralWithdrawal;

use App\Http\Requests\Admin\V2\Common\AdminFormRequest;

class RejectReferralWithdrawalRequest extends AdminFormRequest
{
    public function rules(): array
    {
        return [
            'remark' => ['required', 'string', 'max:255'],
            'per_page' => ['prohibited'],
            'pageSize' => ['prohibited'],
        ];
    }

    public function remark(): string
    {
        return (string) $this->validated()['remark'];
    }
}
