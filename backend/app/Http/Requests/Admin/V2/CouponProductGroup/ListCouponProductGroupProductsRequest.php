<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin\V2\CouponProductGroup;

use App\Http\Requests\Admin\V2\Common\AdminFormRequest;
use Illuminate\Validation\Rule;

class ListCouponProductGroupProductsRequest extends AdminFormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'group' => ['required', 'integer', 'min:1'],
            'level' => ['required', 'integer', Rule::in([1, 2, 3])],
            'page' => ['nullable', 'integer', 'min:1'],
            'page_size' => ['nullable', 'integer', 'min:1', 'max:100'],
            'per_page' => ['prohibited'],
            'keyword' => ['nullable', 'string', 'max:100'],
            'status' => ['nullable', 'integer', Rule::in([0, 1])],
        ];
    }

    public function validationData(): array
    {
        return [
            ...$this->query(),
            'group' => $this->route('group'),
        ];
    }

    public function attributes(): array
    {
        return [
            'group' => '分组',
            'level' => '分组层级',
            'page' => '页码',
            'page_size' => '每页数量',
            'per_page' => '旧分页参数',
            'keyword' => '关键词',
            'status' => '状态',
        ];
    }

    public function groupId(): int
    {
        return (int) $this->validated('group');
    }

    public function level(): int
    {
        return (int) $this->validated('level');
    }

    public function pageNumber(): int
    {
        return max((int) $this->integer('page', 1), 1);
    }

    public function pageSize(): int
    {
        return min(max((int) $this->integer('page_size', 50), 1), 100);
    }
}
