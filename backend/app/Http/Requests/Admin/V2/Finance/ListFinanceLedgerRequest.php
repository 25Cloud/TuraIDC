<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin\V2\Finance;

use App\Constants\FinanceLedgerEventType;
use App\Http\Requests\Admin\V2\Common\AdminFormRequest;
use App\Http\Requests\Concerns\HasDateRangeFilter;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class ListFinanceLedgerRequest extends AdminFormRequest
{
    use HasDateRangeFilter;

    public function rules(): array
    {
        return [
            ...$this->filterRules(),
            'page' => ['nullable', 'integer', 'min:1'],
            'page_size' => ['nullable', 'integer', 'min:1', 'max:100'],
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
            'user_id',
            'invoice_no',
            'payment_no',
            'keyword',
            'start_date',
            'end_date',
        ]);
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $this->validateDateRange($validator);
            $this->rejectPrivacyFilters($validator, [], [
                'keyword' => '邮箱、手机号等隐私关键词',
            ]);
        });
    }

    /**
     * @return array<string, mixed>
     */
    private function filterRules(): array
    {
        return [
            'tab' => ['nullable', Rule::in(['all', 'invoices', 'balance', 'recharge', 'adjustment'])],
            'event_type' => ['nullable', Rule::in(FinanceLedgerEventType::allowedFilterValues())],
            'direction' => ['nullable', Rule::in([FinanceLedgerEventType::DIRECTION_IN, FinanceLedgerEventType::DIRECTION_OUT])],
            'status' => ['nullable', 'integer'],
            'user_id' => ['nullable', 'integer', 'min:1'],
            'invoice_no' => ['nullable', 'string', 'max:50'],
            'payment_no' => ['nullable', 'string', 'max:50'],
            'keyword' => ['nullable', 'string', 'max:100'],
            ...$this->dateRangeRules(),
        ];
    }
}
