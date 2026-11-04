<?php

declare(strict_types=1);

namespace TuraIDC\Plugins\Addons\AutoRefund\Services;

use App\Constants\InvoiceStatus;
use App\Constants\InvoiceType;
use App\Constants\ServiceStatus;
use App\Models\Invoice;
use App\Models\Order;
use App\Models\Product;
use App\Models\Service;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use TuraIDC\Plugins\Addons\AutoRefund\Models\AutoRefundApplication;
use TuraIDC\Plugins\Addons\AutoRefund\Models\AutoRefundProductRule;

/**
 * 本地可退资格与预估金额（完整复刻上游 AutoRefund 的三种规则 + 三种要求 + 九条前置校验）。
 *
 * ## 字段映射（上游 → 本站）——这三项算错就是退错钱
 *
 * | 上游 `calcRefund()` 用的字段 | 本站来源 | 说明 |
 * | --- | --- | --- |
 * | `regdate`（开通时间） | `services.created_at` | 本站服务实例没有独立开通时间列，创建服务行即开通起点 |
 * | `nextduedate`（到期时间） | `services.expires_at` | 服务表唯一的到期时间真源 |
 * | `firstpaymentamount`（首付实付） | 首购账单 `invoices.paid_amount` | 优先 `services.order.invoice`（开通那一单），
 *   其次 `services.invoice_id`；`paid_amount` 为 0 时退回 `invoices.amount` |
 *
 * 金额公式逐字照抄上游 `calcRefund()`，不自己发明口径：
 *
 * ```text
 * 周期秒数 = nextduedate - regdate
 * 每秒单价 = firstpaymentamount / 周期秒数
 * 已用秒数 = now - regdate
 *
 * 按时长：金额 = firstpaymentamount - 每秒单价 * 已用秒数
 * 按月：  月单价 = 每秒单价 * 2592000
 *        已用月数 = ceil(已用秒数 / 2592000)
 *        金额 = firstpaymentamount - 月单价 * 已用月数
 * 全额：  金额 = firstpaymentamount
 * 统一 round(金额, 2)；结果 <= 0 直接拒绝申请。
 * ```
 *
 * 主机处于 `Pending`（未开通完成）时上游**强制按全额**算，不看商品配置的规则——开通失败
 * 通常意味着上游要整单退，按已用时长折算只会把「一台从没交付的机器」算成部分退款。
 *
 * ## 边界
 *
 * 金额只是给用户看的**预估**：商业版 `api.php` 契约明确「调用方不能指定金额」，
 * 实退金额以上游与管理员确认为准，结算还受既有账单可退上限保护。
 */
final class AutoRefundPolicy
{
    /** 上游 `within` / 兼容口径里「一个月」的秒数（30 天） */
    public const SECONDS_PER_MONTH = 2592000;

    // —— 第一阶段沿用、第二阶段继续使用的枚举码 ——
    public const REASON_UPSTREAM_MISSING = 'upstream_missing';

    public const REASON_NO_PAID_INVOICE = 'no_paid_invoice';

    public const REASON_WINDOW_EXPIRED = 'window_expired';

    public const REASON_FIRST_ONLY_USED = 'first_only_used';

    public const REASON_PENDING_APPLICATION = 'pending_application';

    public const REASON_AMOUNT_TOO_LOW = 'amount_too_low';

    public const REASON_UPSTREAM_UNAVAILABLE = 'upstream_unavailable';

    // —— 第二阶段新增（九条前置校验与商品策略） ——
    public const REASON_RULE_MISSING = 'rule_missing';

    public const REASON_RENEWED = 'renewed';

    public const REASON_UPGRADED = 'upgraded';

    public const REASON_FREE_ORDER = 'free_order';

    public const REASON_SERVICE_STATUS = 'service_status';

    public const REASON_EXPIRED = 'expired';

    public const REASON_UNPAID = 'unpaid';

    /** @var array<string, string> 枚举码 → 中文文案（提交时直接回给用户） */
    public const REASON_LABELS = [
        self::REASON_UPSTREAM_MISSING => '上游列表中找不到这台机器，请联系客服处理',
        self::REASON_NO_PAID_INVOICE => '该服务没有可退的已支付账单',
        self::REASON_WINDOW_EXPIRED => '已超过可自助退款的时间窗口，请联系客服',
        self::REASON_FIRST_ONLY_USED => '该商品/分组已有退款记录，本站仅支持首次自助退款，请联系客服',
        self::REASON_PENDING_APPLICATION => '该服务已有进行中的退款申请',
        self::REASON_AMOUNT_TOO_LOW => '可退金额不足，无法自助提交',
        self::REASON_UPSTREAM_UNAVAILABLE => '上游暂不可用，请稍后再试',
        self::REASON_RULE_MISSING => '当前商品不支持自助退款，请联系客服',
        self::REASON_RENEWED => '已续费的商品不支持自助申请退款，请联系客服',
        self::REASON_UPGRADED => '已升级过配置的商品不支持自助申请退款，请联系客服',
        self::REASON_FREE_ORDER => '免费订单不支持自助申请退款',
        self::REASON_SERVICE_STATUS => '该服务当前状态不支持申请退款，请联系客服',
        self::REASON_EXPIRED => '该服务已到期，不支持自助申请退款，请联系客服',
        self::REASON_UNPAID => '该服务的首购账单尚未支付，不支持申请退款',
    ];

    public function __construct(
        private readonly AutoRefundSettings $settings,
    ) {}

    /**
     * 评估一台服务实例（`/candidates` 与提交前的唯一判定入口）。
     *
     * `$context` 只承载「上游可见性」这类调用方掌握的外部事实：
     * - `upstream_host_id`：本地解析出的上游 hostid；
     * - `upstream_available`：上游查询是否可用（false 时不给「上游缺机器」的误判）；
     * - `upstream_contains`：该 hostid 是否出现在上游列表里；
     * - `active_application_id`：调用方查到的在途申请（可选，policy 自己也会查）。
     *
     * @param  array{
     *     upstream_host_id?: string,
     *     upstream_available?: bool,
     *     upstream_contains?: bool,
     *     active_application_id?: int|null
     * }  $context
     * @return array{
     *     refundable: bool,
     *     policy_configured: bool,
     *     refund_type: string,
     *     refund_type_label: string,
     *     refund_rule: string,
     *     refund_rule_label: string,
     *     requirement: string,
     *     requirement_label: string,
     *     window_hours: int,
     *     estimated_amount: string,
     *     estimate_hint: string,
     *     blocked_reasons: array<int, string>,
     *     invoice: Invoice|null,
     *     used_seconds: int,
     *     total_seconds: int,
     *     base_amount: string,
     *     forced_full: bool,
     *     policy_snapshot: array<string, mixed>
     * }
     */
    public function evaluate(Service $service, array $context = []): array
    {
        $blocked = [];

        $rule = $this->ruleFor($service);
        $policyConfigured = $rule instanceof AutoRefundProductRule;

        // —— 校验 1：商品没有退款策略 ——
        if (! $policyConfigured) {
            $blocked[] = self::REASON_RULE_MISSING;
        }

        // —— 校验 2：首购账单必须存在且已支付 ——
        $invoice = $this->resolveFirstPurchaseInvoice($service);
        if (! $invoice instanceof Invoice) {
            $blocked[] = self::REASON_NO_PAID_INVOICE;
        } elseif (! in_array((int) $invoice->status, [InvoiceStatus::PAID, InvoiceStatus::PARTIALLY_REFUNDED], true)) {
            $blocked[] = self::REASON_UNPAID;
        }

        // —— 校验 3：同一主机已有未取消的申请 ——
        if ($this->hasBlockingApplication($service, $context)) {
            $blocked[] = self::REASON_PENDING_APPLICATION;
        }

        $status = (int) $service->status;
        $pending = $status === ServiceStatus::PENDING;
        $expired = $service->expires_at !== null && $service->expires_at->isPast();

        // —— 校验 4：非 Pending 且已过期 ——
        if (! $pending && $expired) {
            $blocked[] = self::REASON_EXPIRED;
        }

        // —— 校验 5：服务已删除（软删或被取消到终态） ——
        if ($service->trashed() || $status === ServiceStatus::CANCELLED) {
            $blocked[] = self::REASON_SERVICE_STATUS;
        }

        // —— 校验 6：既不是运行中也不是 Pending ——
        if (! $pending && $status !== ServiceStatus::ACTIVE) {
            $blocked[] = self::REASON_SERVICE_STATUS;
        }

        // —— 校验 7：存在续费账单 ——
        if ($this->hasInvoiceOfType($service, InvoiceType::RENEW)) {
            $blocked[] = self::REASON_RENEWED;
        }

        // —— 校验 8：首付金额为 0（免费订单） ——
        $baseAmount = $this->baseAmount($invoice);
        if ($baseAmount <= 0) {
            $blocked[] = self::REASON_FREE_ORDER;
        }

        // —— 校验 9：存在升降级账单 ——
        if ($this->hasInvoiceOfType($service, InvoiceType::UPGRADE)) {
            $blocked[] = self::REASON_UPGRADED;
        }

        // 上游可见性（调用方的外部事实，不属于九条校验但同属「不能提交」）。
        //
        // 只在**真的依赖上游**时才拦：
        // - `bridge` 要按 hostid 提交、还要在 `query` 列表里可见；
        // - `api_ticket` 只需要一个 hostid 填进工单的 `host_id`，不要求它出现在 api.php 的列表里
        //   （只用工单通道、压根没配 api.php 的站点是正常部署形态）；
        // - `manual` / `auto` 完全不出站，上游挂了也不该让它们变成不可退。
        $upstreamAvailable = (bool) ($context['upstream_available'] ?? true);
        $upstreamHostId = trim((string) ($context['upstream_host_id'] ?? ''));
        $upstreamContains = array_key_exists('upstream_contains', $context)
            ? (bool) $context['upstream_contains']
            : true;

        $refundType = $rule?->refundType() ?? AutoRefundProductRule::TYPE_MANUAL;
        $needsListing = $refundType === AutoRefundProductRule::TYPE_BRIDGE;
        $needsHostId = in_array($refundType, [
            AutoRefundProductRule::TYPE_BRIDGE,
            AutoRefundProductRule::TYPE_API_TICKET,
        ], true);

        if ($needsListing && ! $upstreamAvailable) {
            $blocked[] = self::REASON_UPSTREAM_UNAVAILABLE;
        } elseif ($needsHostId && $upstreamHostId === '') {
            $blocked[] = self::REASON_UPSTREAM_MISSING;
        } elseif ($needsListing && ! $upstreamContains) {
            $blocked[] = self::REASON_UPSTREAM_MISSING;
        }

        [$amount, $hint, $usedSeconds, $totalSeconds, $forcedFull] = $this->estimate($service, $baseAmount, $rule, $pending);

        // 金额 <= 0 直接拒绝申请（上游同样拒）
        if ($amount <= 0) {
            $blocked[] = self::REASON_AMOUNT_TOO_LOW;
        }

        // —— 三种「退款要求」 ——
        // `bridge` / `api_ticket` 两条通道由上游按其自身商品配置复核首退与时间窗口，
        // 本地只保留九条前置校验；`manual` / `auto` 完全按本地策略判定。
        $delegates = $rule instanceof AutoRefundProductRule && $rule->delegatesRequirementToUpstream();
        $requirement = $rule?->requirement() ?? AutoRefundProductRule::REQUIREMENT_PRODUCT_FIRST;
        $windowHours = $rule?->windowHours() ?? 0;

        if ($rule instanceof AutoRefundProductRule && ! $delegates) {
            if ($requirement === AutoRefundProductRule::REQUIREMENT_WITHIN_HOURS) {
                if ($this->windowExpired($service, $windowHours)) {
                    $blocked[] = self::REASON_WINDOW_EXPIRED;
                }
            } elseif ($this->hasPreviousRefund($service, $requirement)) {
                $blocked[] = self::REASON_FIRST_ONLY_USED;
            }
        }

        $blocked = array_values(array_unique($blocked));

        return [
            'refundable' => $blocked === [],
            'policy_configured' => $policyConfigured,
            'refund_type' => $refundType,
            'refund_type_label' => AutoRefundProductRule::TYPE_LABELS[$refundType] ?? $refundType,
            'refund_rule' => $rule?->refundRule() ?? AutoRefundProductRule::RULE_DURATION,
            'refund_rule_label' => $rule?->refundRuleLabel() ?? AutoRefundProductRule::RULE_LABELS[AutoRefundProductRule::RULE_DURATION],
            'requirement' => $requirement,
            'requirement_label' => AutoRefundProductRule::REQUIREMENT_LABELS[$requirement] ?? $requirement,
            'window_hours' => $windowHours,
            'estimated_amount' => number_format($amount, 2, '.', ''),
            'estimate_hint' => $hint,
            'blocked_reasons' => $blocked,
            'invoice' => $invoice,
            'used_seconds' => $usedSeconds,
            'total_seconds' => $totalSeconds,
            'base_amount' => number_format($baseAmount, 2, '.', ''),
            'forced_full' => $forcedFull,
            'policy_snapshot' => $this->snapshot($service, $rule, $requirement, $windowHours, $delegates, [
                'amount' => number_format($amount, 2, '.', ''),
                'base_amount' => number_format($baseAmount, 2, '.', ''),
                'used_seconds' => $usedSeconds,
                'total_seconds' => $totalSeconds,
                'forced_full' => $forcedFull,
                'hint' => $hint,
                'blocked_reasons' => $blocked,
            ]),
        ];
    }

    /**
     * 商品策略：一行一个商品，**没有行或 `enabled = 0` 都等于「不参与自助退款」**。
     *
     * 商品隐藏/下架不参与判定：退款是既有交易关系的收尾，不是售卖行为，
     * 下架商品照样该让用户退（上游也是这么处理的）。
     */
    public function ruleFor(Service $service): ?AutoRefundProductRule
    {
        $productId = (int) ($service->product_id ?? 0);
        if ($productId <= 0) {
            return null;
        }

        return AutoRefundProductRule::query()
            ->where('product_id', $productId)
            ->where('enabled', 1)
            ->first();
    }

    /**
     * 首购账单：优先服务所属订单（开通那一单）的账单，其次服务当前关联账单。
     *
     * 为什么不先看 `services.invoice_id`：该列的语义是「**最近一次**关联账单」，
     * 服务续费后它会指向续费账单，拿它当首付实付会把续费金额当成本次退款基数。
     */
    public function resolveFirstPurchaseInvoice(Service $service): ?Invoice
    {
        $order = $service->order instanceof Order ? $service->order : null;

        if ($order instanceof Order && $order->invoice instanceof Invoice) {
            return $order->invoice;
        }

        return $service->invoice instanceof Invoice ? $service->invoice : null;
    }

    /**
     * 首购实付金额：`paid_amount` 为 0 时退回 `amount`（都是账单口径，不含优惠券快照推算）。
     */
    private function baseAmount(?Invoice $invoice): float
    {
        if (! $invoice instanceof Invoice) {
            return 0.0;
        }

        $paid = (float) ($invoice->paid_amount ?? 0);
        if ($paid > 0) {
            return round($paid, 2);
        }

        return round((float) ($invoice->amount ?? 0), 2);
    }

    /**
     * @return array{0: float, 1: string, 2: int, 3: int, 4: bool} 金额、提示、已用秒、周期秒、是否强制全额
     */
    private function estimate(Service $service, float $base, ?AutoRefundProductRule $rule, bool $pending): array
    {
        $start = $service->created_at?->toImmutable();
        $end = $service->expires_at?->toImmutable();
        $now = CarbonImmutable::now();

        $usedSeconds = $start instanceof CarbonImmutable ? max(0, $now->getTimestamp() - $start->getTimestamp()) : 0;
        $totalSeconds = ($start instanceof CarbonImmutable && $end instanceof CarbonImmutable)
            ? $end->getTimestamp() - $start->getTimestamp()
            : 0;

        if ($base <= 0) {
            return [0.0, '没有可退的已付金额', $usedSeconds, $totalSeconds, false];
        }

        // Pending（未开通完成）时上游强制按全额算，不看商品配置的规则
        if ($pending) {
            return [$base, '全额（机器尚未开通完成，按首付实付全额退）', $usedSeconds, $totalSeconds, true];
        }

        if ($totalSeconds <= 0) {
            // 缺到期时间或周期非正数：算不出「每秒单价」，给 0 让 amount_too_low 挡住，
            // 而不是猜一个全额。
            return [0.0, '服务缺少有效期，无法折算可退金额', $usedSeconds, $totalSeconds, false];
        }

        $ruleKey = $rule?->refundRule() ?? AutoRefundProductRule::RULE_DURATION;
        $perSecond = $base / $totalSeconds;

        if ($ruleKey === AutoRefundProductRule::RULE_FULL) {
            return [$base, '全额（首购实付 '.number_format($base, 2, '.', '').'）', $usedSeconds, $totalSeconds, false];
        }

        if ($ruleKey === AutoRefundProductRule::RULE_MONTH) {
            $monthPrice = $perSecond * self::SECONDS_PER_MONTH;
            $usedMonths = (int) ceil($usedSeconds / self::SECONDS_PER_MONTH);
            // 算出来可能为负（已用超过周期）：按 0 展示，仍然走 amount_too_low 拒绝，
            // 决策不变，只是不把负数抛给前端。
            $amount = max(0.0, round($base - $monthPrice * $usedMonths, 2));

            return [
                $amount,
                '按月折算：已用 '.$usedMonths.' 个月 × '.number_format($monthPrice, 2, '.', '').' 元/月',
                $usedSeconds,
                $totalSeconds,
                false,
            ];
        }

        $amount = max(0.0, round($base - $perSecond * $usedSeconds, 2));

        return [
            $amount,
            '按时长折算：已用 '.$this->formatSeconds($usedSeconds).' / 周期 '.$this->formatSeconds($totalSeconds),
            $usedSeconds,
            $totalSeconds,
            false,
        ];
    }

    private function formatSeconds(int $seconds): string
    {
        if ($seconds < 86400) {
            return max(0, (int) round($seconds / 3600)).' 小时';
        }

        return round($seconds / 86400, 1).' 天';
    }

    /**
     * 指定时间内可退：开通时间 + N 小时 < 现在 才拒（边界放行）。
     */
    private function windowExpired(Service $service, int $windowHours): bool
    {
        if ($windowHours <= 0) {
            return false;
        }

        $start = $service->created_at;
        if ($start === null) {
            return false;
        }

        $deadline = $start->copy()->addSeconds($windowHours * 3600);

        return $deadline->lessThan(now());
    }

    /**
     * 「首次」要求：该用户是否已经在这个范围内退过款。
     *
     * - `product_first`：同商品；
     * - `category_first`：同**一级商品分组**。
     *
     * 与上游的刻意差异：上游 `request = 2` 的实现与 `request = 1` 完全一样（都按 productid 查），
     * 等于两个选项没区别。本站按一级分组判定，语义符合它自己的界面文案。
     *
     * 已取消的申请不算（上游同样排除 `status = 4`）：取消代表这笔申请没有生效。
     */
    private function hasPreviousRefund(Service $service, string $requirement): bool
    {
        $query = AutoRefundApplication::query()
            ->where('user_id', (int) $service->user_id)
            ->where('service_id', '<>', (int) $service->id)
            ->whereIn('status', AutoRefundApplication::BLOCKING_STATUSES);

        if ($requirement === AutoRefundProductRule::REQUIREMENT_CATEGORY_FIRST) {
            $firstGroupId = $this->firstProductGroupId((int) $service->product_id);
            if ($firstGroupId <= 0) {
                // 商品没有挂分组时退化成「同商品」判定，不给一条永不命中的放行口子
                return (clone $query)->whereIn('service_id', $this->serviceIdsOfProduct((int) $service->product_id))->exists();
            }

            $productIds = Product::query()
                ->inFirstProductGroup($firstGroupId)
                ->select('products.id');

            return $query
                ->whereIn('service_id', Service::query()->select('id')->whereIn('product_id', $productIds))
                ->exists();
        }

        return $query
            ->whereIn('service_id', $this->serviceIdsOfProduct((int) $service->product_id))
            ->exists();
    }

    /**
     * @return Builder<Service>
     */
    private function serviceIdsOfProduct(int $productId): Builder
    {
        return Service::query()->select('id')->where('product_id', $productId);
    }

    /**
     * 同主机是否已有「未取消」的申请（上游判断口径：状态非 4 即视为重复提交）。
     */
    private function hasBlockingApplication(Service $service, array $context): bool
    {
        if ((int) ($context['active_application_id'] ?? 0) > 0) {
            return true;
        }

        return AutoRefundApplication::query()
            ->where('service_id', (int) $service->id)
            ->whereIn('status', AutoRefundApplication::BLOCKING_STATUSES)
            ->exists();
    }

    /**
     * 该服务名下是否存在指定类型的账单（`renew` / `upgrade`）。
     *
     * 计划里写的是 `invoice_items.type = renew`，实际列名是 `invoice_items.item_type`
     * （它是 `invoices.type` 的投影，见 `Invoice::syncInvoiceItemProjection()`）。
     * 投影只在特定时机同步，历史账单可能还没有明细行，所以这里两条都认：
     * `invoices.type` 命中，或该账单的 `invoice_items.item_type` 命中。
     * 已取消的账单不算——取消的续费单不该永久锁死用户的退款入口。
     */
    private function hasInvoiceOfType(Service $service, string $invoiceType): bool
    {
        return Invoice::query()
            ->where(function ($query) use ($service): void {
                $query->where('service_id', (int) $service->id);
                if ((int) ($service->order_id ?? 0) > 0) {
                    $query->orWhere('order_id', (int) $service->order_id);
                }
            })
            ->where('status', '<>', InvoiceStatus::CANCELLED)
            ->where(function ($query) use ($invoiceType): void {
                $query->where('type', $invoiceType)
                    ->orWhereIn('id', DB::table('invoice_items')->select('invoice_id')->where('item_type', $invoiceType));
            })
            ->exists();
    }

    private function firstProductGroupId(int $productId): int
    {
        if ($productId <= 0) {
            return 0;
        }

        $row = DB::table('products as p')
            ->leftJoin('third_product_groups as third', 'third.id', '=', 'p.product_group_id')
            ->leftJoin('second_product_groups as second', 'second.id', '=', 'third.second_product_group_id')
            ->where('p.id', $productId)
            ->first(['second.first_product_group_id']);

        return (int) ($row->first_product_group_id ?? 0);
    }

    /**
     * 写进台账 `policy_snapshot` 的完整解释：当时生效的策略 + 怎么算出来的 + 命中了什么。
     *
     * 没有它，事后没人能回答「当时为什么这台能退、金额怎么算的」——策略是可改配置。
     *
     * @param  array<string, mixed>  $computed
     * @return array<string, mixed>
     */
    private function snapshot(
        Service $service,
        ?AutoRefundProductRule $rule,
        string $requirement,
        int $windowHours,
        bool $delegates,
        array $computed,
    ): array {
        return [
            'evaluated_at' => now()->format('Y-m-d H:i:s'),
            'product_id' => (int) $service->product_id,
            'service_id' => (int) $service->id,
            'rule_id' => $rule?->id === null ? null : (int) $rule->id,
            'configured' => $rule instanceof AutoRefundProductRule,
            'refund_type' => $rule?->refundType(),
            'refund_rule' => $rule?->refundRule(),
            'requirement' => $requirement,
            'window_hours' => $windowHours,
            'requirement_delegated_to_upstream' => $delegates,
            'resolved' => $computed,
        ];
    }
}
