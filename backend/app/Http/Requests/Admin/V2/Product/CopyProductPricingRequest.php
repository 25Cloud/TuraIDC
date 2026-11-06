<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin\V2\Product;

use App\Constants\BillingCycle;
use App\Http\Requests\Admin\V2\Common\AdminFormRequest;
use Illuminate\Validation\Rule;

/**
 * 「按月价同步到其他周期」的入参校验。
 *
 * 周期取值只认 `BillingCycle::RENEWABLE`（月付/季付/半年付/年付）：
 * 一次性与免费没有月数，二进制/三年付不在本站在售枚举内，传进来一律 422，
 * 不做「猜一个相近周期」的兜底——算错周期就是把价目表写错。
 *
 * 别名（yearly / biannually）同样拒收：本接口是写入口，只接受规范值；
 * 别名兼容留在读侧（`BillingCycle::normalize()`）。
 */
final class CopyProductPricingRequest extends AdminFormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $fromCycle = BillingCycle::normalize((string) $this->input('from_cycle', ''));

        return [
            'from_cycle' => ['required', 'string', Rule::in(BillingCycle::RENEWABLE)],
            'to_cycles' => ['required', 'array', 'min:1'],
            'to_cycles.*' => [
                'required',
                'string',
                'distinct',
                Rule::in(BillingCycle::RENEWABLE),
                Rule::notIn([$fromCycle]),
            ],
            'overwrite' => ['sometimes', 'boolean'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'from_cycle.required' => '请选择来源计费周期',
            'from_cycle.string' => '来源计费周期不合法',
            'from_cycle.in' => '来源计费周期不合法，只支持月付/季付/半年付/年付',
            'to_cycles.required' => '请选择要同步的目标周期',
            'to_cycles.array' => '目标周期格式不正确',
            'to_cycles.min' => '请至少选择一个目标周期',
            'to_cycles.*.required' => '目标周期不能为空',
            'to_cycles.*.string' => '目标周期不合法',
            'to_cycles.*.distinct' => '目标周期不能重复',
            'to_cycles.*.in' => '目标计费周期不合法，只支持月付/季付/半年付/年付',
            'to_cycles.*.not_in' => '目标周期不能与来源周期相同',
            'overwrite.boolean' => '是否覆盖已有价格只能是 true 或 false',
        ];
    }
}
