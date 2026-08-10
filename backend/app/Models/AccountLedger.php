<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\NormalizesTraceId;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AccountLedger extends Model
{
    use NormalizesTraceId;

    protected $table = 'account_ledgers';

    protected $fillable = [
        'user_id',
        'account_type',
        'business_type',
        'direction',
        'amount',
        'balance_before',
        'balance_after',
        'source_type',
        'source_id',
        'operator_type',
        'operator_id',
        'remark',
        'trace_id',
        'happened_at',
    ];

    protected function casts(): array
    {
        return [
            'user_id' => 'integer',
            'source_id' => 'integer',
            'operator_id' => 'integer',
            'amount' => 'decimal:2',
            'balance_before' => 'decimal:2',
            'balance_after' => 'decimal:2',
            'happened_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
