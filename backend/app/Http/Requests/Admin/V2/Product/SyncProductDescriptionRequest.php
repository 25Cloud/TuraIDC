<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin\V2\Product;

use App\Http\Requests\Admin\V2\Common\AdminFormRequest;

class SyncProductDescriptionRequest extends AdminFormRequest
{
    public function rules(): array
    {
        return [
            // 单商品同步不需要 product_ids，批量同步必填
            'product_ids' => ['sometimes', 'array', 'min:1'],
            'product_ids.*' => ['required', 'integer', 'min:1'],
            // 是否覆盖本地已有的人工描述，默认覆盖
            'overwrite' => ['sometimes', 'boolean'],
        ];
    }
}
