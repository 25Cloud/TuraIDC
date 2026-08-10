<?php

declare(strict_types=1);

namespace App\Http\Requests\Site\V2;

use Illuminate\Foundation\Http\FormRequest;

class SiteHomeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'group_limit' => ['nullable', 'integer', 'min:1', 'max:8'],
            'notice_limit' => ['nullable', 'integer', 'min:1', 'max:10'],
            'help_limit' => ['nullable', 'integer', 'min:1', 'max:8'],
            'per_page' => ['prohibited'],
        ];
    }

    public function groupLimit(): int
    {
        return (int) ($this->validated('group_limit') ?? 8);
    }

    public function noticeLimit(): int
    {
        return (int) ($this->validated('notice_limit') ?? 6);
    }

    public function helpLimit(): int
    {
        return (int) ($this->validated('help_limit') ?? 4);
    }
}
