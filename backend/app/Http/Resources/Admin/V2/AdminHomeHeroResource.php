<?php

declare(strict_types=1);

namespace App\Http\Resources\Admin\V2;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AdminHomeHeroResource extends JsonResource
{
    private const MAX_VIDEO_OPTIONS = 100;

    private const SLIDE_FIELDS = [
        'key',
        'rail_title',
        'title',
        'desc',
        'primary_text',
        'primary_path',
        'secondary_text',
        'secondary_path',
        'shape',
        'video',
        'ribbon',
        'ribbon_type',
    ];

    private const FEATURE_FIELDS = [
        'key',
        'kicker',
        'title',
        'desc',
        'path',
    ];

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $payload = is_array($this->resource) ? $this->resource : [];
        $defaults = is_array($payload['defaults'] ?? null) ? $payload['defaults'] : [];
        $options = is_array($payload['options'] ?? null) ? $payload['options'] : [];

        return [
            'slides' => $this->projectStringList((array) ($payload['slides'] ?? []), self::SLIDE_FIELDS),
            'features' => $this->projectStringList((array) ($payload['features'] ?? []), self::FEATURE_FIELDS),
            'defaults' => [
                'slides' => $this->projectStringList((array) ($defaults['slides'] ?? []), self::SLIDE_FIELDS),
                'features' => $this->projectStringList((array) ($defaults['features'] ?? []), self::FEATURE_FIELDS),
            ],
            'options' => [
                'shape' => $this->stringValues((array) ($options['shape'] ?? [])),
                'ribbon_type' => $this->stringValues((array) ($options['ribbon_type'] ?? [])),
                'videos' => $this->projectVideoList((array) ($options['videos'] ?? [])),
            ],
        ];
    }

    /**
     * @param  array<int, mixed>  $items
     * @param  list<string>  $fields
     * @return list<array<string, string>>
     */
    private function projectStringList(array $items, array $fields): array
    {
        return collect($items)
            ->filter(static fn (mixed $item): bool => is_array($item))
            ->map(static function (array $item) use ($fields): array {
                return collect($fields)
                    ->mapWithKeys(static fn (string $field): array => [$field => (string) ($item[$field] ?? '')])
                    ->all();
            })
            ->values()
            ->all();
    }

    /**
     * @param  array<int, mixed>  $items
     * @return list<array<string, mixed>>
     */
    private function projectVideoList(array $items): array
    {
        return collect($items)
            ->filter(static fn (mixed $item): bool => is_array($item))
            ->take(self::MAX_VIDEO_OPTIONS)
            ->map(static function (array $item): array {
                return [
                    'id' => $item['id'] ?? null,
                    'filename' => (string) ($item['filename'] ?? ''),
                    'path' => (string) ($item['path'] ?? ''),
                    'url' => (string) ($item['url'] ?? ''),
                    'mime_type' => (string) ($item['mime_type'] ?? ''),
                    'size' => (int) ($item['size'] ?? 0),
                    'width' => isset($item['width']) ? (int) $item['width'] : null,
                    'height' => isset($item['height']) ? (int) $item['height'] : null,
                    'group' => (string) ($item['group'] ?? ''),
                    'type' => (string) ($item['type'] ?? ''),
                    'created_at' => (string) ($item['created_at'] ?? ''),
                ];
            })
            ->values()
            ->all();
    }

    /**
     * @param  array<int, mixed>  $items
     * @return list<string>
     */
    private function stringValues(array $items): array
    {
        return collect($items)
            ->map(static fn (mixed $item): string => (string) $item)
            ->values()
            ->all();
    }
}
