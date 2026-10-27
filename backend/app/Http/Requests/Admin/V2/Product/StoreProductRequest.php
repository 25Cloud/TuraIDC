<?php

namespace App\Http\Requests\Admin\V2\Product;

use App\Http\Requests\Admin\V2\Common\AdminFormRequest;
use App\Models\Product;
use App\Models\ThirdProductGroup;
use Illuminate\Validation\Rule;

class StoreProductRequest extends AdminFormRequest
{
    public function rules(): array
    {
        return [
            'name' => ['nullable', 'string', 'max:255'],
            'display_name' => ['nullable', 'string', 'max:255'],
            'custom_display_name' => ['nullable', 'string', 'max:255'],
            'product_type' => ['nullable', 'string', 'max:50'],
            'service_type_code' => ['nullable', 'string', 'max:50'],
            'console_template' => ['nullable', 'string', Rule::in(Product::CONSOLE_TEMPLATES)],
            'first_product_group_id' => ['nullable', 'integer', 'min:1'],
            'second_product_group_id' => ['nullable', 'integer', 'min:1'],
            'third_product_group_id' => ['required', 'integer', 'min:1', Rule::exists((new ThirdProductGroup)->getTable(), 'id')],
            'product_discount_group_id' => ['nullable', 'integer', 'exists:product_discount_groups,id'],
            'cpu_model' => ['nullable', 'string', 'max:120'],
            'cpu_turbo' => ['nullable', 'string', 'max:40'],
            'remark' => ['nullable', 'string', 'max:255'],
            // 描述是富文本片段，落库前由 ProductDescriptionService 过白名单消毒。
            // 这里只限长度：HTML 体积本身没有业务上限，text 列上限 65535 字节。
            'description' => ['nullable', 'string', 'max:60000'],
            'pricing' => ['required', 'array'],
            'pricing.monthly' => ['nullable', 'numeric', 'min:0'],
            'pricing.quarterly' => ['nullable', 'numeric', 'min:0'],
            'pricing.semiannually' => ['nullable', 'numeric', 'min:0'],
            'pricing.annually' => ['nullable', 'numeric', 'min:0'],
            'setup_fee' => ['nullable', 'numeric', 'min:0'],
            'config_options' => ['nullable', 'array'],
            'purchase_requires' => ['nullable', 'array'],
            'purchase_requires.require_verification' => ['nullable', 'boolean'],
            'purchase_requires.require_phone' => ['nullable', 'boolean'],
            'purchase_requires.provision_hostname' => ['nullable', 'array'],
            'purchase_requires.provision_hostname.mode' => ['nullable', 'string', Rule::in(['system', 'fixed', 'prefix'])],
            'purchase_requires.provision_hostname.value' => ['nullable', 'string', 'max:200'],
            'purchase_requires.provision_hostname.length' => ['nullable', 'integer', 'min:4', 'max:63'],
            'stock' => ['nullable', 'integer', 'min:-1'],
            'status' => ['nullable', 'in:0,1'],
            'sort_order' => ['nullable', 'integer', 'min:0', 'max:999999'],
            'auto_setup' => ['nullable', 'in:0,1'],
            'upstream_binding' => ['nullable', 'array'],
            'upstream_binding.supplier_id' => ['nullable', 'integer', 'exists:suppliers,id'],
            'upstream_binding.upstream_product_id' => ['nullable'],
            ...$this->allPaginationRules(),
        ];
    }

    /**
     * 层级校验必须给中文、可操作的说法。
     *
     * `products.product_group_id` 外键指向 third_product_groups，商品只能挂在三级分组下；
     * 默认的 Laravel 文案在英文 locale 下是「The third product group id field is required.」，
     * 前端再被过滤成「参数验证失败」，管理员根本不知道该做什么。
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'third_product_group_id.required' => '请选择三级分类：商品必须挂载到三级分组下（二级分组下需要先建三级分组）',
            'third_product_group_id.integer' => '三级分类不合法，请重新选择',
            'third_product_group_id.min' => '三级分类不合法，请重新选择',
            'third_product_group_id.exists' => '所选三级分类不存在或已被删除，请刷新后重新选择',
            'pricing.required' => '请至少配置一个计费周期的价格',
            'pricing.array' => '计费周期价格格式不正确',
            'pricing.monthly.numeric' => '月付价格必须是数字',
            'pricing.quarterly.numeric' => '季付价格必须是数字',
            'pricing.semiannually.numeric' => '半年付价格必须是数字',
            'pricing.annually.numeric' => '年付价格必须是数字',
            'console_template.in' => '用户控制台模板不在允许范围内',
            'product_discount_group_id.exists' => '所选商品折扣分组不存在',
            'upstream_binding.supplier_id.exists' => '所选供应商不存在',
        ];
    }
}
