<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin\V2\Database;

use App\Http\Requests\Admin\V2\Common\AdminFormRequest;

class OptimizeDatabaseTablesRequest extends AdminFormRequest
{
    public function rules(): array
    {
        return [
            'tables' => ['nullable', 'array', 'max:500'],
            'tables.*' => ['required', 'string', 'max:128'],
            'per_page' => ['prohibited'],
            'pageSize' => ['prohibited'],
        ];
    }

    /**
     * @return list<string>
     */
    public function tables(): array
    {
        $tables = $this->validated()['tables'] ?? [];

        return array_values(array_filter(array_map(
            static fn (mixed $table): string => is_string($table) ? trim($table) : '',
            is_array($tables) ? $tables : []
        ), static fn (string $table): bool => $table !== ''));
    }
}
