<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin\V2\Finance;

use App\Http\Requests\Admin\V2\Common\AdminFormRequest;
use App\Http\Requests\Concerns\HasDateRangeFilter;
use Illuminate\Validation\Validator;

class ListFinanceRechargesRequest extends AdminFormRequest
{
    use HasDateRangeFilter;

    public function rules(): array
    {
        return [
            'keyword' => ['nullable', 'string', 'max:80'],
            'status' => ['nullable', 'integer'],
            'start_date' => ['nullable', 'date_format:Y-m-d'],
            'end_date' => ['nullable', 'date_format:Y-m-d'],
            'date_range' => ['prohibited'],
            'page' => ['nullable', 'integer', 'min:1'],
            'page_size' => ['nullable', 'integer', 'min:1', 'max:100'],
            'per_page' => ['prohibited'],
            'pageSize' => ['prohibited'],
        ];
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
    public function filters(): array
    {
        return $this->validated();
    }

    public function pageSize(): int
    {
        return $this->perPage(20, 100);
    }
}
