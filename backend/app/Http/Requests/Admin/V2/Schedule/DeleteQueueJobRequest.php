<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin\V2\Schedule;

use App\Http\Requests\Admin\V2\Common\AdminFormRequest;
use App\Services\Automation\QueueJobService;
use Illuminate\Validation\Rule;

/**
 * 队列的删除与清空只允许作用于「已失败」。
 * pending（jobs 表）里是尚未运行的业务任务，删除即静默丢弃，
 * 因此在请求层就拒绝，而不是等 service 抛业务异常——语义上这是非法请求。
 */
class DeleteQueueJobRequest extends AdminFormRequest
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
            'queue.in' => '待执行队列只读，仅允许删除已失败的任务',
        ];
    }
}
