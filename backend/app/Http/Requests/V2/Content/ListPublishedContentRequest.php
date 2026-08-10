<?php

declare(strict_types=1);

namespace App\Http\Requests\V2\Content;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ListPublishedContentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'keyword' => ['sometimes', 'string', 'max:100'],
            'category_id' => ['sometimes', 'integer', Rule::exists('content_categories', 'id')],
            'content_category_id' => ['sometimes', 'integer', Rule::exists('content_categories', 'id')],
            'is_recommended' => ['sometimes', 'integer', Rule::in([0, 1])],
            'page' => ['sometimes', 'integer', 'min:1'],
            'page_size' => ['sometimes', 'integer', 'min:1', 'max:50'],
            'pageSize' => ['prohibited'],
            'per_page' => ['prohibited'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function filters(): array
    {
        $filters = $this->safe()->only([
            'keyword',
            'category_id',
            'content_category_id',
            'is_recommended',
        ]);

        if (empty($filters['category_id']) && ! empty($filters['content_category_id'])) {
            $filters['category_id'] = (int) $filters['content_category_id'];
        }

        return $filters;
    }

    public function perPage(int $default = 10): int
    {
        return max(1, min((int) $this->integer('page_size', $default), 50));
    }
}
