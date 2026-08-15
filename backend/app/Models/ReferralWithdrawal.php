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

    /** 已打款：支付宝方式审核通过后，管理员确认打款并回填凭证。 */
    public const STATUS_PAID = 3;

    public const STATUS_LABELS = [
        self::STATUS_PENDING => '待处理',
        self::STATUS_APPROVED => '已通过',
        self::STATUS_REJECTED => '已拒绝',
        self::STATUS_PAID => '已打款',
    ];

    public static function statusLabel(int $status): string
    {
        return self::STATUS_LABELS[$status] ?? (string) $status;
    }

    protected $fillable = [
        'user_id',
        'amount',
        'method',
        'account_name',
        'account_no',
        'status',
        'payment_no',
        'remark',
        'operator',
        'trace_id',
        'paid_at',
        'processed_at',
    ];

    protected function casts(): array
    {
        return [
            'user_id' => 'integer',
            'amount' => 'decimal:2',
            'status' => 'integer',
            'paid_at' => 'datetime',
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
