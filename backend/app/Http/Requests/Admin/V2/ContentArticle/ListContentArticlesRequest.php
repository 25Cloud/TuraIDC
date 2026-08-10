<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin\V2\ContentArticle;

use App\Http\Requests\Admin\V2\Common\AdminFormRequest;
use App\Models\ContentArticle;
use Illuminate\Validation\Rule;

class ListContentArticlesRequest extends AdminFormRequest
{
    public function rules(): array
    {
        return [
            'page' => ['sometimes', 'integer', 'min:1'],
            'page_size' => ['sometimes', 'integer', 'min:1', 'max:100'],
            'pageSize' => ['prohibited'],
            'per_page' => ['prohibited'],
            'content_type' => ['sometimes', 'string', Rule::in([ContentArticle::TYPE_NOTICE, ContentArticle::TYPE_HELP])],
            'type' => ['prohibited'],
            'status' => ['sometimes', 'integer', Rule::in([
                ContentArticle::STATUS_DRAFT,
                ContentArticle::STATUS_PUBLISHED,
                ContentArticle::STATUS_OFFLINE,
            ])],
            'keyword' => ['sometimes', 'string', 'max:100'],
            'category_id' => ['sometimes', 'integer', Rule::exists('content_categories', 'id')],
            'content_category_id' => ['prohibited'],
            'is_pinned' => ['sometimes', 'integer', Rule::in([0, 1])],
        ];
    }
}
