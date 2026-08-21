<?php

namespace App\Http\Requests\Admin\V2\Supplier;

use App\Http\Requests\Admin\V2\Common\AdminFormRequest;
use App\Models\Supplier;
use App\Services\Integrations\Plugins\PluginBindingResolver;
use App\Services\Upstream\ProviderKey;
use App\Services\Upstream\ProviderRegistry;
use App\Services\Upstream\ProviderResolver;
use Closure;
use Illuminate\Validation\Rule;

class UpsertSupplierRequest extends AdminFormRequest
{
    protected function prepareForValidation(): void
    {
        $upstreamBinding = is_array($this->input('upstream_binding')) ? $this->input('upstream_binding') : [];
        if ($upstreamBinding !== []) {
            $this->merge([
                'provider_key' => $upstreamBinding['provider_key'] ?? $this->input('provider_key', ''),
                'api_url' => $upstreamBinding['base_url'] ?? $this->input('api_url', ''),
                'api_username' => $upstreamBinding['account_name'] ?? $this->input('api_username', ''),
            ]);
        }

        $normalizedProviderKey = app(ProviderResolver::class)->normalizeKey(
            (string) $this->input('provider_key', '')
        ) ?? '';

        $this->merge([
            'provider_key' => $normalizedProviderKey,
        ]);
    }

    public function rules(): array
    {
        $supplier = $this->supplier();
        $existingBinding = $this->existingBindingProjection();
        $hasExistingApiUrl = trim((string) ($existingBinding['base_url'] ?? '')) !== '';
        $hasExistingApiKey = (bool) ($existingBinding['has_api_key'] ?? false);
        $fields = $this->providerFormFields();
        $usesApiUrl = $this->hasProviderField($fields, 'api_url');
        $usesApiUsername = $this->hasProviderField($fields, 'api_username');
        $usesApiKey = $this->hasProviderField($fields, 'api_key');

        $rules = [
            'name' => ['required', 'string', 'max:120'],
            'provider_key' => ['required', Rule::in(app(ProviderRegistry::class)->keys())],
            'api_url' => $usesApiUrl && ! $hasExistingApiUrl && $this->isProviderFieldRequired($fields, 'api_url')
                ? ['required', 'url:http,https', 'max:255', fn (string $attribute, mixed $value, Closure $fail) => $this->validateApiUrl($value, $fail)]
                : ['nullable', 'url:http,https', 'max:255', fn (string $attribute, mixed $value, Closure $fail) => $this->validateApiUrl($value, $fail)],
            'api_username' => $usesApiUsername && $this->isProviderFieldRequired($fields, 'api_username')
                ? ['required', 'string', 'max:100']
                : ['nullable', 'string', 'max:100'],
            'api_key' => $usesApiKey && ! $hasExistingApiKey && $this->isProviderFieldRequired($fields, 'api_key')
                ? ['required', 'string', 'max:255']
                : ['nullable', 'string', 'max:255'],
            'upstream_binding' => ['nullable', 'array'],
            'upstream_binding.provider_key' => ['nullable', Rule::in(app(ProviderRegistry::class)->keys())],
            'upstream_binding.base_url' => ['nullable', 'url:http,https', 'max:255', fn (string $attribute, mixed $value, Closure $fail) => $this->validateApiUrl($value, $fail)],
            'upstream_binding.account_name' => ['nullable', 'string', 'max:100'],
            'provider_config' => ['nullable', 'array'],
            'contact_name' => ['nullable', 'string', 'max:60'],
            'contact_phone' => ['nullable', 'string', 'max:30'],
            'contact_email' => ['nullable', 'email', 'max:100'],
            'website' => ['nullable', 'string', 'max:255'],
            'status' => ['nullable', 'in:0,1'],
            'ticket_delivery_enabled' => [
                'sometimes',
                'boolean',
                Rule::when(
                    (string) $this->input('provider_key', '') !== ProviderKey::ZJMF_FINANCE_API,
                    ['declined']
                ),
            ],
            'sort_order' => ['nullable', 'integer', 'min:0', 'max:999999'],
            'notes' => ['nullable', 'string', 'max:4000'],
        ];

        foreach ($this->providerConfigFields($fields) as $field) {
            $key = $this->fieldKey($field);
            $rules["provider_config.{$key}"] = $this->rulesForProviderConfigField($field, $supplier);
        }

        return array_merge($rules, $this->allPaginationRules());
    }

    public function payload(): array
    {
        $supplier = $this->supplier();
        $validated = $this->validated();
        $existingBinding = $this->existingBindingProjection();

        $validated['status'] = (int) ($validated['status'] ?? $this->input('status', 1));
        $validated['sort_order'] = (int) ($validated['sort_order'] ?? $this->input('sort_order', 0));
        $validated['provider_key'] = (string) ($validated['provider_key'] ?? '');
        $validated['api_url'] = $this->normalizeApiUrl((string) ($validated['api_url'] ?? ''));
        $validated['api_username'] = trim((string) ($validated['api_username'] ?? ''));
        $validated['api_key'] = trim((string) ($validated['api_key'] ?? ''));
        $validated['provider_config'] = $this->normalizeProviderConfig(
            (array) ($validated['provider_config'] ?? []),
            is_array($existingBinding['provider_config'] ?? null) ? (array) $existingBinding['provider_config'] : []
        );
        $validated['ticket_delivery_enabled'] = (string) ($validated['provider_key'] ?? '') === ProviderKey::ZJMF_FINANCE_API
            && (array_key_exists('ticket_delivery_enabled', $validated)
                ? (bool) $validated['ticket_delivery_enabled']
                : (bool) ($existingBinding['ticket_delivery_enabled'] ?? false));

        if ($supplier !== null) {
            if ($validated['api_url'] === '') {
                $validated['api_url'] = trim((string) ($existingBinding['base_url'] ?? ''));
            }

            if ($validated['api_username'] === '') {
                $validated['api_username'] = trim((string) ($existingBinding['account_name'] ?? ''));
            }

            if ($validated['api_key'] === '') {
                $validated['api_key'] = trim((string) ($existingBinding['api_key'] ?? ''));
            }
        }

        $validated['contact_name'] = null;
        $validated['contact_phone'] = null;
        $validated['contact_email'] = null;
        $validated['website'] = null;
        $validated['notes'] = trim((string) ($validated['notes'] ?? '')) ?: null;
        $validated['provider_key'] = app(ProviderResolver::class)->normalizeKey(
            (string) $validated['provider_key']
        ) ?? '';

        return $validated;
    }

    public function supplierPayload(): array
    {
        $payload = $this->payload();

        return [
            'name' => $payload['name'],
            'code' => $payload['code'] ?? null,
            'contact_name' => $payload['contact_name'],
            'contact_phone' => $payload['contact_phone'],
            'contact_email' => $payload['contact_email'],
            'website' => $payload['website'],
            'status' => $payload['status'],
            'sort_order' => $payload['sort_order'],
            'notes' => $payload['notes'],
        ];
    }

    public function upstreamBindingPayload(): array
    {
        $payload = $this->payload();

        return [
            'provider_key' => $payload['provider_key'],
            'base_url' => $payload['api_url'],
            'account_name' => $payload['api_username'],
            'api_key' => $payload['api_key'],
            'provider_config' => $payload['provider_config'],
            'status' => $payload['status'],
            'priority' => $payload['sort_order'],
            'ticket_delivery_enabled' => (bool) ($payload['ticket_delivery_enabled'] ?? false),
        ];
    }

    private function supplier(): ?Supplier
    {
        $supplier = $this->route('supplier');

        return $supplier instanceof Supplier ? $supplier : null;
    }

    private function validateApiUrl(mixed $value, Closure $fail): void
    {
        $url = trim((string) $value);
        if ($url === '') {
            return;
        }

        $parsed = parse_url($url);
        if (! is_array($parsed)) {
            $fail('上游接口地址格式不正确');

            return;
        }

        $scheme = strtolower((string) ($parsed['scheme'] ?? ''));
        $host = strtolower(trim((string) ($parsed['host'] ?? '')));

        if ($scheme === '' || $host === '') {
            $fail('上游接口地址格式不正确');

            return;
        }

        if ($scheme !== 'https' && ! app()->environment('local')) {
            $fail('上游接口地址必须使用 HTTPS');

            return;
        }

        if (isset($parsed['user']) || isset($parsed['pass'])) {
            $fail('上游接口地址禁止包含账号信息');

            return;
        }

        if ($host === 'localhost' || str_ends_with($host, '.localhost')) {
            $fail('上游接口地址禁止使用本机地址');

            return;
        }

        $allowedHosts = array_values(array_filter(array_map(
            static fn (string $item): string => strtolower(trim($item)),
            explode(',', (string) config('idc.hosting_panel_api.allowed_hosts', ''))
        )));

        if ($allowedHosts !== []) {
            $matched = collect($allowedHosts)->contains(function (string $allowedHost) use ($host): bool {
                return $host === $allowedHost || str_ends_with($host, '.'.$allowedHost);
            });

            if (! $matched) {
                $fail('上游接口域名不在允许范围内');

                return;
            }
        }

        if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
            $publicIp = filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE);
            if ($publicIp === false) {
                $fail('上游接口地址禁止使用内网或保留地址');
            }
        }
    }

    private function normalizeApiUrl(string $url): string
    {
        $url = trim($url);

        return $url !== '' ? rtrim($url, '/') : '';
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function providerFormFields(): array
    {
        $providerKey = (string) $this->input('provider_key', '');
        $descriptor = app(ProviderRegistry::class)->descriptor($providerKey);
        if ($descriptor === null) {
            return [];
        }

        $form = (array) $descriptor->supplierForm;

        return array_values(array_filter(
            (array) ($form['fields'] ?? []),
            fn (mixed $field): bool => is_array($field) && $this->fieldKey($field) !== ''
        ));
    }

    /**
     * @param  array<int, array<string, mixed>>  $fields
     */
    private function hasProviderField(array $fields, string $key): bool
    {
        return collect($fields)->contains(fn (array $field): bool => $this->fieldKey($field) === $key);
    }

    /**
     * @param  array<int, array<string, mixed>>  $fields
     */
    private function isProviderFieldRequired(array $fields, string $key): bool
    {
        $field = collect($fields)->first(fn (array $item): bool => $this->fieldKey($item) === $key);

        return is_array($field) && (bool) ($field['required'] ?? false);
    }

    /**
     * @param  array<int, array<string, mixed>>  $fields
     * @return array<int, array<string, mixed>>
     */
    private function providerConfigFields(array $fields): array
    {
        return array_values(array_filter(
            $fields,
            fn (array $field): bool => ! in_array($this->fieldKey($field), ['api_url', 'api_username', 'api_key'], true)
        ));
    }

    /**
     * @return array<int, mixed>
     */
    private function rulesForProviderConfigField(array $field, ?Supplier $supplier): array
    {
        $key = $this->fieldKey($field);
        $required = (bool) ($field['required'] ?? false);
        $secret = (bool) ($field['secret'] ?? false);
        $existingBinding = $this->existingBindingProjection();
        $existingConfig = is_array($existingBinding['provider_config'] ?? null) ? (array) $existingBinding['provider_config'] : [];
        $existingValue = $existingConfig[$key] ?? null;
        $rules = $required && ! ($secret && $this->filledValue($existingValue))
            ? ['required']
            : ['nullable'];

        return array_merge($rules, match ((string) ($field['type'] ?? 'text')) {
            'switch', 'boolean' => ['boolean'],
            'number' => ['numeric'],
            'url' => ['url:http,https', 'max:500'],
            default => ['string', 'max:1000'],
        });
    }

    /**
     * @param  array<string, mixed>  $input
     * @param  array<string, mixed>  $existing
     * @return array<string, mixed>
     */
    private function normalizeProviderConfig(array $input, array $existing): array
    {
        $config = [];

        foreach ($this->providerConfigFields($this->providerFormFields()) as $field) {
            $key = $this->fieldKey($field);
            $value = $input[$key] ?? null;
            $secret = (bool) ($field['secret'] ?? false);

            if ($secret && ! $this->filledValue($value) && $this->filledValue($existing[$key] ?? null)) {
                $config[$key] = $existing[$key];

                continue;
            }

            $config[$key] = match ((string) ($field['type'] ?? 'text')) {
                'switch', 'boolean' => filter_var($value, FILTER_VALIDATE_BOOLEAN),
                'number' => is_numeric($value) ? (float) $value : null,
                default => trim((string) ($value ?? '')),
            };
        }

        return array_filter($config, fn (mixed $value): bool => $this->filledValue($value));
    }

    private function fieldKey(array $field): string
    {
        return trim((string) ($field['key'] ?? ''));
    }

    /**
     * @return array<string, mixed>
     */
    private function existingBindingProjection(): array
    {
        $supplier = $this->supplier();
        if (! $supplier instanceof Supplier) {
            return [];
        }

        return app(PluginBindingResolver::class)->supplierBindingProjection($supplier, includeSecrets: true);
    }

    private function filledValue(mixed $value): bool
    {
        if (is_bool($value) || is_int($value) || is_float($value)) {
            return true;
        }

        return trim((string) ($value ?? '')) !== '';
    }
}
