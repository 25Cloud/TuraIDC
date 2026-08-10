<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin\V2\Setting;

use App\Http\Requests\Admin\V2\Common\AdminFormRequest;

class RevealSettingSecretRequest extends AdminFormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'per_page' => ['prohibited'],
            'pageSize' => ['prohibited'],
        ];
    }

    public function groupKey(): string
    {
        return trim((string) $this->route('group', ''));
    }

    public function secretKey(): string
    {
        return trim((string) $this->route('key', ''));
    }
}
