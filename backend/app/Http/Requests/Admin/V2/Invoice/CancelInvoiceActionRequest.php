<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin\V2\Invoice;

use App\Http\Requests\Admin\V2\Common\AdminFormRequest;

class CancelInvoiceActionRequest extends AdminFormRequest
{
    public function rules(): array
    {
        return [
            'page' => ['prohibited'],
            'page_size' => ['prohibited'],
            'pageSize' => ['prohibited'],
            'per_page' => ['prohibited'],
        ];
    }
}
