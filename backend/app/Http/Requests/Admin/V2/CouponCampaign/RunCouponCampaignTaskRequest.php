<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin\V2\CouponCampaign;

use App\Http\Requests\Admin\V2\Common\AdminFormRequest;
use Illuminate\Validation\Rule;

class RunCouponCampaignTaskRequest extends AdminFormRequest
{
    public const TYPE_TRIGGER = 'trigger';

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
            'type' => ['required', 'string', Rule::in([self::TYPE_TRIGGER])],
            'payload' => ['nullable', 'array'],
            'per_page' => ['prohibited'],
            'page' => ['prohibited'],
            'page_size' => ['prohibited'],
            'pageSize' => ['prohibited'],
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
