<?php

declare(strict_types=1);

namespace App\Http\Requests\Site\V2\Product;

use App\Http\Requests\Site\QuoteProductRequest as LegacyQuoteProductRequest;

class QuoteProductRequest extends LegacyQuoteProductRequest
{
    public function rules(): array
    {
        return array_merge(parent::rules(), [
            'product' => ['required', 'integer', 'min:1'],
            'page' => ['prohibited'],
            'page_size' => ['prohibited'],
            'pageSize' => ['prohibited'],
            'per_page' => ['prohibited'],
        ]);
    }

    public function validationData(): array
    {
        return array_merge(parent::validationData(), [
            'product' => $this->route('product'),
        ]);
    }
}
