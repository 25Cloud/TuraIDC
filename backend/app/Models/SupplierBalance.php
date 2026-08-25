<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * 属性类型显式声明：静态分析读不到刚新增的表结构时会把日期列推断成 string，
 * 导致对 Carbon 方法的调用被误报。
 *
 * @property int $id
 * @property int $supplier_id
 * @property string|null $provider_key
 * @property string|null $balance
 * @property string|null $currency
 * @property string $low_balance_threshold
 * @property bool $low_balance_alert_enabled
 * @property Carbon|null $last_synced_at
 * @property Carbon|null $last_attempted_at
 * @property string|null $last_sync_status
 * @property string|null $last_sync_error
 * @property Carbon|null $low_balance_notified_at
 */
class SupplierBalance extends Model
{
    /** 余额不足告警阈值的默认值 */
    public const DEFAULT_LOW_BALANCE_THRESHOLD = 20.0;

    protected $fillable = [
        'supplier_id',
        'provider_key',
        'balance',
        'currency',
        'low_balance_threshold',
        'low_balance_alert_enabled',
        'last_synced_at',
        'last_attempted_at',
        'last_sync_status',
        'last_sync_error',
        'low_balance_notified_at',
    ];

    protected function casts(): array
    {
        return [
            'supplier_id' => 'integer',
            'balance' => 'decimal:2',
            'low_balance_threshold' => 'decimal:2',
            'low_balance_alert_enabled' => 'boolean',
            'last_synced_at' => 'datetime',
            'last_attempted_at' => 'datetime',
            'low_balance_notified_at' => 'datetime',
        ];
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class, 'supplier_id');
    }

    /**
     * 余额是否已跌破阈值。
     *
     * 从未同步成功（balance 为 null）时返回 false：那是"未知"而不是"不足"，
     * 拿未知去触发告警会在上游接口刚接入、尚未同步时误报。
     *
     * 比较走整数分而非浮点：告警边界不应由二进制浮点的表示误差决定
     * （例如 20.00 与阈值 20 在浮点下可能因累积误差出现意外的大小关系）。
     */
    public function isBelowThreshold(): bool
    {
        if ($this->balance === null) {
            return false;
        }

        return self::toCents($this->balance) < self::toCents($this->low_balance_threshold);
    }

    /**
     * 金额转整数分。
     *
     * decimal cast 取回的是字符串，先按两位小数定标再取整，避免直接 (int) 截断。
     */
    public static function toCents(mixed $amount): int
    {
        return (int) round(((float) $amount) * 100);
    }
}
