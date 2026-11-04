<?php

declare(strict_types=1);

namespace TuraIDC\Plugins\Addons\AutoRefund\Http\Requests\Client;

use App\Http\Requests\Client\V2\Common\ClientFormRequest;

/**
 * 我的退款申请列表：分页 + 状态筛选（状态由服务层白名单过滤，非法值直接忽略）。
 */
final class ListApplicationsRequest extends ClientFormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return array_merge($this->paginationRules(50), [
            'status' => ['nullable', 'string', 'max:64'],
        ]);
    }
}
