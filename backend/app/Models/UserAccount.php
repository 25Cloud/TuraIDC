<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class UserAccount extends Model
{
    protected $primaryKey = 'user_id';

    public $incrementing = false;

    protected $keyType = 'int';

    protected $fillable = [
        'user_id',
        'cash_balance',
        'credit_limit',
        'referral_frozen_balance',
        'referral_available_balance',
        'referral_pending_withdrawal_balance',
        'referral_withdrawn_balance',
        'version',
    ];

    protected function casts(): array
    {
        return [
            'cash_balance' => 'decimal:2',
            'credit_limit' => 'decimal:2',
            'referral_frozen_balance' => 'decimal:2',
            'referral_available_balance' => 'decimal:2',
            'referral_pending_withdrawal_balance' => 'decimal:2',
            'referral_withdrawn_balance' => 'decimal:2',
            'version' => 'integer',
        ];
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
