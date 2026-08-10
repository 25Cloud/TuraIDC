<?php

declare(strict_types=1);

namespace App\Http\Requests\Client\V2\Notification;

use Illuminate\Foundation\Http\FormRequest;

class ListNotificationFeedRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'limit' => ['sometimes', 'integer', 'min:1', 'max:30'],
            'page' => ['prohibited'],
            'page_size' => ['prohibited'],
            'pageSize' => ['prohibited'],
            'per_page' => ['prohibited'],
        ];
    }

    public function limit(): int
    {
        return max(1, min((int) $this->integer('limit', 10), 30));
    }
}
