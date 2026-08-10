<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin\V2\Concerns;

trait RejectsLegacyPagination
{
    /**
     * @return array<string, list<string>>
     */
    protected function legacyPaginationRules(): array
    {
        return [
            'per_page' => ['prohibited'],
            'pageSize' => ['prohibited'],
        ];
    }

    /**
     * @return array<string, list<string>>
     */
    protected function allPaginationRules(): array
    {
        return [
            'page' => ['prohibited'],
            'page_size' => ['prohibited'],
            ...$this->legacyPaginationRules(),
        ];
    }
}
