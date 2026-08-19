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

    public const TYPE_TEST_VERIFICATION = 'test_verification';

    public const TYPE_TEST_PAYMENT = 'test_payment';

    public const TYPE_TEST_CAPTCHA = 'test_captcha';

    public const TYPE_TEST_CONNECTION = 'test_connection';

    protected function prepareForValidation(): void
    {
        $payload = $this->input('payload', []);
        $payload = is_array($payload) ? $payload : [];

        foreach (['to', 'subject', 'phone', 'real_name', 'card_no', 'lot_number', 'captcha_output', 'pass_token', 'gen_time'] as $field) {
            if (array_key_exists($field, $payload)) {
                $payload[$field] = trim((string) $payload[$field]);
            }
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
                self::TYPE_TEST_VERIFICATION,
                self::TYPE_TEST_PAYMENT,
                self::TYPE_TEST_CAPTCHA,
                self::TYPE_TEST_CONNECTION,
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
            'payload.real_name' => [
                Rule::requiredIf($type === self::TYPE_TEST_VERIFICATION),
                'string',
                'max:64',
            ],
            'payload.card_no' => [
                Rule::requiredIf($type === self::TYPE_TEST_VERIFICATION),
                'string',
                'max:32',
            ],
            'payload.lot_number' => [
                Rule::requiredIf($type === self::TYPE_TEST_CAPTCHA),
                'string',
                'max:128',
            ],
            'payload.captcha_output' => [
                Rule::requiredIf($type === self::TYPE_TEST_CAPTCHA),
                'string',
                'max:512',
            ],
            'payload.pass_token' => [
                Rule::requiredIf($type === self::TYPE_TEST_CAPTCHA),
                'string',
                'max:512',
            ],
            'payload.gen_time' => [
                Rule::requiredIf($type === self::TYPE_TEST_CAPTCHA),
                'string',
                'max:32',
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
