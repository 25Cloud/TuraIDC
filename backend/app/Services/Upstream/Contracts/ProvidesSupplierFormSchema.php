<?php

declare(strict_types=1);

namespace App\Services\Upstream\Contracts;

interface ProvidesSupplierFormSchema
{
    /**
     * @return array{fields?: array<int, array<string, mixed>>, help?: string}
     */
    public function supplierFormSchema(): array;
}
