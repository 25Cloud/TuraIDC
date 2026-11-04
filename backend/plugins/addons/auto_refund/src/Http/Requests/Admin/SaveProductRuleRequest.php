<?php

declare(strict_types=1);

namespace TuraIDC\Plugins\Addons\AutoRefund\Http\Requests\Admin;

use App\Http\Requests\Admin\V2\Common\AdminFormRequest;
use Illuminate\Validation\Rule;
use TuraIDC\Plugins\Addons\AutoRefund\Models\AutoRefundProductRule;

/**
 * 保存单个商品的退款策略。
 *
 * 四个枚举值必须来自受控清单（`AutoRefundProductRule` 的常量真源），
 * 不收任意字符串——这些值直接决定走哪条通道、怎么算钱。
 *
 * `window_hours` 只有 `requirement = within_hours` 时才生效，但任何情况下都允许传：
 * 运营先填窗口再切要求是常见顺序，为此要求「先改要求再填数字」纯属添乱。
 */
final class SaveProductRuleRequest extends AdminFormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'refund_type' => ['required', 'string', Rule::in(AutoRefundProductRule::REFUND_TYPES)],
            'refund_rule' => ['required', 'string', Rule::in(AutoRefundProductRule::REFUND_RULES)],
            'requirement' => ['required', 'string', Rule::in(AutoRefundProductRule::REQUIREMENTS)],
            'window_hours' => ['nullable', 'integer', 'min:0', 'max:87600'],
            'enabled' => ['nullable', 'boolean'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'refund_type.required' => '请选择退款类型',
            'refund_type.in' => '退款类型不合法',
            'refund_rule.required' => '请选择退款规则',
            'refund_rule.in' => '退款规则不合法',
            'requirement.required' => '请选择退款要求',
            'requirement.in' => '退款要求不合法',
            'window_hours.integer' => '时间窗口必须是整数小时',
            'window_hours.min' => '时间窗口不能为负数',
            'window_hours.max' => '时间窗口过大（最多 87600 小时）',
        ];
    }
}
