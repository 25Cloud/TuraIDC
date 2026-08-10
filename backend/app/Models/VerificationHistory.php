<?php

namespace App\Models;

use App\Casts\LegacyEncrypted;
use Illuminate\Database\Eloquent\Model;

class VerificationHistory extends Model
{
    protected $fillable = [
        'user_id',
        'real_name',
        'id_card',
        'verification_status',
        'verification_message',
        'verification_certify_id',
        'verification_biz_code',
        'verification_type',
        'submitted_at',
        'completed_at',
    ];

    protected function casts(): array
    {
        return [
            'id_card' => LegacyEncrypted::class,
            'submitted_at' => 'datetime',
            'completed_at' => 'datetime',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
        ];
    }
}
