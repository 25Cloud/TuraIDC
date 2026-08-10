<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class NoticeRead extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'user_id',
        'article_id',
        'read_at',
    ];

    protected function casts(): array
    {
        return [
            'user_id' => 'integer',
            'article_id' => 'integer',
            'read_at' => 'datetime',
            'created_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function article(): BelongsTo
    {
        return $this->belongsTo(ContentArticle::class, 'article_id');
    }
}
