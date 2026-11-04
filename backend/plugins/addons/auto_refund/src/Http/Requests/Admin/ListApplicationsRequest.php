<?php

declare(strict_types=1);

namespace TuraIDC\Plugins\Addons\AutoRefund\Http\Requests\Admin;

use App\Http\Requests\Admin\V2\Common\AdminFormRequest;

/**
 * 管理端退款台账筛选。
 *
 * 日期用 `start_date` / `end_date`（Y-m-d），服务层按 `created_at` 范围比较，
 * 不用 whereDate()——函数包裹会让索引失效。
 */
final class ListApplicationsRequest extends AdminFormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return array_merge($this->paginationRules(100), [
            'status' => ['nullable', 'string', 'max:64'],
            'user_id' => ['nullable', 'integer', 'min:1'],
            'service_id' => ['nullable', 'integer', 'min:1'],
            'application_no' => ['nullable', 'string', 'max:32'],
            'keyword' => ['nullable', 'string', 'max:64'],
            'start_date' => ['nullable', 'date_format:Y-m-d'],
            'end_date' => ['nullable', 'date_format:Y-m-d'],
        ]);
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'start_date.date_format' => '开始日期格式必须为 YYYY-MM-DD',
            'end_date.date_format' => '结束日期格式必须为 YYYY-MM-DD',
        ];
    }
}
