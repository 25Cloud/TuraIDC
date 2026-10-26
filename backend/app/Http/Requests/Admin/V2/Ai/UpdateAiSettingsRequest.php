<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin\V2\Ai;

use App\Http\Requests\Admin\V2\Common\AdminFormRequest;

class UpdateAiSettingsRequest extends AdminFormRequest
{
    /** 0/1 开关类字段：前端可能传布尔值，这里统一归一成整数 */
    private const BOOLEAN_KEYS = [
        'enabled',
        'console_enabled',
        'site_enabled',
        'admin_enabled',
        'allow_balance_pay',
        'redact_pii',
    ];

    /** 允许写入的字段白名单，其余（如 tools、protocols 等只读回显）直接忽略 */
    private const ALLOWED_KEYS = [
        'enabled',
        'protocol',
        'base_url',
        'api_key',
        'model',
        'temperature',
        'max_tokens',
        'console_enabled',
        'site_enabled',
        'admin_enabled',
        'allow_balance_pay',
        'user_daily_messages',
        'user_daily_tokens',
        'global_daily_tokens',
        'global_daily_cost_cents',
        'cost_per_1k_tokens_cents',
        'max_tool_rounds',
        'request_timeout_seconds',
        'history_message_limit',
        'redact_pii',
        'retention_days',
        'default_permission_mode',
        'max_permission_mode',
        'enabled_tools',
    ];

    /**
     * 归一化：布尔 → 0/1，'true'/'false' → 0/1；api_key 传对象时只取其中的字符串值；
     * 只读回显字段直接丢弃，避免前端把 GET 结果整体回传时被 422 拦下。
     */
    protected function prepareForValidation(): void
    {
        $input = $this->all();
        $normalized = [];

        foreach (self::ALLOWED_KEYS as $key) {
            if (! array_key_exists($key, $input)) {
                continue;
            }
            $value = $input[$key];

            if ($key === 'api_key') {
                if (is_array($value)) {
                    $value = (string) ($value['value'] ?? '');
                }
                $normalized[$key] = (string) $value;
                continue;
            }

            if (in_array($key, self::BOOLEAN_KEYS, true)) {
                if (is_bool($value)) {
                    $normalized[$key] = $value ? 1 : 0;
                } elseif (is_string($value) && in_array(strtolower($value), ['true', 'false'], true)) {
                    $normalized[$key] = strtolower($value) === 'true' ? 1 : 0;
                } else {
                    $normalized[$key] = $value;
                }
                continue;
            }

            if ($key === 'enabled_tools') {
                if ($value === null || $value === '') {
                    $normalized[$key] = [];
                } elseif (is_array($value)) {
                    $normalized[$key] = array_values(array_filter(array_map('strval', $value), static fn (string $item): bool => $item !== ''));
                }
                continue;
            }

            $normalized[$key] = $value;
        }

        $this->replace($normalized);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'enabled' => ['nullable', 'integer', 'in:0,1'],
            'protocol' => ['nullable', 'string', 'in:chat_completions,responses,anthropic_messages'],
            'base_url' => ['nullable', 'string', 'max:255'],
            'api_key' => ['nullable', 'string', 'max:255'],
            'model' => ['nullable', 'string', 'max:120'],
            'temperature' => ['nullable', 'numeric', 'min:0', 'max:2'],
            'max_tokens' => ['nullable', 'integer', 'min:128', 'max:8192'],
            'console_enabled' => ['nullable', 'integer', 'in:0,1'],
            'site_enabled' => ['nullable', 'integer', 'in:0,1'],
            'admin_enabled' => ['nullable', 'integer', 'in:0,1'],
            'allow_balance_pay' => ['nullable', 'integer', 'in:0,1'],
            'user_daily_messages' => ['nullable', 'integer', 'min:0', 'max:10000'],
            'user_daily_tokens' => ['nullable', 'integer', 'min:0', 'max:100000000'],
            'global_daily_tokens' => ['nullable', 'integer', 'min:0', 'max:100000000'],
            'global_daily_cost_cents' => ['nullable', 'integer', 'min:0', 'max:100000000'],
            'cost_per_1k_tokens_cents' => ['nullable', 'integer', 'min:0', 'max:100000'],
            'max_tool_rounds' => ['nullable', 'integer', 'min:1', 'max:12'],
            'request_timeout_seconds' => ['nullable', 'integer', 'min:15', 'max:180'],
            'history_message_limit' => ['nullable', 'integer', 'min:2', 'max:40'],
            'redact_pii' => ['nullable', 'integer', 'in:0,1'],
            'retention_days' => ['nullable', 'integer', 'min:1', 'max:365'],
            'default_permission_mode' => ['nullable', 'string', 'in:read_only,confirm,auto_approve'],
            'max_permission_mode' => ['nullable', 'string', 'in:read_only,confirm,auto_approve'],
            'enabled_tools' => ['nullable', 'array', 'max:40'],
            'enabled_tools.*' => ['string', 'max:64'],
        ];
    }
}
