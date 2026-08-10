<?php

namespace App\Http\Requests\Admin\V2\User;

use App\Http\Requests\Admin\V2\Common\AdminFormRequest;

class ListUserBalanceLogsRequest extends AdminFormRequest
{
    public function rules(): array
    {
        return array_merge($this->paginationRules(), [
            'event_type' => ['nullable', 'in:recharge,consume,refund,adjust,admin_deduct,manual_recharge,manual_deduction,invoice_payment,invoice_refund,system_adjustment,referral_withdraw_approved,referral_credit_cash'],
            'type' => ['nullable', 'in:recharge,consume,refund,adjust,admin_deduct,manual_recharge,manual_deduction,invoice_payment,invoice_refund,system_adjustment,referral_withdraw_approved,referral_credit_cash'],
        ], $this->legacyPaginationRules());
    }

    public function filters(): array
    {
        $filters = $this->safe()->only(['event_type', 'type']);

        if (empty($filters['event_type']) && ! empty($filters['type'])) {
            $filters['event_type'] = $filters['type'];
        }

        unset($filters['type']);

        return $filters;
    }
}
