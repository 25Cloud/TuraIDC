<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $supplier_id
 * @property string|null $balance
 * @property string|null $previous_balance
 * @property string|null $delta
 * @property string|null $currency
 * @property string $source
 * @property int|null $order_id
 * @property Carbon|null $recorded_at
 */
class SupplierBalanceLog extends Model
{
    /** 定时同步触发 */
    public const SOURCE_SCHEDULE = 'schedule';

    /** 上游开通完成后触发 */
    public const SOURCE_PROVISION = 'provision';

    /** 管理员手动查询触发 */
    public const SOURCE_MANUAL = 'manual';

    protected $fillable = [
        'supplier_id',
        'balance',
        'previous_balance',
        'delta',
        'currency',
        'source',
        'order_id',
        'recorded_at',
    ];

    protected function casts(): array
    {
        return [
            'supplier_id' => 'integer',
            'balance' => 'decimal:2',
            'previous_balance' => 'decimal:2',
            'delta' => 'decimal:2',
            'order_id' => 'integer',
            'recorded_at' => 'datetime',
        ];
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class, 'supplier_id');
    }
}
