<?php

declare(strict_types=1);

namespace App\Http\Requests\Client\V2\Finance;

use App\Constants\FinanceLedgerEventType;
use App\Http\Requests\Client\V2\Common\ClientFormRequest;
use App\Http\Requests\Concerns\HasDateRangeFilter;
use Illuminate\Validation\Rule;

class SummarizeFinanceLedgerRequest extends ClientFormRequest
{
    use HasDateRangeFilter;

    public function rules(): array
    {
        return [
            'tab' => ['nullable', Rule::in(['all', 'invoices', 'balance', 'recharge', 'adjustment'])],
            'event_type' => ['nullable', Rule::in(FinanceLedgerEventType::allowedFilterValues())],
            'direction' => ['nullable', Rule::in([FinanceLedgerEventType::DIRECTION_IN, FinanceLedgerEventType::DIRECTION_OUT])],
            'status' => ['nullable', 'integer'],
            'service_id' => ['nullable', 'integer', 'min:1'],
            'invoice_no' => ['nullable', 'string', 'max:50'],
            'payment_no' => ['nullable', 'string', 'max:50'],
            ...$this->dateRangeRules(),
            'page' => ['prohibited'],
            'page_size' => ['prohibited'],
            'pageSize' => ['prohibited'],
            'per_page' => ['prohibited'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function filters(): array
    {
        return $this->safe()->only([
            'tab',
            'event_type',
            'direction',
            'status',
            'service_id',
            'invoice_no',
            'payment_no',
            'start_date',
            'end_date',
        ]);
    }
}
