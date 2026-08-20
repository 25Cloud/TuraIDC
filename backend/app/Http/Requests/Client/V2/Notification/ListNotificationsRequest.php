<?php

declare(strict_types=1);

namespace App\Http\Requests\Client\V2\Notification;

use Illuminate\Foundation\Http\FormRequest;

class ListNotificationsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'unread_only' => ['sometimes', 'boolean'],
            'page' => ['sometimes', 'integer', 'min:1'],
            'page_size' => ['sometimes', 'integer', 'min:1', 'max:50'],
            'pageSize' => ['prohibited'],
            'per_page' => ['prohibited'],
        ];
    }

    public function unreadOnly(): bool
    {
        return (bool) $this->boolean('unread_only');
    }

    public function pageNumber(): int
    {
        return max(1, (int) $this->integer('page', 1));
    }

    public function perPage(int $default = 15, int $max = 50): int
    {
        return max(1, min((int) $this->integer('page_size', $default), $max));
    }
}
