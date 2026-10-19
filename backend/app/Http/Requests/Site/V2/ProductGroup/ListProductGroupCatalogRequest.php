<?php

declare(strict_types=1);

namespace App\Http\Requests\Site\V2\ProductGroup;

use Illuminate\Foundation\Http\FormRequest;

class ListProductGroupCatalogRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'group' => ['required', 'integer', 'min:1'],
            // 指定要随目录一起下发的三级分组；不传时下发第一个可见三级分组
            'child_group_id' => ['sometimes', 'integer', 'min:1'],
            // 旧三段商品深链的第三段：可能是三级分组 ID，也可能是商品 ID，
            // 由服务端解析出它归属的三级分组，避免前端再多发一轮请求。
            'resolve_id' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['prohibited'],
            'pageSize' => ['prohibited'],
            'page' => ['prohibited'],
            'page_size' => ['prohibited'],
        ];
    }

    public function validationData(): array
    {
        return array_merge(parent::validationData(), [
            'group' => $this->route('group'),
        ]);
    }
}
