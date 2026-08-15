<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin\V2\Verification;

use App\Http\Requests\Admin\V2\Common\AdminFormRequest;
use Illuminate\Validation\Validator;

class ListVerificationsRequest extends AdminFormRequest
{
    public function rules(): array
    {
        return [
            'keyword' => ['nullable', 'string', 'max:100'],
            'is_verified' => ['nullable', 'in:0,1'],
            'verification_status' => ['nullable', 'in:0,1,2,3,4,5'],
            'page' => ['nullable', 'integer', 'min:1'],
            'page_size' => ['nullable', 'integer', 'min:1', 'max:100'],
            'per_page' => ['prohibited'],
            'pageSize' => ['prohibited'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(fn (Validator $validator) => $this->rejectPrivacyFilters($validator, [], [
            'keyword' => '邮箱、手机号、证件号等隐私关键词',
        ]));
    }

    /**
     * @return array<string, mixed>
     */
    public function filters(): array
    {
        return $this->safe()->only([
            'keyword',
            'is_verified',
            'verification_status',
        ]);
    }

    public function pageSize(): int
    {
        return $this->perPage(20, 100);
    }
}
