<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin\V2\Schedule;

use App\Http\Requests\Admin\V2\Common\AdminFormRequest;

class TriggerScheduleTaskRequest extends AdminFormRequest
{
    public function rules(): array
    {
        return [
            'task' => ['required', 'string'],
            'per_page' => ['prohibited'],
            'pageSize' => ['prohibited'],
        ];
    }

    public function task(): string
    {
        return (string) $this->validated()['task'];
    }
}
