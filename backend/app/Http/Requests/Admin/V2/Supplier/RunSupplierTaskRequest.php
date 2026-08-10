<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin\V2\Supplier;

use App\Http\Requests\Admin\V2\Common\AdminFormRequest;

class RunSupplierTaskRequest extends AdminFormRequest
{
    protected function prepareForValidation(): void
    {
        $payload = $this->input('payload', []);

        $this->merge([
            'type' => trim((string) $this->input('type', '')),
            'payload' => is_array($payload) ? $payload : [],
        ]);
    }

    public function rules(): array
    {
        return [
            'type' => ['required', 'string', 'regex:/\A[A-Za-z0-9_.-]+\z/'],
            'payload' => ['nullable', 'array'],
            'page' => ['prohibited'],
            'page_size' => ['prohibited'],
            'pageSize' => ['prohibited'],
            'per_page' => ['prohibited'],
        ];
    }

    public function taskType(): string
    {
        return (string) $this->validated()['type'];
    }

    /**
     * @return array<string, mixed>
     */
    public function payload(): array
    {
        $payload = $this->validated()['payload'] ?? [];

        return is_array($payload) ? $payload : [];
    }
}
