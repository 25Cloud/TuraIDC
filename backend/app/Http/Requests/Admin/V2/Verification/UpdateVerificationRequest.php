<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin\V2\Verification;

use App\Http\Requests\Admin\V2\Common\AdminFormRequest;
use App\Support\TextSanitizer;
use Illuminate\Contracts\Validation\Validator;

class UpdateVerificationRequest extends AdminFormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'verification_status' => ['nullable', 'integer', 'in:0,2,3,5'],
            'real_name' => ['nullable', 'string', 'max:50'],
            'id_card' => ['nullable', 'string', 'regex:/^[0-9A-Za-z]{15,24}$/'],
            'verification_message' => ['nullable', 'string', 'max:255'],
            'page' => ['prohibited'],
            'page_size' => ['prohibited'],
        ];
    }

    /**
     * 至少提供一项可执行变更，避免空请求空转。
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $hasChange = $this->filled('verification_status')
                || $this->filled('real_name')
                || $this->filled('id_card');

            if (! $hasChange) {
                $validator->errors()->add('verification_status', '请至少设置实名状态、真实姓名或证件号码其中一项');
            }
        });
    }

    /**
     * 名称等文本统一剥掉 HTML 标签，防止存储型 XSS。
     *
     * @return array<string, mixed>
     */
    public function payload(): array
    {
        $validated = $this->validated();

        if (isset($validated['real_name']) && is_string($validated['real_name'])) {
            $validated['real_name'] = TextSanitizer::clean($validated['real_name']);
        }

        if (isset($validated['verification_message']) && is_string($validated['verification_message'])) {
            $validated['verification_message'] = TextSanitizer::clean($validated['verification_message'], preserveNewLines: true);
        }

        return $validated;
    }
}
