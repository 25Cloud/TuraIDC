<?php

declare(strict_types=1);

namespace App\Http\Requests\Client\V2\Notification;

use Illuminate\Foundation\Http\FormRequest;

class ShowNotificationUnreadCountRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'limit' => ['prohibited'],
            'page' => ['prohibited'],
            'page_size' => ['prohibited'],
            'pageSize' => ['prohibited'],
            'per_page' => ['prohibited'],
        ];
    }
}
