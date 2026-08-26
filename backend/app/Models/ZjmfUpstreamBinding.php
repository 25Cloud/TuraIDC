<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * 下游（魔方财务）绑定：上游开通/状态变更后回推下游回调地址。
 */
class ZjmfUpstreamBinding extends Model
{
    protected $fillable = [
        'user_id', 'invoice_id', 'service_id',
        'downstream_url', 'downstream_token', 'downstream_id',
        'domain', 'payload',
    ];

    protected function casts(): array
    {
        return [
            'downstream_id' => 'integer',
            'payload' => 'array',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
