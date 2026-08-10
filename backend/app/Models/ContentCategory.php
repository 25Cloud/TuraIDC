<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class ContentCategory extends Model
{
    public const STATUS_DISABLED = 0;

    public const STATUS_ENABLED = 1;

    protected $table = 'content_categories';

    protected $fillable = [
        'content_type',
        'type',
        'name',
        'slug',
        'description',
        'status',
        'sort_order',
        'created_by',
        'updated_by',
    ];

    protected function casts(): array
    {
        return [
            'status' => 'integer',
            'sort_order' => 'integer',
            'created_by' => 'integer',
            'updated_by' => 'integer',
        ];
    }

    protected static function booted(): void {}

    public function articles()
    {
        return $this->hasMany(ContentArticle::class, 'category_id');
    }

    public function creator()
    {
        return $this->belongsTo(AdminUser::class, 'created_by');
    }

    public function updater()
    {
        return $this->belongsTo(AdminUser::class, 'updated_by');
    }

    public function scopeOfType(Builder $query, string $type): Builder
    {
        return $query->where('content_type', $type);
    }

    public function scopeEnabled(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_ENABLED);
    }

    public function getTypeAttribute(): ?string
    {
        return $this->attributes['content_type'] ?? null;
    }

    public function setTypeAttribute(?string $value): void
    {
        $this->attributes['content_type'] = $value;
    }
}
