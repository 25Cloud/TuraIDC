<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin\V2\UserService;

use App\Http\Requests\Admin\V2\Common\AdminFormRequest;

class ListServiceOperationLogsRequest extends AdminFormRequest
{
    public function rules(): array
    {
        return array_merge($this->paginationRules(50), [
            'keyword' => ['nullable', 'string', 'max:100'],
            'category' => ['nullable', 'in:power,password,reinstall,renew,nat_forwarding,security_group,security_rule,service'],
        ], $this->legacyPaginationRules());
    }

    public function filters(): array
    {
        return $this->safe()->only([
            'keyword',
            'category',
        ]);
    }
}