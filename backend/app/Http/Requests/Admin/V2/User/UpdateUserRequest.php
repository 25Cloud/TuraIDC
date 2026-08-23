<?php

namespace App\Http\Requests\Admin\V2\User;

use App\Http\Requests\Admin\V2\Common\AdminFormRequest;
use App\Models\User;
use App\Support\AccountIdentifier;
use Illuminate\Validation\Rule;

class UpdateUserRequest extends AdminFormRequest
{
    protected function prepareForValidation(): void
    {
        if ($this->has('phone')) {
            $this->merge([
                'phone' => AccountIdentifier::normalizeOptionalPhone((string) $this->input('phone')),
            ]);
        }
    }

    public function rules(): array
    {
        $user = $this->route('user');
        $ignoreUserId = $user instanceof User ? (int) $user->id : (is_numeric($user) ? (int) $user : null);

        $rules = [
            'nickname' => ['nullable', 'string', 'max:50'],
            'phone' => [
                'nullable',
                'string',
                'max:20',
                function (string $attribute, mixed $value, \Closure $fail) {
                    if ($value !== null && $value !== '' && AccountIdentifier::detectType((string) $value) !== 'phone') {
                        $fail('请输入正确的手机号');
                    }
                },
                Rule::unique('users', 'phone')->ignore($ignoreUserId),
            ],
            'password' => ['nullable', 'string', 'min:8'],
            'status' => ['nullable', 'in:0,1'],
            'credit_limit' => ['nullable', 'numeric', 'min:0'],
            'admin_note' => ['nullable', 'string', 'max:2000'],
        ];

        $rules['phone'][0] = 'sometimes';
        array_splice($rules['phone'], 1, 0, 'required');

        return array_merge($rules, $this->allPaginationRules());
    }

    public function validatedPayload(): array
    {
        return $this->safe()->only([
            'nickname',
            'phone',
            'password',
            'status',
            'credit_limit',
            'admin_note',
            'agent_group_id',
        ]);
    }

    public function payload(): array
    {
        return $this->validatedPayload();
    }
}
