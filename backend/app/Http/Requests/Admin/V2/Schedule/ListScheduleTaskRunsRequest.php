<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin\V2\Schedule;

use App\Http\Requests\Admin\V2\Common\AdminFormRequest;
use App\Http\Requests\Concerns\HasDateRangeFilter;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class ListScheduleTaskRunsRequest extends AdminFormRequest
{
    use HasDateRangeFilter;

    private const STATUSES = [
        'queued',
        'running',
        'retrying',
        'success',
        'failed',
        'dispatch_failed',
    ];

    private const SOURCES = [
        'heartbeat',
        'manual_trigger',
        'manual_retry',
    ];

    private const SORTS = [
        'id',
        'task_key',
        'status',
        'source',
        'created_at',
        'queued_at',
        'started_at',
        'finished_at',
    ];

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'keyword' => ['sometimes', 'string', 'max:80'],
            'task_key' => ['sometimes', 'string', 'max:120'],
            'status' => ['sometimes', 'string', 'max:100'],
            'source' => ['sometimes', 'string', Rule::in(self::SOURCES)],
            'start_date' => ['sometimes', 'date_format:Y-m-d'],
            'end_date' => ['sometimes', 'date_format:Y-m-d'],
            'date_range' => ['prohibited'],
            'page' => ['sometimes', 'integer', 'min:1'],
            'page_size' => ['sometimes', 'integer', 'min:1', 'max:100'],
            'pageSize' => ['prohibited'],
            'per_page' => ['prohibited'],
            'sort' => ['sometimes', 'string', Rule::in(self::SORTS)],
            'direction' => ['sometimes', 'string', Rule::in(['asc', 'desc'])],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $this->validateDateRange($validator);
            $this->validateStatusList($validator);
        });
    }

    /**
     * status 支持逗号分隔的多状态过滤，例如 queued,running,retrying。
     *
     * @return list<string>
     */
    public function statuses(): array
    {
        $raw = trim((string) ($this->validated()['status'] ?? ''));

        return $raw === ''
            ? []
            : array_values(array_intersect(self::STATUSES, array_map('trim', explode(',', $raw))));
    }

    private function validateStatusList(Validator $validator): void
    {
        $raw = trim((string) $this->input('status'));
        if ($raw === '') {
            return;
        }

        $invalid = array_values(array_filter(
            array_map('trim', explode(',', $raw)),
            fn (string $item): bool => $item !== '' && ! in_array($item, self::STATUSES, true),
        ));

        if ($invalid !== []) {
            $validator->errors()->add('status', '包含不支持的状态：'.implode(',', array_unique($invalid)));
        }
    }
}
