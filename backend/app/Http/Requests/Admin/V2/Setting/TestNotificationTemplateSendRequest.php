<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin\V2\Setting;

use App\Http\Requests\Admin\V2\Common\AdminFormRequest;
use Illuminate\Validation\Validator;

class TestNotificationTemplateSendRequest extends AdminFormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $channel = trim((string) $this->input('channel', ''));
        $recipients = $this->collectRecipients($channel);

        $this->merge([
            'channel' => $channel,
            'code' => trim((string) $this->input('code', '')),
            'recipients' => $recipients,
        ]);
    }

    public function rules(): array
    {
        return [
            'channel' => ['required', 'string', 'in:email,sms'],
            'code' => ['required', 'string', 'max:64'],
            'recipient' => ['nullable', 'string'],
            'recipients' => ['required', 'array', 'min:1', 'max:1'],
            'recipients.*' => ['required', 'string', 'max:255'],
            'page' => ['prohibited'],
            'page_size' => ['prohibited'],
            'pageSize' => ['prohibited'],
            'per_page' => ['prohibited'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $channel = trim((string) $this->input('channel', ''));
            if (! in_array($channel, ['email', 'sms'], true)) {
                return;
            }

            foreach ($this->recipients() as $index => $recipient) {
                if ($channel === 'email' && filter_var($recipient, FILTER_VALIDATE_EMAIL) === false) {
                    $validator->errors()->add("recipients.{$index}", '请输入正确的邮箱地址');
                }

                if ($channel === 'sms' && preg_match('/^\+?\d{6,20}$/', $recipient) !== 1) {
                    $validator->errors()->add("recipients.{$index}", '请输入正确的手机号');
                }
            }
        });
    }

    public function channel(): string
    {
        return trim((string) $this->validated('channel', ''));
    }

    public function code(): string
    {
        return trim((string) $this->validated('code', ''));
    }

    /**
     * @return list<string>
     */
    public function recipients(): array
    {
        return array_values(array_filter(
            array_map('strval', (array) $this->input('recipients', [])),
            static fn (string $recipient): bool => trim($recipient) !== ''
        ));
    }

    /**
     * @return list<string>
     */
    private function collectRecipients(string $channel): array
    {
        $items = [];
        $rawRecipients = $this->input('recipients', []);

        if (is_array($rawRecipients)) {
            $items = array_merge($items, $rawRecipients);
        } elseif (is_string($rawRecipients)) {
            $items = array_merge($items, $this->splitRecipients($rawRecipients));
        }

        $recipient = $this->input('recipient');
        if (is_string($recipient)) {
            $items = array_merge($items, $this->splitRecipients($recipient));
        }

        $normalized = [];
        foreach ($items as $item) {
            if (is_array($item) || is_object($item)) {
                continue;
            }

            $value = $this->normalizeRecipient((string) $item, $channel);
            if ($value === '') {
                continue;
            }

            $normalized[$value] = $value;
        }

        return array_values($normalized);
    }

    /**
     * @return list<string>
     */
    private function splitRecipients(string $value): array
    {
        return array_values(array_filter(
            preg_split('/[\r\n,;，；]+/u', $value) ?: [],
            static fn (string $item): bool => trim($item) !== ''
        ));
    }

    private function normalizeRecipient(string $recipient, string $channel): string
    {
        $recipient = trim($recipient);

        if ($channel === 'sms') {
            return preg_replace('/[\s\-]+/u', '', $recipient) ?? $recipient;
        }

        return $recipient;
    }
}
