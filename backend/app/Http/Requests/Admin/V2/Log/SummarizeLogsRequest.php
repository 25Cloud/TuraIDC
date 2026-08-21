<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin\V2\Log;

use App\Http\Requests\Admin\V2\Common\AdminFormRequest;
use App\Services\System\AdminLogV2QueryService;
use Illuminate\Validation\Rule;

class SummarizeLogsRequest extends AdminFormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'channel' => ['required', Rule::in(AdminLogV2QueryService::channels())],
            'page' => ['prohibited'],
            'page_size' => ['prohibited'],
            'per_page' => ['prohibited'],
            'pageSize' => ['prohibited'],
            'keyword' => ['sometimes', 'string', 'max:120'],
            'actor_keyword' => ['sometimes', 'string', 'max:120'],
            'description_keyword' => ['sometimes', 'string', 'max:120'],
            'ip_address' => ['sometimes', 'string', 'max:45'],
            'level' => ['sometimes', 'in:DEBUG,INFO,NOTICE,WARNING,ERROR,CRITICAL,ALERT,EMERGENCY'],
            'module' => ['sometimes', 'string', 'max:60'],
            'method' => ['sometimes', 'in:GET,POST,PUT,PATCH,DELETE,OPTIONS,HEAD'],
            'status' => ['sometimes', 'string', 'max:20'],
            'task_key' => ['sometimes', 'string', 'max:60'],
            'task_name' => ['sometimes', 'string', 'max:120'],
            'user_type' => ['sometimes', 'in:admin,client,guest'],
            'phone' => ['sometimes', 'string', 'max:30'],
            'email' => ['sometimes', 'string', 'max:120'],
            'gateway' => ['sometimes', 'string', 'max:50'],
            'gateway_key' => ['sometimes', 'string', 'max:120'],
            'driver_key' => ['sometimes', 'string', 'max:120'],
            'plugin_id' => ['sometimes', 'integer', 'min:1'],
            'trace_id' => ['sometimes', 'string', 'max:64'],
            'action' => ['sometimes', 'string', 'max:100'],
            'result_status' => ['sometimes', 'in:success,failed,pending,unknown'],
            'actor_type' => ['sometimes', 'in:admin,client,system,sub_account'],
            'subject_type' => ['sometimes', 'string', 'max:50'],
            'ticket_id' => ['sometimes', 'integer', 'min:1'],
            'ticket_reply_id' => ['sometimes', 'integer', 'min:1'],
            'operation' => ['sometimes', 'string', 'max:32'],
            'event' => ['sometimes', 'string', 'max:32'],
            'reason_code' => ['sometimes', 'string', 'max:64'],
            'provider_key' => ['sometimes', 'string', 'max:64'],
            'supplier_id' => ['sometimes', 'integer', 'min:1'],
            'start_date' => ['sometimes', 'date'],
            'end_date' => ['sometimes', 'date'],
        ];
    }

    public function validationData(): array
    {
        return array_merge(parent::validationData(), [
            'channel' => $this->route('channel'),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function filters(): array
    {
        return $this->safe()->only([
            'keyword',
            'actor_keyword',
            'description_keyword',
            'ip_address',
            'level',
            'module',
            'method',
            'status',
            'task_key',
            'task_name',
            'user_type',
            'phone',
            'email',
            'gateway',
            'gateway_key',
            'driver_key',
            'plugin_id',
            'trace_id',
            'action',
            'result_status',
            'actor_type',
            'subject_type',
            'ticket_id',
            'ticket_reply_id',
            'operation',
            'event',
            'reason_code',
            'provider_key',
            'supplier_id',
            'start_date',
            'end_date',
        ]);
    }
}
