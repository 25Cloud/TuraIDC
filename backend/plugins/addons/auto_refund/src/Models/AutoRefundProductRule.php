<?php

declare(strict_types=1);

namespace TuraIDC\Plugins\Addons\AutoRefund\Models;

use App\Models\AdminUser;
use App\Models\Product;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * 商品级退款策略（`auto_refund_product_rules`，一行一个商品）。
 *
 * 为什么粒度是商品而不是全局一套：上游 AutoRefund 的「退款类型 / 退款规则 / 退款要求」
 * 三项都挂在**商品**上（它后台「添加产品」页逐商品配置，`product_refund` 表一行一个商品）。
 * 「产品首次退款」这类要求拿全局开关根本表达不了——判定粒度本来就是商品。
 *
 * 常量取值与上游 `product_refund` 表一一对应（见执行计划第二阶段的语义对照表）：
 * - `type`：1 人工审核 → `manual`、2 自动退款 → `auto`、3 API 工单退款 → `api_ticket`、4 插件间对接 → `bridge`；
 * - `rules`：1 按时长 → `duration`、2 按月 → `month`、3 全额 → `full`；
 * - `request`：1 产品首次 → `product_first`、2 同类产品首次 → `category_first`、3 指定时间内 → `within_hours`；
 * - `within`：上游存**小时**，本站列名 `window_hours`，只在 `within_hours` 下生效。
 *
 * 状态码保留本站字符串而不是 1/2/3/4：前端已经按字符串渲染，改成数字要动三端。
 */
class AutoRefundProductRule extends Model
{
    public const TYPE_MANUAL = 'manual';

    public const TYPE_AUTO = 'auto';

    public const TYPE_API_TICKET = 'api_ticket';

    public const TYPE_BRIDGE = 'bridge';

    public const RULE_DURATION = 'duration';

    public const RULE_MONTH = 'month';

    public const RULE_FULL = 'full';

    public const REQUIREMENT_PRODUCT_FIRST = 'product_first';

    public const REQUIREMENT_CATEGORY_FIRST = 'category_first';

    public const REQUIREMENT_WITHIN_HOURS = 'within_hours';

    /** @var array<int, string> */
    public const REFUND_TYPES = [self::TYPE_MANUAL, self::TYPE_AUTO, self::TYPE_API_TICKET, self::TYPE_BRIDGE];

    /** @var array<int, string> */
    public const REFUND_RULES = [self::RULE_DURATION, self::RULE_MONTH, self::RULE_FULL];

    /** @var array<int, string> */
    public const REQUIREMENTS = [
        self::REQUIREMENT_PRODUCT_FIRST,
        self::REQUIREMENT_CATEGORY_FIRST,
        self::REQUIREMENT_WITHIN_HOURS,
    ];

    /** @var array<string, string> */
    public const TYPE_LABELS = [
        self::TYPE_MANUAL => '人工审核',
        self::TYPE_AUTO => '自动退款',
        self::TYPE_API_TICKET => 'API 工单退款',
        self::TYPE_BRIDGE => '插件间对接',
    ];

    /** @var array<string, string> */
    public const RULE_LABELS = [
        self::RULE_DURATION => '按时长折算',
        self::RULE_MONTH => '按月折算',
        self::RULE_FULL => '全额退款',
    ];

    /** @var array<string, string> */
    public const REQUIREMENT_LABELS = [
        self::REQUIREMENT_PRODUCT_FIRST => '同商品首次退款',
        self::REQUIREMENT_CATEGORY_FIRST => '同分组首次退款',
        self::REQUIREMENT_WITHIN_HOURS => '指定时间内可退',
    ];

    protected $table = 'auto_refund_product_rules';

    protected $fillable = [
        'product_id',
        'refund_type',
        'refund_rule',
        'requirement',
        'window_hours',
        'enabled',
        'operator_id',
    ];

    protected function casts(): array
    {
        return [
            'product_id' => 'integer',
            'window_hours' => 'integer',
            'enabled' => 'boolean',
            'operator_id' => 'integer',
        ];
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'product_id');
    }

    public function operator(): BelongsTo
    {
        return $this->belongsTo(AdminUser::class, 'operator_id');
    }

    /**
     * 只有「启用」的行才生效：停用等于该商品不参与自助退款，
     * 与「没有这一行」在业务上同义（判定口径见 AutoRefundPolicy）。
     */
    public function scopeEnabled(Builder $query): Builder
    {
        return $query->where('enabled', 1);
    }

    public function refundType(): string
    {
        $type = (string) $this->refund_type;

        return array_key_exists($type, self::TYPE_LABELS) ? $type : self::TYPE_MANUAL;
    }

    public function refundRule(): string
    {
        $rule = (string) $this->refund_rule;

        return array_key_exists($rule, self::RULE_LABELS) ? $rule : self::RULE_DURATION;
    }

    public function requirement(): string
    {
        $requirement = (string) $this->requirement;

        return array_key_exists($requirement, self::REQUIREMENT_LABELS)
            ? $requirement
            : self::REQUIREMENT_PRODUCT_FIRST;
    }

    public function refundTypeLabel(): string
    {
        return self::TYPE_LABELS[$this->refundType()] ?? $this->refundType();
    }

    public function refundRuleLabel(): string
    {
        return self::RULE_LABELS[$this->refundRule()] ?? $this->refundRule();
    }

    public function requirementLabel(): string
    {
        return self::REQUIREMENT_LABELS[$this->requirement()] ?? $this->requirement();
    }

    /**
     * 时间窗口（小时）。只有 `within_hours` 才有意义，0 表示不限。
     */
    public function windowHours(): int
    {
        return max(0, (int) $this->window_hours);
    }

    /**
     * 该退款类型是否走「上游判首退/时间窗口」——`bridge` 与 `api_ticket` 两条通道
     * 都由上游按其自身商品配置复核，本地只保留其余前置校验（见执行计划第二阶段）。
     */
    public function delegatesRequirementToUpstream(): bool
    {
        return in_array($this->refundType(), [self::TYPE_API_TICKET, self::TYPE_BRIDGE], true);
    }

    /**
     * 写入台账的 `channel` 口径：只有三种（`local` / `bridge` / `api_ticket`）。
     * `manual` 与 `auto` 都不外发，同属 `local`——类型差异由 `refund_type` 表达。
     */
    public function channel(): string
    {
        return match ($this->refundType()) {
            self::TYPE_BRIDGE => 'bridge',
            self::TYPE_API_TICKET => 'api_ticket',
            default => 'local',
        };
    }

    /**
     * 后台下拉/前端本地化用的枚举清单。
     *
     * @return array<int, array{value: string, label: string}>
     */
    public static function typeOptions(): array
    {
        return self::optionsOf(self::TYPE_LABELS);
    }

    /**
     * @return array<int, array{value: string, label: string}>
     */
    public static function ruleOptions(): array
    {
        return self::optionsOf(self::RULE_LABELS);
    }

    /**
     * @return array<int, array{value: string, label: string}>
     */
    public static function requirementOptions(): array
    {
        return self::optionsOf(self::REQUIREMENT_LABELS);
    }

    /**
     * @param  array<string, string>  $labels
     * @return array<int, array{value: string, label: string}>
     */
    private static function optionsOf(array $labels): array
    {
        $options = [];
        foreach ($labels as $value => $label) {
            $options[] = ['value' => (string) $value, 'label' => $label];
        }

        return $options;
    }
}
