<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin\V2\Invoice;

use App\Constants\InvoiceType;
use App\Http\Requests\Admin\V2\Common\AdminFormRequest;
use App\Http\Requests\Concerns\HasDateRangeFilter;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class ListInvoicesRequest extends AdminFormRequest
{
    use HasDateRangeFilter;

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'keyword' => ['sometimes', 'string', 'max:80'],
            'invoice_no' => ['sometimes', 'string', 'max:80'],
            'user_id' => ['sometimes', 'integer', 'min:1'],
            'status' => ['sometimes', 'integer'],
            'type' => ['sometimes', 'string', Rule::in($this->allowedTypes())],
            'product_id' => ['sometimes', 'integer', 'min:1'],
            'start_date' => ['sometimes', 'date_format:Y-m-d'],
            'end_date' => ['sometimes', 'date_format:Y-m-d'],
            'date_range' => ['prohibited'],
            'page' => ['sometimes', 'integer', 'min:1'],
            'page_size' => ['sometimes', 'integer', 'min:1', 'max:100'],
            'pageSize' => ['prohibited'],
            'per_page' => ['prohibited'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(fn (Validator $validator): mixed => $this->validateDateRange($validator));
    }

    /**
     * @return list<string>
     */
    private function allowedTypes(): array
    {
        return [
            InvoiceType::NEW_PURCHASE,
            'normal',
            InvoiceType::RENEW,
            InvoiceType::RECHARGE,
            InvoiceType::UPGRADE,
            InvoiceType::DEDUCTION,
            InvoiceType::REFERRAL_CREDIT,
            InvoiceType::MANUAL,
        ];
    }
}
