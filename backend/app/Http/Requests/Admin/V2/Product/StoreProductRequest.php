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
            'console_template' => ['nullable', 'string', Rule::in([
                Product::CONSOLE_TEMPLATE_COMPUTE,
                Product::CONSOLE_TEMPLATE_PORT_MAPPING,
            ])],
            'first_product_group_id' => ['nullable', 'integer', 'min:1'],
            'second_product_group_id' => ['nullable', 'integer', 'min:1'],
            'third_product_group_id' => ['required', 'integer', 'min:1', Rule::exists((new ThirdProductGroup)->getTable(), 'id')],
            'product_discount_group_id' => ['nullable', 'integer', 'exists:product_discount_groups,id'],
            'remark' => ['nullable', 'string', 'max:255'],
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
}
