<?php

namespace App\Http\Requests\Site;

use Illuminate\Foundation\Http\FormRequest;

class QuoteProductRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'billing_cycle' => ['required', 'string', 'max:30'],
            'config' => ['nullable', 'array', 'max:60'],
            'config.*' => ['required', function ($attribute, $value, $fail) {
                if (is_array($value)) {
                    $fail('配置项不支持嵌套结构');

                    return;
                }
                if (is_string($value) && mb_strlen($value) > 128) {
                    $fail('配置项值长度不能超过128个字符');
                }
            }],
            'quantity' => ['nullable', 'integer', 'min:1', 'max:10'],
            'user_coupon_id' => ['nullable', 'integer', 'min:1'],
        ];
    }
}
