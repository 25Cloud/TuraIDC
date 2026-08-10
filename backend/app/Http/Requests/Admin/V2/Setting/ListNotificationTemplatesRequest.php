<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin\V2\Setting;

use App\Http\Requests\Admin\V2\Common\AdminFormRequest;

class ListNotificationTemplatesRequest extends AdminFormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'channel' => ['nullable', 'string', 'in:email,sms'],
            'page' => ['prohibited'],
            'page_size' => ['prohibited'],
            'pageSize' => ['prohibited'],
            'per_page' => ['prohibited'],
        ];
    }

    public function channel(): ?string
    {
        $channel = trim((string) $this->validated('channel', ''));

        return $channel !== '' ? $channel : null;
    }
}
