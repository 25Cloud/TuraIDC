<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin\V2\Finance;

use App\Http\Requests\Admin\V2\Common\AdminFormRequest;

class ShowNewCustomerDailySummaryRequest extends AdminFormRequest
{
    public function rules(): array
    {
        return [
            'month' => ['nullable', 'date_format:Y-m', 'required_without:start_date'],
            'start_date' => ['nullable', 'date_format:Y-m-d', 'required_with:end_date'],
            'end_date' => ['nullable', 'date_format:Y-m-d', 'required_with:start_date', 'after_or_equal:start_date'],
            'page' => ['prohibited'],
            'page_size' => ['prohibited'],
            'per_page' => ['prohibited'],
            'pageSize' => ['prohibited'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function filters(): array
    {
        return $this->validated();
    }
}
