<?php

namespace App\Http\Requests\Concerns;

use Carbon\CarbonImmutable;

trait HasDateRangeFilter
{
    protected function dateRangeRules(): array
    {
        return [
            'start_date' => ['nullable', 'date_format:Y-m-d'],
            'end_date' => ['nullable', 'date_format:Y-m-d'],
            'date_range' => ['prohibited'],
        ];
    }

    public function withValidator($validator): void
    {
        $validator->after(fn ($validator) => $this->validateDateRange($validator));
    }

    protected function validateDateRange($validator): void
    {
        $startDate = trim((string) $this->input('start_date', ''));
        $endDate = trim((string) $this->input('end_date', ''));

        if ($startDate === '' || $endDate === '') {
            return;
        }

        try {
            $start = CarbonImmutable::createFromFormat('Y-m-d', $startDate);
            $end = CarbonImmutable::createFromFormat('Y-m-d', $endDate);
        } catch (\Throwable) {
            return;
        }

        if ($start instanceof CarbonImmutable && $end instanceof CarbonImmutable && $start->greaterThan($end)) {
            $validator->errors()->add('start_date', '开始日期不能晚于结束日期');
        }
    }
}
