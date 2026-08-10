<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ReferralWithdrawal extends Model
{
    public const METHOD_BALANCE = 'balance';

    public const METHOD_ALIPAY = 'alipay';

    public const STATUS_PENDING = 0;

    public const STATUS_APPROVED = 1;

    public const STATUS_REJECTED = 2;

    protected $fillable = [
        'user_id',
        'amount',
        'method',
        'account_name',
        'account_no',
        'status',
        'remark',
        'operator',
        'trace_id',
        'processed_at',
    ];

    protected function casts(): array
    {
        return [
            'user_id' => 'integer',
            'amount' => 'decimal:2',
            'status' => 'integer',
            'processed_at' => 'datetime',
        ];
    }

    public function getAccountNameDisplayAttribute(): string
    {
        $accountName = trim((string) ($this->attributes['account_name'] ?? ''));

        if ($this->hasReadableText($accountName)) {
            return $accountName;
        }

        return $this->user?->display_name ?? '';
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    private function hasReadableText(string $value): bool
    {
        if ($value === '') {
            return false;
        }

        return preg_replace('/[\s\?？\x{FFFD}]+/u', '', $value) !== '';
    }
}
