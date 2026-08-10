<?php

namespace App\Http\Requests\Admin\V2\UserService;

use App\Http\Requests\Admin\V2\Common\AdminFormRequest;

class UpdateUserServiceMetaRequest extends AdminFormRequest
{
    public function rules(): array
    {
        return [
            'amount' => ['nullable', 'numeric', 'min:0', 'max:99999999.99'],
            'supplier_id' => ['nullable', 'integer', 'min:1'],
            'upstream_product_id' => ['nullable', 'integer', 'min:1'],
            'upstream_host_id' => ['nullable', 'integer', 'min:1'],
            'service_name' => ['nullable', 'string', 'max:120'],
            'custom_hostname' => ['nullable', 'string', 'max:200'],

            'locked_pricing' => ['nullable', 'array'],
            'locked_pricing.monthly' => ['nullable', 'array'],
            'locked_pricing.monthly.enabled' => ['nullable', 'boolean'],
            'locked_pricing.monthly.manual_amount' => ['nullable', 'numeric', 'min:0', 'max:99999999.99'],
            'locked_pricing.quarterly' => ['nullable', 'array'],
            'locked_pricing.quarterly.enabled' => ['nullable', 'boolean'],
            'locked_pricing.quarterly.manual_amount' => ['nullable', 'numeric', 'min:0', 'max:99999999.99'],
            'locked_pricing.semiannually' => ['nullable', 'array'],
            'locked_pricing.semiannually.enabled' => ['nullable', 'boolean'],
            'locked_pricing.semiannually.manual_amount' => ['nullable', 'numeric', 'min:0', 'max:99999999.99'],
            'locked_pricing.annually' => ['nullable', 'array'],
            'locked_pricing.annually.enabled' => ['nullable', 'boolean'],
            'locked_pricing.annually.manual_amount' => ['nullable', 'numeric', 'min:0', 'max:99999999.99'],

            // clear_locked_pricing=true 时恢复默认购买快照定价
            'clear_locked_pricing' => ['nullable', 'boolean'],
            'clear_custom_hostname' => ['nullable', 'boolean'],
            ...$this->allPaginationRules(),
        ];
    }

    public function payload(): array
    {
        $validated = $this->validated();

        $lockedPricing = null;
        if (! empty($validated['clear_locked_pricing'])) {
            $lockedPricing = null;
        } elseif (isset($validated['locked_pricing']) && is_array($validated['locked_pricing'])) {
            $allowedCycles = ['monthly', 'quarterly', 'semiannually', 'annually'];
            $lockedPricing = [];

            foreach ($allowedCycles as $cycle) {
                $entry = $validated['locked_pricing'][$cycle] ?? null;
                if (! is_array($entry)) {
                    continue;
                }

                $lockedPricing[$cycle] = [
                    'enabled' => ! empty($entry['enabled']),
                    'manual_amount' => isset($entry['manual_amount']) && $entry['manual_amount'] !== ''
                        ? round((float) $entry['manual_amount'], 2)
                        : null,
                ];
            }
        }

        return [
            'amount' => isset($validated['amount']) && $validated['amount'] !== ''
                ? round((float) $validated['amount'], 2)
                : null,
            'supplier_id' => isset($validated['supplier_id'])
                ? (int) $validated['supplier_id']
                : null,
            'upstream_product_id' => isset($validated['upstream_product_id'])
                ? (int) $validated['upstream_product_id']
                : null,
            'upstream_host_id' => isset($validated['upstream_host_id'])
                ? (int) $validated['upstream_host_id']
                : null,
            'service_name' => array_key_exists('service_name', $validated)
                ? trim((string) ($validated['service_name'] ?? ''))
                : null,
            'custom_hostname' => trim((string) ($validated['custom_hostname'] ?? '')),
            'locked_pricing' => $lockedPricing,
            'clear_locked_pricing' => ! empty($validated['clear_locked_pricing']),
            'clear_custom_hostname' => ! empty($validated['clear_custom_hostname']),
        ];
    }
}
