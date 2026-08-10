<?php

declare(strict_types=1);

namespace App\Http\Resources\Product;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ProductCategoryResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return is_array($this->resource) ? $this->resource : [];
    }
}
