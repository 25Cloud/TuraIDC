<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\NormalizesTraceId;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Withdrawal extends Model
{
    use NormalizesTraceId;

    protected $table = 'withdrawals';

    protected $fillable = [
        'withdrawal_no',
        'user_id',
        'account_type',
        'amount',
        'status',
        'method',
        'account_name',
        'account_no',
        'account_snapshot_json',
        'processed_at',
        'rejected_reason',
        'operator_id',
        'trace_id',
    ];

    protected function casts(): array
    {
        return [
            'user_id' => 'integer',
            'operator_id' => 'integer',
            'amount' => 'decimal:2',
            'status' => 'integer',
            'account_snapshot_json' => 'array',
            'processed_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
