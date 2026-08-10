<?php

declare(strict_types=1);

namespace App\Models\Concerns;

trait NormalizesTraceId
{
    public function getTraceIdAttribute(mixed $value): ?string
    {
        $normalized = trim((string) $value);

        return $normalized !== '' ? $normalized : null;
    }

    public function setTraceIdAttribute(mixed $value): void
    {
        $normalized = trim((string) $value);
        $this->attributes['trace_id'] = $normalized !== '' ? $normalized : null;
    }
}
