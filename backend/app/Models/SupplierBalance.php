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
    /** 余额不足告警阈值的默认值（decimal 字符串，避免浮点字面量进入金额路径） */
    public const DEFAULT_LOW_BALANCE_THRESHOLD = '20.00';

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
     * 金额转整数分——纯字符串解析，全程不经浮点。
     *
     * decimal cast 取回的是字符串（如 "123.45"），直接按小数点切分定标即可。
     * 不用 (float)*100：金额禁止进入浮点路径；也不用 bcmath：本仓零 bcmath 依赖，
     * 宝塔部署的扩展清单里也没有该扩展，不能假定可用。
     *
     * decimal(14,2) 的整数部分最多 12 位，×100 后 14 位，远在 64 位 int 范围内。
     */
    public static function toCents(mixed $amount): int
    {
        $raw = trim((string) $amount);
        if ($raw === '') {
            return 0;
        }

        // 科学计数法（"1.5e3"）按小数点切分会算出完全错误的值。正常输入到不了这里
        // （取值层已把上游余额规整成定点字符串），但本方法是公开静态入口，挡一道更稳妥。
        if (str_contains($raw, 'e') || str_contains($raw, 'E')) {
            $raw = sprintf('%.2F', (float) $raw);
        }

        $negative = str_starts_with($raw, '-');
        $raw = ltrim($raw, '+-');
        [$integerPart, $fractionPart] = array_pad(explode('.', $raw, 2), 2, '');

        // 小数位定标到两位：不足补零，超出直接截断（decimal(14,2) 本身只存两位）
        $fractionPart = substr(str_pad($fractionPart, 2, '0'), 0, 2);
        $cents = (int) ($integerPart === '' ? '0' : $integerPart) * 100 + (int) $fractionPart;

        return $negative ? -$cents : $cents;
    }

    /**
     * 整数分转回 decimal 字符串，用于写库与展示，同样不经浮点。
     */
    public static function centsToDecimal(int $cents): string
    {
        $sign = $cents < 0 ? '-' : '';
        $abs = abs($cents);

        return $sign.intdiv($abs, 100).'.'.str_pad((string) ($abs % 100), 2, '0', STR_PAD_LEFT);
    }
}
