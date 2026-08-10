<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MediaFile extends Model
{
    protected $fillable = [
        'filename',
        'path',
        'url',
        'mime_type',
        'size',
        'width',
        'height',
        'group',
        'uploaded_by',
    ];

    protected function casts(): array
    {
        return [
            'size' => 'integer',
            'width' => 'integer',
            'height' => 'integer',
            'uploaded_by' => 'integer',
        ];
    }

    public function uploader(): BelongsTo
    {
        return $this->belongsTo(AdminUser::class, 'uploaded_by');
    }
}
