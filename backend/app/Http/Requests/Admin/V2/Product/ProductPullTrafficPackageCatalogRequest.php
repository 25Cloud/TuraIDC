<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin\V2\Product;

use App\Http\Requests\Admin\V2\Common\AdminFormRequest;
use App\Models\SecondProductGroup;
use App\Models\ThirdProductGroup;
use Illuminate\Validation\Rule;

class ProductPullTrafficPackageCatalogRequest extends AdminFormRequest
{
    public function rules(): array
    {
        return [
            'second_product_group_id' => ['required_without:third_product_group_id', 'integer', 'min:1', Rule::exists((new SecondProductGroup)->getTable(), 'id')],
            'third_product_group_id' => ['nullable', 'integer', 'min:1', Rule::exists((new ThirdProductGroup)->getTable(), 'id')],
            'product_type' => ['nullable', 'string', 'max:50'],
            'supplier_id' => ['nullable', 'integer', 'min:1'],
            'upstream_product_id' => ['nullable', 'integer', 'min:1'],
            'source_product_id' => ['nullable', 'integer', 'min:1'],
            'page' => ['prohibited'],
            'page_size' => ['prohibited'],
            'pageSize' => ['prohibited'],
            'per_page' => ['prohibited'],
        ];
    }
}
