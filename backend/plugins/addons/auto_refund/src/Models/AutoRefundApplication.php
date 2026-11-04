<?php

declare(strict_types=1);

namespace TuraIDC\Plugins\Addons\AutoRefund\Models;

use App\Models\AdminUser;
use App\Models\Invoice;
use App\Models\Order;
use App\Models\Service;
use App\Models\Ticket;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * 自助退款申请（本地台账）。
 *
 * 状态机的唯一真源就是这里的常量；控制器与服务层只允许用常量，不写字符串字面量。
 *
 * ```text
 * (提交)
 *   created --submit 200--> pending_review --cancel 200--> cancelled
 *                          pending_review --管理员结算--> settled
 *                          pending_review --管理员驳回--> rejected
 *   created --submit 非200--> failed            （凭据/参数/业务拒绝，上游没建单）
 *   created --网络超时------> uncertain         （结果未知，占住幂等闸门）
 *   uncertain/pending_review --管理员作废--> closed
 * ```
 */
class AutoRefundApplication extends Model
{
    public const STATUS_CREATED = 'created';

    public const STATUS_PENDING_REVIEW = 'pending_review';

    public const STATUS_CANCELLED = 'cancelled';

    public const STATUS_SETTLED = 'settled';

    public const STATUS_REJECTED = 'rejected';

    public const STATUS_FAILED = 'failed';

    public const STATUS_UNCERTAIN = 'uncertain';

    public const STATUS_CLOSED = 'closed';

    /**
     * 「在途」状态：这两个状态必须保留 `active_service_id`，否则用户重提会造出
     * 第二笔上游申请（`uncertain` 尤其不能释放闸门）。
     */
    public const ACTIVE_STATUSES = [self::STATUS_PENDING_REVIEW, self::STATUS_UNCERTAIN];

    /** 收口状态：写入时 `active_service_id` 一律置 NULL */
    public const TERMINAL_STATUSES = [
        self::STATUS_CANCELLED,
        self::STATUS_SETTLED,
        self::STATUS_REJECTED,
        self::STATUS_FAILED,
        self::STATUS_CLOSED,
    ];

    /**
     * 「仅限首次退款」时算作用掉额度过的状态。
     *
     * `failed` 不计：那次请求上游根本没建单（凭据/参数/业务拒绝），不该吃掉用户的首次额度；
     * `closed` 计：管理员已作废，说明上游可能确实收到过申请。
     */
    public const FIRST_ONLY_COUNTED_STATUSES = [
        self::STATUS_PENDING_REVIEW,
        self::STATUS_UNCERTAIN,
        self::STATUS_SETTLED,
        self::STATUS_CANCELLED,
        self::STATUS_REJECTED,
        self::STATUS_CLOSED,
    ];

    /**
     * 挡住同一主机再次申请的状态（上游口径：状态非「用户取消」即视为重复提交）。
     *
     * `cancelled` 放行：用户自己撤掉的申请没有生效；
     * `failed` 放行：上游明确没建单，重提不会造出第二笔。
     */
    public const BLOCKING_STATUSES = [
        self::STATUS_PENDING_REVIEW,
        self::STATUS_UNCERTAIN,
        self::STATUS_SETTLED,
        self::STATUS_REJECTED,
        self::STATUS_CLOSED,
    ];

    /** @var array<int, string> */
    public const STATUS_LABELS = [
        self::STATUS_CREATED => '已创建',
        self::STATUS_PENDING_REVIEW => '待上游审核',
        self::STATUS_CANCELLED => '已取消',
        self::STATUS_SETTLED => '已结算',
        self::STATUS_REJECTED => '已驳回',
        self::STATUS_FAILED => '提交失败',
        self::STATUS_UNCERTAIN => '结果未知',
        self::STATUS_CLOSED => '已作废',
    ];

    /**
     * 通道取值（与 `auto_refund_applications.channel` 迁移注释一致）。
     *
     * `manual` 与 `auto` 类型都不外发，同属 `local`；类型差异由 `refund_type` 表达。
     */
    public const CHANNEL_LOCAL = 'local';

    public const CHANNEL_BRIDGE = 'bridge';

    public const CHANNEL_API_TICKET = 'api_ticket';

    /** @var array<string, string> */
    public const CHANNEL_LABELS = [
        self::CHANNEL_LOCAL => '本站处理',
        self::CHANNEL_BRIDGE => '上游接口',
        self::CHANNEL_API_TICKET => '上游工单',
    ];

    protected $fillable = [
        'application_no',
        'user_id',
        'service_id',
        'order_id',
        'invoice_id',
        'upstream_product_id',
        'status',
        'refund_type',
        'channel',
        'ticket_id',
        'upstream_ticket_id',
        'policy_snapshot',
        'active_service_id',
        'reason',
        'estimated_amount',
        'settled_amount',
        'upstream_code',
        'upstream_msg',
        'trace_id',
        'response_meta',
        'submitted_at',
        'cancelled_at',
        'settled_at',
        'operator_id',
        'settle_remark',
    ];

    protected function casts(): array
    {
        return [
            'estimated_amount' => 'decimal:2',
            'settled_amount' => 'decimal:2',
            'upstream_code' => 'integer',
            'response_meta' => 'array',
            // 策略快照是提交那一刻的 JSON 字符串：列类型是 text（MySQL 5.7 不用 json 列），
            // 用 array cast 由 Eloquent 负责编解码，读写都拿到数组。
            'policy_snapshot' => 'array',
            'ticket_id' => 'integer',
            'submitted_at' => 'datetime',
            'cancelled_at' => 'datetime',
            'settled_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function service(): BelongsTo
    {
        return $this->belongsTo(Service::class, 'service_id');
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class, 'order_id');
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class, 'invoice_id');
    }

    public function operator(): BelongsTo
    {
        return $this->belongsTo(AdminUser::class, 'operator_id');
    }

    /**
     * `api_ticket` 通道生成的承载工单。
     */
    public function ticket(): BelongsTo
    {
        return $this->belongsTo(Ticket::class, 'ticket_id');
    }

    /**
     * 在途申请（占着幂等闸门的那些）。
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->whereIn('status', self::ACTIVE_STATUSES);
    }

    public function statusLabel(): string
    {
        return self::STATUS_LABELS[(string) $this->status] ?? (string) $this->status;
    }

    public function channelLabel(): string
    {
        $channel = (string) $this->channel;

        return self::CHANNEL_LABELS[$channel] ?? ($channel !== '' ? $channel : self::CHANNEL_LOCAL);
    }

    /**
     * 退款类型标签。类型常量与文案的真源在 `AutoRefundProductRule`（商品策略那份枚举），
     * 台账只存快照值，展示时回查同一份文案，避免两处各写一套中文。
     */
    public function refundTypeLabel(): string
    {
        $type = (string) $this->refund_type;

        return AutoRefundProductRule::TYPE_LABELS[$type] ?? ($type !== '' ? $type : AutoRefundProductRule::TYPE_MANUAL);
    }

    public function isActive(): bool
    {
        return in_array((string) $this->status, self::ACTIVE_STATUSES, true);
    }

    public function isPendingReview(): bool
    {
        return (string) $this->status === self::STATUS_PENDING_REVIEW;
    }

    /**
     * 用户可自行取消的唯一状态。
     */
    public function isCancellable(): bool
    {
        return $this->isPendingReview();
    }

    /**
     * 后台展示/前端本地化用的状态枚举。
     *
     * @return array<int, array{value: string, label: string}>
     */
    public static function statusOptions(): array
    {
        $options = [];
        foreach (self::STATUS_LABELS as $value => $label) {
            $options[] = ['value' => $value, 'label' => $label];
        }

        return $options;
    }
}
