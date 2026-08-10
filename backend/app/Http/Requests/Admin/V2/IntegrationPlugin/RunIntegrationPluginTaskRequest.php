<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin\V2\IntegrationPlugin;

use App\Http\Requests\Admin\V2\Common\AdminFormRequest;
use Illuminate\Validation\Rule;

class RunIntegrationPluginTaskRequest extends AdminFormRequest
{
    public const TYPE_HEALTH_CHECK = 'health_check';

    public const TYPE_TEST_EMAIL = 'test_email';

    public const TYPE_TEST_SMS = 'test_sms';

    protected function prepareForValidation(): void
    {
        $payload = $this->input('payload', []);
        $payload = is_array($payload) ? $payload : [];

        if (array_key_exists('to', $payload)) {
            $payload['to'] = trim((string) $payload['to']);
        }

        if (array_key_exists('subject', $payload)) {
            $payload['subject'] = trim((string) $payload['subject']);
        }

        if (array_key_exists('phone', $payload)) {
            $payload['phone'] = trim((string) $payload['phone']);
        }

        $this->merge([
            'type' => trim((string) $this->input('type', '')),
            'payload' => $payload,
        ]);
    }

    public function rules(): array
    {
        $type = (string) $this->input('type', '');

        return [
            'type' => ['required', 'string', Rule::in([
                self::TYPE_HEALTH_CHECK,
                self::TYPE_TEST_EMAIL,
                self::TYPE_TEST_SMS,
            ])],
            'payload' => ['nullable', 'array'],
            'payload.account_index' => [
                Rule::requiredIf($type === self::TYPE_TEST_EMAIL),
                'integer',
                'min:0',
            ],
            'payload.to' => [
                Rule::requiredIf($type === self::TYPE_TEST_EMAIL),
                'email',
                'max:100',
            ],
            'payload.subject' => [
                'nullable',
                'string',
                'max:255',
            ],
            'payload.body' => ['nullable', 'string', 'max:5000'],
            'payload.phone' => [
                Rule::requiredIf($type === self::TYPE_TEST_SMS),
                'string',
                'max:20',
            ],
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
