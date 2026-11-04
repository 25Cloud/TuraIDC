<?php

declare(strict_types=1);

namespace TuraIDC\Plugins\Addons\AutoRefund\Http\Requests\Admin;

use App\Http\Requests\Admin\V2\Common\AdminFormRequest;

/**
 * 商品退款策略列表筛选。
 *
 * `status` 只允许三个值：`all` 全部、`configured` 已配置、`unconfigured` 未配置。
 * 「已配置」按「有没有策略行」判定，与 `enabled` 是否开启无关——停用的策略仍然
 * 是配置过的，运营要能把它改回来。
 */
final class ListProductRulesRequest extends AdminFormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return array_merge($this->paginationRules(100), [
            'keyword' => ['nullable', 'string', 'max:64'],
            'status' => ['nullable', 'string', 'in:all,configured,unconfigured'],
        ]);
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'status.in' => '状态筛选只支持 all / configured / unconfigured',
        ];
    }
}
