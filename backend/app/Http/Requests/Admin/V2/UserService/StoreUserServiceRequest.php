<?php

namespace App\Http\Requests\Admin\V2\UserService;

use App\Http\Requests\Admin\V2\Common\AdminFormRequest;

class StoreUserServiceRequest extends AdminFormRequest
{
    public function rules(): array
    {
        return [
            'product_id' => ['required', 'integer', 'exists:products,id'],
            'billing_cycle' => ['required', 'string', 'max:30'],
            'source_type' => ['required', 'in:manual,upstream'],
            'name' => ['nullable', 'string', 'max:200'],
            'domain' => ['nullable', 'string', 'max:200'],
            'amount' => ['nullable', 'numeric', 'min:0', 'max:99999999.99'],
            'status' => ['required', 'in:0,1,2,3,4'],
            'expires_at' => ['nullable', 'date'],
            'auto_renew' => ['required', 'in:0,1'],
            'dedicated_ip' => ['nullable', 'string', 'max:100'],
            'internal_ip' => ['nullable', 'string', 'max:100'],
            'port' => ['nullable', 'integer', 'between:1,65535'],
            'username' => ['nullable', 'string', 'max:100'],
            'password' => ['nullable', 'string', 'max:200'],
            'upstream_host_id' => ['nullable', 'required_if:source_type,upstream', 'integer', 'min:1'],
            'upstream_status' => ['nullable', 'string', 'max:50'],
            'os' => ['nullable', 'string', 'max:100'],
            'remark' => ['nullable', 'string', 'max:200'],
            ...$this->allPaginationRules(),
        ];
    }

    public function payload(): array
    {
        return $this->safe()->only([
            'product_id',
            'billing_cycle',
            'source_type',
            'name',
            'domain',
            'amount',
            'status',
            'expires_at',
            'auto_renew',
            'dedicated_ip',
            'internal_ip',
            'port',
            'username',
            'password',
            'upstream_host_id',
            'upstream_status',
            'os',
            'remark',
        ]);
    }
}
