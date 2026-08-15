<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin\V2\ReferralWithdrawal;

use App\Http\Requests\Admin\V2\Common\AdminFormRequest;

class ConfirmWithdrawalPaymentRequest extends AdminFormRequest
{
    public function rules(): array
    {
        return [
            'payment_no' => ['required', 'string', 'max:120'],
            'remark' => ['nullable', 'string', 'max:255'],
            'per_page' => ['prohibited'],
            'pageSize' => ['prohibited'],
        ];
    }

    public function paymentNo(): string
    {
        return trim((string) $this->validated()['payment_no']);
    }

    public function remark(): ?string
    {
        return trim((string) ($this->validated()['remark'] ?? '')) ?: null;
    }
}
