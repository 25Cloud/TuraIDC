<?php

declare(strict_types=1);

namespace App\Http\Resources\Site\V2;

use App\Support\UploadUrl;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class SiteHomeHeroResource extends JsonResource
{
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
     * @return array{slides: list<array<string, string>>, features: list<array<string, string>>}
     */
    public function toArray(Request $request): array
    {
        $payload = is_array($this->resource) ? $this->resource : [];

        return [
            'slides' => $this->projectList((array) ($payload['slides'] ?? []), self::SLIDE_FIELDS),
            'features' => $this->projectList((array) ($payload['features'] ?? []), self::FEATURE_FIELDS),
        ];
    }

    /**
     * @param  array<int, mixed>  $items
     * @param  list<string>  $fields
     * @return list<array<string, string>>
     */
    private function projectList(array $items, array $fields): array
    {
        return collect($items)
            ->filter(static fn (mixed $item): bool => is_array($item))
            ->map(static function (array $item) use ($fields): array {
                $projected = collect($fields)
                    ->mapWithKeys(static fn (string $field): array => [$field => (string) ($item[$field] ?? '')])
                    ->all();

                if (array_key_exists('video', $projected)) {
                    $projected['video'] = UploadUrl::resolve($projected['video']) ?? '';
                }

                return $projected;
            })
            ->values()
            ->all();
    }
}
