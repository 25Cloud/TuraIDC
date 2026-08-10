<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin\V2\Log;

use App\Http\Requests\Admin\V2\Common\AdminFormRequest;
use App\Services\System\AdminLogV2QueryService;
use Illuminate\Validation\Rule;

class ShowLogRequest extends AdminFormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'channel' => ['required', Rule::in(AdminLogV2QueryService::channels())],
            'log' => ['required', 'string', 'max:120'],
            'page' => ['prohibited'],
            'page_size' => ['prohibited'],
            'per_page' => ['prohibited'],
            'pageSize' => ['prohibited'],
        ];
    }

    public function validationData(): array
    {
        return array_merge(parent::validationData(), [
            'channel' => $this->route('channel'),
            'log' => $this->route('log'),
        ]);
    }
}
