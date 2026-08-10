<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin\V2\Product;

use App\Http\Requests\Admin\V2\Common\AdminFormRequest;

class ListProductOwnersRequest extends AdminFormRequest
{
    public function rules(): array
    {
        return array_merge($this->paginationRules(), [
            'keyword' => ['nullable', 'string', 'max:100'],
            'pageSize' => ['prohibited'],
            'per_page' => ['prohibited'],
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function filters(): array
    {
        return $this->safe()->only(['keyword']);
    }
}
