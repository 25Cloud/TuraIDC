<?php

declare(strict_types=1);

namespace App\Models\Concerns;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

trait EnsuresTraceId
{
    public static function bootEnsuresTraceId(): void
    {
        static::creating(static function (Model $model): void {
            if (trim((string) $model->getAttribute('trace_id')) !== '') {
                return;
            }

            $model->setAttribute('trace_id', 'auto:'.Str::uuid()->toString());
        });
    }
}
