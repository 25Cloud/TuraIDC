<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin\V2\ContentCategory;

use App\Http\Requests\Admin\V2\Common\AdminFormRequest;
use App\Models\ContentArticle;
use App\Models\ContentCategory;
use Illuminate\Validation\Rule;

class UpdateContentCategoryRequest extends AdminFormRequest
{
    public function rules(): array
    {
        /** @var ContentCategory|null $category */
        $category = $this->route('category');

        return [
            'page' => ['prohibited'],
            'page_size' => ['prohibited'],
            'pageSize' => ['prohibited'],
            'per_page' => ['prohibited'],
            'content_type' => ['required', Rule::in([ContentArticle::TYPE_NOTICE, ContentArticle::TYPE_HELP])],
            'type' => ['prohibited'],
            'name' => ['required', 'string', 'max:80'],
            'slug' => [
                'nullable',
                'string',
                'max:120',
                Rule::unique('content_categories', 'slug')
                    ->ignore($category?->id)
                    ->where(fn ($query) => $query->where('content_type', (string) $this->input('content_type'))),
            ],
            'description' => ['nullable', 'string', 'max:255'],
            'status' => ['nullable', 'integer', Rule::in([0, 1])],
            'sort_order' => ['nullable', 'integer', 'min:0', 'max:999999'],
        ];
    }
}
