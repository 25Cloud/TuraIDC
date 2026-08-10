<?php

declare(strict_types=1);

namespace App\Http\Requests\Client\V2\Finance;

use App\Http\Requests\Client\V2\Common\ClientFormRequest;
use App\Http\Requests\Concerns\HasDateRangeFilter;

class ListBalanceLogsRequest extends ClientFormRequest
{
    use HasDateRangeFilter;

    private const EVENT_TYPES = 'recharge,consume,refund,adjust,admin_deduct,manual_recharge,manual_deduction,invoice_payment,invoice_refund,system_adjustment,referral_withdraw_approved,referral_credit_cash';

    public function rules(): array
    {
        return [
            'event_type' => ['nullable', 'in:'.self::EVENT_TYPES],
            'type' => ['nullable', 'in:'.self::EVENT_TYPES],
            ...$this->dateRangeRules(),
            'page' => ['nullable', 'integer', 'min:1'],
            'page_size' => ['nullable', 'integer', 'min:1', 'max:200'],
            'pageSize' => ['prohibited'],
            'per_page' => ['prohibited'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function filters(): array
    {
        $filters = $this->safe()->only([
            'event_type',
            'type',
            'start_date',
            'end_date',
        ]);

        if (empty($filters['event_type']) && ! empty($filters['type'])) {
            $filters['event_type'] = $filters['type'];
        }

        unset($filters['type']);

        return $filters;
    }
}
