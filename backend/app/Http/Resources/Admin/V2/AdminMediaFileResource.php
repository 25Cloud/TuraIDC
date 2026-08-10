<?php

declare(strict_types=1);

namespace App\Http\Resources\Admin\V2;

use App\Models\MediaFile;
use App\Support\UploadUrl;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AdminMediaFileResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $path = (string) $this->field('path', '');
        $mimeType = (string) $this->field('mime_type', '');

        return [
            'id' => $this->normalizeId($this->field('id')),
            'filename' => (string) $this->field('filename', ''),
            'path' => $path,
            'url' => UploadUrl::resolve($path) ?: $this->field('url'),
            'mime_type' => $mimeType,
            'size' => (int) $this->field('size', 0),
            'width' => $this->nullableInt($this->field('width')),
            'height' => $this->nullableInt($this->field('height')),
            'group' => (string) $this->field('group', ''),
            'type' => str_starts_with($mimeType, 'video/') ? 'video' : 'image',
            'created_at' => $this->createdAt(),
        ];
    }

    private function field(string $key, mixed $default = null): mixed
    {
        if ($this->resource instanceof MediaFile) {
            return $this->resource->{$key} ?? $default;
        }

        if (is_array($this->resource)) {
            return $this->resource[$key] ?? $default;
        }

        return $default;
    }

    private function normalizeId(mixed $value): int|string|null
    {
        if ($value === null || $value === '') {
            return null;
        }

        return is_numeric($value) ? (int) $value : (string) $value;
    }

    private function nullableInt(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        return (int) $value;
    }

    private function createdAt(): ?string
    {
        if ($this->resource instanceof MediaFile) {
            return $this->resource->created_at?->format('Y-m-d H:i:s');
        }

        $createdAt = $this->field('created_at');

        return $createdAt === null ? null : (string) $createdAt;
    }
}
