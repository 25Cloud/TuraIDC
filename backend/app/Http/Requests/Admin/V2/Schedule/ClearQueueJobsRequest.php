<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin\V2\Schedule;

use App\Http\Requests\Admin\V2\Common\AdminFormRequest;
use App\Services\Automation\QueueJobService;
use Illuminate\Validation\Rule;

class ClearQueueJobsRequest extends AdminFormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'queue' => ['required', 'string', Rule::in([QueueJobService::QUEUE_FAILED])],
        ];
    }

    /**
     * queue 是路由参数（{queue}），FormRequest 默认只校验查询与请求体，必须手动并入。
     */
    public function validationData(): array
    {
        return array_merge($this->all(), ['queue' => (string) $this->route('queue')]);
    }

    public function messages(): array
    {
        return [
            'queue.in' => '只允许清空已失败的任务',
        ];
    }
}
