<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin\V2\Product;

use App\Http\Requests\Admin\V2\Common\AdminFormRequest;
use App\Support\ProductProvisionHostname;
use Illuminate\Validation\Rule;

class ProductBatchUpdateProvisionHostnameRequest extends AdminFormRequest
{
    public function rules(): array
    {
        return [
            'product_ids' => ['required', 'array', 'min:1', 'max:200'],
            'product_ids.*' => ['integer', 'min:1', 'distinct'],
            'provision_hostname' => ['required', 'array'],
            'provision_hostname.mode' => ['required', 'string', Rule::in(ProductProvisionHostname::modes())],
            'provision_hostname.value' => ['nullable', 'string', 'max:200'],
            'provision_hostname.length' => ['nullable', 'integer', 'min:4', 'max:63'],
            'page' => ['prohibited'],
            'page_size' => ['prohibited'],
            'pageSize' => ['prohibited'],
            'per_page' => ['prohibited'],
        ];
    }
}
