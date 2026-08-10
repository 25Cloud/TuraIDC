<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin\V2\ContentArticle;

use App\Http\Requests\Admin\V2\Common\AdminFormRequest;
use App\Models\ContentArticle;
use Illuminate\Validation\Rule;

class StoreContentArticleRequest extends AdminFormRequest
{
    public function rules(): array
    {
        return [
            'page' => ['prohibited'],
            'page_size' => ['prohibited'],
            'pageSize' => ['prohibited'],
            'per_page' => ['prohibited'],
            'content_type' => ['required', Rule::in([ContentArticle::TYPE_NOTICE, ContentArticle::TYPE_HELP])],
            'type' => ['prohibited'],
            'category_id' => ['required', 'integer', Rule::exists('content_categories', 'id')],
            'content_category_id' => ['prohibited'],
            'title' => ['required', 'string', 'max:200'],
            'slug' => [
                'nullable',
                'string',
                'max:220',
                Rule::unique('content_articles', 'slug')->where(
                    fn ($query) => $query->where('content_type', (string) $this->input('content_type'))
                ),
            ],
            'summary' => ['nullable', 'string', 'max:500'],
            'content' => ['required', 'string', 'max:30000'],
            'keywords' => ['nullable', 'string', 'max:255'],
            'status' => ['nullable', 'integer', Rule::in([
                ContentArticle::STATUS_DRAFT,
                ContentArticle::STATUS_PUBLISHED,
                ContentArticle::STATUS_OFFLINE,
            ])],
            'is_pinned' => ['nullable', 'integer', Rule::in([0, 1])],
            'cover_image' => ['nullable', 'required_if:is_pinned,1', 'string', 'max:500'],
            'is_recommended' => ['nullable', 'integer', Rule::in([0, 1])],
            'sort_order' => ['nullable', 'integer', 'min:0', 'max:999999'],
            'publish_at' => ['nullable', 'date'],
            'remark' => ['nullable', 'string', 'max:255'],
            'operator' => ['nullable', 'string', 'max:50'],
            'trace_id' => ['prohibited'],
        ];
    }

    public function withValidator($validator): void
    {
        $validator->after(function ($validator): void {
            if ((int) $this->input('is_pinned') === 1 && (int) $this->input('is_recommended') === 1) {
                $validator->errors()->add('is_recommended', '置顶和推荐不能同时选择');
            }
        });
    }
}
