<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin\V2\Product;

use App\Http\Requests\Admin\V2\Common\AdminFormRequest;
use App\Support\AutomationScheduleExpression;
use Illuminate\Validation\Rule;

class UpdateDescriptionSyncSettingsRequest extends AdminFormRequest
{
    public function rules(): array
    {
        return [
            'schedule_mode' => ['required', 'string', Rule::in(AutomationScheduleExpression::modes())],
            'schedule_time' => ['required', 'string', 'regex:/^\d{2}:\d{2}:\d{2}$/'],
        ];
    }
}
