<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\EnsuresTraceId;
use App\Models\Concerns\NormalizesTraceId;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class RechargeRecord extends Model
{
    use EnsuresTraceId, NormalizesTraceId;

    protected $fillable = [
        'record_no',
        'user_id',
        'order_id',
        'invoice_id',
        'payment_id',
        'account_transaction_id',
        'refund_id',
        'origin_recharge_record_id',
        'scene',
        'direction',
        'amount',
        'currency',
        'entry_type',
        'remark',
        'operator_type',
        'operator_id',
        'operator_name',
        'trace_id',
    ];

    protected function casts(): array
    {
        return [
            'user_id' => 'integer',
            'order_id' => 'integer',
            'invoice_id' => 'integer',
            'payment_id' => 'integer',
            'account_transaction_id' => 'integer',
            'refund_id' => 'integer',
            'origin_recharge_record_id' => 'integer',
            'operator_id' => 'integer',
            'amount' => 'decimal:2',
        ];
    }

    public static function generateRecordNo(): string
    {
        return 'RR'.now()->format('YmdHis').Str::upper(Str::random(8));
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    public function payment(): BelongsTo
    {
        return $this->belongsTo(Payment::class);
    }

    public function accountTransaction(): BelongsTo
    {
        return $this->belongsTo(AccountTransaction::class);
    }

    public function refund(): BelongsTo
    {
        return $this->belongsTo(Refund::class);
    }

    public function originRechargeRecord(): BelongsTo
    {
        return $this->belongsTo(self::class, 'origin_recharge_record_id');
    }

    public function offsetRecords(): HasMany
    {
        return $this->hasMany(self::class, 'origin_recharge_record_id');
    }
}
