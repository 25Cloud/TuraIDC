<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class OperationLog extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'user_id',
        'user_type',
        'action',
        'module',
        'subject_id',
        'context',
        'ip_address',
        'target_id',
        'detail',
    ];

    protected function casts(): array
    {
        return [
            'context' => 'array',
            'created_at' => 'datetime',
        ];
    }

    public function getTargetIdAttribute(): ?int
    {
        $value = $this->attributes['subject_id'] ?? null;

        return $value === null ? null : (int) $value;
    }

    public function setTargetIdAttribute($value): void
    {
        $this->attributes['subject_id'] = $value;
    }

    public function getDetailAttribute(): array
    {
        $value = $this->attributes['context'] ?? null;

        if (is_array($value)) {
            return $value;
        }

        if (is_string($value) && $value !== '') {
            $decoded = json_decode($value, true);

            return is_array($decoded) ? $decoded : [];
        }

        return [];
    }

    public function setDetailAttribute($value): void
    {
        $this->setAttribute('context', $value);
    }
}
