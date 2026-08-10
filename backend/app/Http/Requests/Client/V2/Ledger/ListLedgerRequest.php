<?php

declare(strict_types=1);

namespace App\Http\Requests\Client\V2\Ledger;

use App\Constants\FinanceLedgerEventType;
use App\Http\Requests\Concerns\HasDateRangeFilter;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class ListLedgerRequest extends FormRequest
{
    use HasDateRangeFilter;

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'tab' => ['sometimes', Rule::in(['all', 'invoices', 'balance', 'recharge', 'adjustment'])],
            'event_type' => ['sometimes', Rule::in(FinanceLedgerEventType::allowedFilterValues())],
            'direction' => ['sometimes', Rule::in([FinanceLedgerEventType::DIRECTION_IN, FinanceLedgerEventType::DIRECTION_OUT])],
            'status' => ['sometimes', 'integer'],
            'service_id' => ['sometimes', 'integer', 'min:1'],
            'invoice_no' => ['sometimes', 'string', 'max:50'],
            'payment_no' => ['sometimes', 'string', 'max:50'],
            'start_date' => ['sometimes', 'date_format:Y-m-d'],
            'end_date' => ['sometimes', 'date_format:Y-m-d'],
            'date_range' => ['prohibited'],
            'page' => ['sometimes', 'integer', 'min:1'],
            'page_size' => ['sometimes', 'integer', 'min:1', 'max:100'],
            'per_page' => ['prohibited'],
            'pageSize' => ['prohibited'],
            'type' => ['prohibited'],
            'gateway' => ['prohibited'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(fn (Validator $validator): mixed => $this->validateDateRange($validator));
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

    public function perPage(int $default = 15): int
    {
        return max(1, min((int) $this->integer('page_size', $default), 100));
    }
}
