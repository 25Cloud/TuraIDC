<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin\V2\Setting;

use App\Http\Requests\Admin\V2\Common\AdminFormRequest;

class UpdateSettingsRequest extends AdminFormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'group' => ['nullable', 'string', 'max:50'],
            'settings' => ['required', 'array'],
            'per_page' => ['prohibited'],
            'pageSize' => ['prohibited'],
        ];
    }

    public function group(): string
    {
        $group = trim((string) $this->validated('group', 'system'));

        return $group !== '' ? $group : 'system';
    }

    /**
     * @return array<string, mixed>
     */
    public function settings(): array
    {
        return (array) $this->validated('settings', []);
    }
}
