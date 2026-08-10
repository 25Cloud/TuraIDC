<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ReferralRelation extends Model
{
    protected $table = 'referral_relations';

    protected $fillable = [
        'referrer_user_id',
        'referred_user_id',
        'referral_code_snapshot',
        'bound_at',
    ];

    protected function casts(): array
    {
        return [
            'referrer_user_id' => 'integer',
            'referred_user_id' => 'integer',
            'bound_at' => 'datetime',
        ];
    }

    public function referrer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'referrer_user_id');
    }

    public function referredUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'referred_user_id');
    }
}
