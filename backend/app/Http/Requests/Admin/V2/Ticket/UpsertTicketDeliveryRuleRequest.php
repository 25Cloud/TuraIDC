<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin\V2\Ticket;

use App\Http\Requests\Admin\V2\Common\AdminFormRequest;
use App\Services\Ticket\TicketService;
use App\Services\Upstream\ProviderKey;
use Illuminate\Validation\Rule;

final class UpsertTicketDeliveryRuleRequest extends AdminFormRequest
{
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:120'],
            'department' => ['required', Rule::in(TicketService::DEPARTMENTS)],
            'supplier_id' => ['required', 'integer', 'exists:suppliers,id'],
            'provider_key' => ['nullable', Rule::in([ProviderKey::ZJMF_FINANCE_API])],
            'product_scope_mode' => ['required', Rule::in(['all', 'selected'])],
            'upstream_department_id' => ['required', 'string', 'max:64'],
            'enabled' => ['sometimes', 'boolean'],
            'sync_admin_replies' => ['sometimes', 'boolean'],
            'mask_keywords' => ['nullable', 'string', 'max:10000'],
            'product_ids' => ['sometimes', 'array'],
            'product_ids.*' => ['integer', 'exists:products,id'],
        ];
    }

    /** @return array<string, mixed> */
    public function payload(): array
    {
        $payload = $this->validated();
        $payload['provider_key'] = ProviderKey::ZJMF_FINANCE_API;
        $payload['product_ids'] = array_values(array_unique(array_map(
            'intval',
            (array) ($payload['product_ids'] ?? [])
        )));

        return $payload;
    }
}
