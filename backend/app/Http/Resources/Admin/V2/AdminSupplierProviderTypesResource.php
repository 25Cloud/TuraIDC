<?php

declare(strict_types=1);

namespace App\Http\Resources\Admin\V2;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AdminSupplierProviderTypesResource extends JsonResource
{
    /**
     * @return array{list: list<array<string, mixed>>}
     */
    public function toArray(Request $request): array
    {
        $payload = is_array($this->resource) ? $this->resource : [];

        return [
            'list' => $this->projectOptions((array) ($payload['list'] ?? [])),
        ];
    }

    /**
     * @param  array<int, mixed>  $items
     * @return list<array<string, mixed>>
     */
    private function projectOptions(array $items): array
    {
        return collect($items)
            ->filter(static fn (mixed $item): bool => is_array($item))
            ->map(fn (array $item): array => [
                'value' => (string) ($item['value'] ?? ''),
                'label' => (string) ($item['label'] ?? ''),
                'supplier_form' => $this->projectSupplierForm((array) ($item['supplier_form'] ?? [])),
            ])
            ->values()
            ->all();
    }

    /**
     * @param  array<string, mixed>  $form
     * @return array{fields: list<array<string, mixed>>, help: string}
     */
    private function projectSupplierForm(array $form): array
    {
        return [
            'fields' => $this->projectFields((array) ($form['fields'] ?? [])),
            'help' => (string) ($form['help'] ?? ''),
        ];
    }

    /**
     * @param  array<int, mixed>  $fields
     * @return list<array<string, mixed>>
     */
    private function projectFields(array $fields): array
    {
        return collect($fields)
            ->filter(static fn (mixed $field): bool => is_array($field))
            ->map(static function (array $field): array {
                return array_filter([
                    'key' => (string) ($field['key'] ?? ''),
                    'label' => (string) ($field['label'] ?? ''),
                    'type' => (string) ($field['type'] ?? 'text'),
                    'required' => (bool) ($field['required'] ?? false),
                    'placeholder' => (string) ($field['placeholder'] ?? ''),
                    'description' => (string) ($field['description'] ?? ''),
                    'options' => is_array($field['options'] ?? null) ? array_values($field['options']) : null,
                ], static fn (mixed $value): bool => $value !== null);
            })
            ->values()
            ->all();
    }
}
