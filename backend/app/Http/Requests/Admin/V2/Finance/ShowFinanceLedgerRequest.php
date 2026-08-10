<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin\V2\Finance;

use App\Http\Requests\Admin\V2\Common\AdminFormRequest;

class ShowFinanceLedgerRequest extends AdminFormRequest
{
    public function rules(): array
    {
        return [
            'id' => ['required', 'integer', 'min:1'],
            'page' => ['prohibited'],
            'page_size' => ['prohibited'],
            'pageSize' => ['prohibited'],
            'per_page' => ['prohibited'],
        ];
    }

    public function validationData(): array
    {
        return array_merge(parent::validationData(), [
            'id' => $this->route('id'),
        ]);
    }
}
