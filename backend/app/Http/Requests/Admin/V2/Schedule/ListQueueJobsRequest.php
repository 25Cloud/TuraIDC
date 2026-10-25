<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin\V2\Schedule;

use App\Http\Requests\Admin\V2\Common\AdminFormRequest;
use App\Services\Automation\QueueJobService;
use Illuminate\Validation\Rule;

class ListQueueJobsRequest extends AdminFormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'queue' => ['required', 'string', Rule::in([QueueJobService::QUEUE_PENDING, QueueJobService::QUEUE_FAILED])],
            'page' => ['sometimes', 'integer', 'min:1'],
            ...$this->paginationRules(),
        ];
    }

    public function queueName(): string
    {
        return trim((string) $this->validated('queue'));
    }
}
