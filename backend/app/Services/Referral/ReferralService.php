<?php

declare(strict_types=1);

namespace App\Services\Referral;

use App\Constants\InvoiceStatus;
use App\Constants\OrderStatus;
use App\Exceptions\BusinessException;
use App\Models\AccountTransaction;
use App\Models\Invoice;
use App\Models\MemberLevel;
use App\Models\Order;
use App\Models\Product;
use App\Models\ReferralReward;
use App\Models\ReferralWithdrawal;
use App\Models\Setting;
use App\Models\User;
use App\Models\UserAccount;
use App\Models\UserReferral;
use App\Services\Finance\InvoiceService;
use App\Services\ProductCatalog\ProductDisplayNameResolver;
use App\Services\System\OperationLogService;
use App\Services\User\AccountService;
use App\Support\AdminPrivacy;
use App\Support\PublicUrl;
use App\Support\TextSanitizer;
use Carbon\CarbonInterface;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

class ReferralService
{
    private const DEFAULT_REWARD_RATE = 10.00;

    /**
     * 推广奖励相关退款阻断错误码。
     * 用于区分"奖励已提现/可提余额不足"等需人工处理的场景，
     * 避免管理员只看到通用文案而无法识别阻断原因。
     */
    public const REFUND_BLOCKED_REFERRER_MISSING_CODE = 42210;

    public const REFUND_BLOCKED_REWARD_WITHDRAWN_CODE = 42211;

    public const REFUND_BLOCKED_FROZEN_INSUFFICIENT_CODE = 42212;

    public const ACCOUNT_LOG_TYPE_REWARD_FROZEN = 'reward_frozen';

    public const ACCOUNT_LOG_TYPE_REWARD_RELEASED = 'reward_released';

    public const ACCOUNT_LOG_TYPE_REWARD_REVERSED = 'reward_reversed';

    public const ACCOUNT_LOG_TYPE_WITHDRAW_APPLY = 'withdraw_apply';

    public const ACCOUNT_LOG_TYPE_WITHDRAW_APPROVED = 'withdraw_approved';

    public const ACCOUNT_LOG_TYPE_WITHDRAW_REJECTED = 'withdraw_rejected';

    private const ACCOUNT_LOG_EVENT_TYPES = [
        self::ACCOUNT_LOG_TYPE_REWARD_FROZEN,
        self::ACCOUNT_LOG_TYPE_REWARD_RELEASED,
        self::ACCOUNT_LOG_TYPE_REWARD_REVERSED,
        self::ACCOUNT_LOG_TYPE_WITHDRAW_APPLY,
        self::ACCOUNT_LOG_TYPE_WITHDRAW_APPROVED,
        self::ACCOUNT_LOG_TYPE_WITHDRAW_REJECTED,
    ];

    public function __construct(
        private MemberLevelService $memberLevelService,
        private OperationLogService $operationLogService,
        private InvoiceService $invoiceService,
        private readonly ?ProductDisplayNameResolver $productDisplayNameResolver = null,
        private ?AccountService $accountService = null,
    ) {}

    public function ensureReferralCode(User $user): string
    {
        $currentUserCode = $this->normalizeReferralCode($user->getRawOriginal('referral_code') ?? $user->referral_code);

        if (! $this->hasReferralProfilesTable()) {
            $resolvedCode = $this->isReusableReferralCode($currentUserCode)
                ? $currentUserCode
                : $this->generateUniqueReferralCode();

            if ($resolvedCode !== $currentUserCode) {
                $user->forceFill([
                    'referral_code' => $resolvedCode,
                ])->save();
            }

            return $resolvedCode;
        }

        $profile = $this->ensureReferralProfile($user, $currentUserCode);
        $profileCode = $this->normalizeReferralCode($profile->getRawOriginal('referral_code') ?? $profile->referral_code);
        $code = $this->isReusableReferralCode($profileCode)
            ? $profileCode
            : ($this->isReusableReferralCode($currentUserCode) ? $currentUserCode : $this->generateUniqueReferralCode());

        if ($profileCode !== $code) {
            $profile->forceFill([
                'referral_code' => $code,
            ])->save();
        }

        if ($currentUserCode !== $code) {
            $user->forceFill([
                'referral_code' => $code,
            ])->save();
        }

        $this->syncUserFromReferralProfile($user, $profile);
        $this->resetUserAggregateRelations($user);

        return $code;
    }

    public function bindReferrer(User $user, ?string $referralCode, array $context = []): void
    {
        if (! $this->referralEnabled()) {
            return;
        }

        $referralCode = strtoupper(trim((string) $referralCode));
        $userProfile = $this->hasReferralProfilesTable() ? $this->ensureReferralProfile($user) : null;
        $currentReferrerUserId = $userProfile?->referrer_user_id ?? $user->referrer_user_id;

        if ($referralCode === '' || $currentReferrerUserId) {
            return;
        }

        $referrer = $this->findReferrerByCode($referralCode, (int) $user->id);

        if (! $referrer || (int) $referrer->id === (int) $user->id) {
            return;
        }

        $requestIp = trim((string) ($context['ip'] ?? ''));
        if ($requestIp !== '' && trim((string) $referrer->last_login_ip) !== '' && trim((string) $referrer->last_login_ip) === $requestIp) {
            $this->operationLogService->write(
                userId: $user->id,
                userType: 'client',
                action: 'referral.bind.blocked_same_ip',
                module: 'referral',
                targetId: $referrer->id,
                detail: [
                    'referral_code' => $referralCode,
                    'ip' => $requestIp,
                ],
                ipAddress: $requestIp,
            );

            return;
        }

        $this->ensureReferralCode($referrer);
        $this->ensureUserLevel($referrer);

        if ($userProfile) {
            $userProfile->forceFill([
                'referrer_user_id' => $referrer->id,
                'referred_at' => now(),
            ])->save();

            $this->syncUserFromReferralProfile($user, $userProfile);
        } else {
            $user->forceFill([
                'referrer_user_id' => $referrer->id,
                'referred_at' => now(),
            ])->save();
        }

        $this->resetUserAggregateRelations($user);
    }

    public function rewardForPaidOrder(Order $order, ?string $traceId = null): ?ReferralReward
    {
        if (! $this->referralEnabled()) {
            return null;
        }

        if ($order->type !== 'new') {
            return null;
        }

        if (! in_array((int) $order->status, [OrderStatus::PAID, OrderStatus::COMPLETED, OrderStatus::PROCESSING], true)) {
            return null;
        }

        $order->loadMissing(['user', 'product']);
        $buyer = $order->user;
        $referrerUserId = $buyer ? $this->resolveBuyerReferrerUserId($buyer) : null;

        if (! $buyer || ! $referrerUserId) {
            return null;
        }

        $referrer = User::query()->find((int) $referrerUserId);
        if (! $referrer) {
            return null;
        }

        $lockKey = "lock:referral:reward:order:{$order->id}";

        return Cache::lock($lockKey, 30)->block(5, function () use ($order, $buyer, $referrer, $traceId) {
            $existing = ReferralReward::query()->where('order_id', $order->id)->first();
            if ($existing) {
                return $existing;
            }

            $orderAmount = round((float) ($order->paid_amount ?: $order->amount), 2);
            if ($orderAmount <= 0) {
                return null;
            }

            return DB::transaction(function () use ($order, $buyer, $referrer, $traceId, $orderAmount) {
                $lockedReferrer = User::query()->lockForUpdate()->findOrFail($referrer->id);
                $referrerProfile = $this->hasReferralProfilesTable()
                    ? $this->lockReferralProfile($lockedReferrer->id)
                    : null;
                $referrerAccount = $this->lockUserAccount($lockedReferrer->id);
                $currentSalesAmount = $referrerProfile
                    ? (float) $referrerProfile->total_sales_amount
                    : (float) $lockedReferrer->total_sales_amount;
                $nextSalesAmount = round($currentSalesAmount + $orderAmount, 2);
                $level = $this->memberLevelService->resolveLevelBySales($nextSalesAmount);
                $rewardRate = $level
                    ? round((float) $level->reward_rate, 2)
                    : $this->rewardRate();
                $rewardAmount = round($orderAmount * $rewardRate / 100, 2);

                if ($rewardAmount <= 0) {
                    return null;
                }

                if ($referrerProfile) {
                    $referrerProfile->forceFill([
                        'total_sales_amount' => $nextSalesAmount,
                        'member_level_id' => $level?->id,
                    ])->save();
                    $this->syncUserFromReferralProfile($lockedReferrer, $referrerProfile);
                } else {
                    $lockedReferrer->forceFill([
                        'total_sales_amount' => number_format($nextSalesAmount, 2, '.', ''),
                        'member_level_id' => $level?->id,
                    ])->save();
                }

                $referrerAccount = $this->accounts()->updateAccount($referrerAccount, [
                    'referral_frozen_balance' => round((float) $referrerAccount->referral_frozen_balance + $rewardAmount, 2),
                ]);

                $this->resetUserAggregateRelations($lockedReferrer);

                $this->writeAccountLog(
                    user: $lockedReferrer,
                    type: self::ACCOUNT_LOG_TYPE_REWARD_FROZEN,
                    amount: $rewardAmount,
                    remark: "推荐奖励冻结，来源订单 {$order->order_no}",
                    relatedId: $order->id,
                    relatedType: 'order',
                    operator: 'system',
                    traceId: $traceId,
                    balances: $this->buildReferralBalanceSnapshot($referrerAccount),
                );

                $this->operationLogService->write(
                    userId: $lockedReferrer->id,
                    userType: 'client',
                    action: 'referral.reward.frozen',
                    module: 'referral',
                    targetId: $order->id,
                    detail: [
                        'referred_user_id' => $buyer->id,
                        'order_no' => $order->order_no,
                        'order_amount' => $orderAmount,
                        'reward_rate' => $rewardRate,
                        'reward_amount' => $rewardAmount,
                        'member_level' => $level?->name,
                    ],
                );

                return ReferralReward::query()->create([
                    'referrer_user_id' => $lockedReferrer->id,
                    'referred_user_id' => $buyer->id,
                    'order_id' => $order->id,
                    'product_id' => $order->product_id,
                    'order_amount' => $orderAmount,
                    'reward_rate' => $rewardRate,
                    'reward_amount' => $rewardAmount,
                    'status' => ReferralReward::STATUS_FROZEN,
                    'available_at' => now()->addDays($this->rewardFreezeDays()),
                    'operator' => 'system',
                    'remark' => '下级用户成功购买产品后进入冻结期',
                    'trace_id' => $traceId,
                    'rewarded_at' => now(),
                ]);
            });
        });
    }

    /**
     * 基于账单发放推荐奖励（无订单场景）
     */
    public function rewardForPaidInvoice(Invoice $invoice, ?string $traceId = null): ?ReferralReward
    {
        if (! $this->referralEnabled()) {
            return null;
        }

        if (! in_array((string) $invoice->type, ['normal', 'new'], true)) {
            return null;
        }

        if ((int) $invoice->status !== InvoiceStatus::PAID) {
            return null;
        }

        $invoice->loadMissing(['user', 'product']);
        $buyer = $invoice->user;
        $referrerUserId = $buyer ? $this->resolveBuyerReferrerUserId($buyer) : null;

        if (! $buyer || ! $referrerUserId) {
            return null;
        }

        $referrer = User::query()->find((int) $referrerUserId);
        if (! $referrer) {
            return null;
        }

        $lockKey = "lock:referral:reward:invoice:{$invoice->id}";

        return Cache::lock($lockKey, 30)->block(5, function () use ($invoice, $buyer, $referrer, $traceId) {
            $existing = ReferralReward::query()->where('invoice_id', $invoice->id)->first();
            if ($existing) {
                return $existing;
            }

            $invoiceAmount = round((float) ($invoice->paid_amount ?: $invoice->amount), 2);
            if ($invoiceAmount <= 0) {
                return null;
            }

            return DB::transaction(function () use ($invoice, $buyer, $referrer, $traceId, $invoiceAmount) {
                $lockedReferrer = User::query()->lockForUpdate()->findOrFail($referrer->id);
                $referrerProfile = $this->hasReferralProfilesTable()
                    ? $this->lockReferralProfile($lockedReferrer->id)
                    : null;
                $referrerAccount = $this->lockUserAccount($lockedReferrer->id);
                $currentSalesAmount = $referrerProfile
                    ? (float) $referrerProfile->total_sales_amount
                    : (float) $lockedReferrer->total_sales_amount;
                $nextSalesAmount = round($currentSalesAmount + $invoiceAmount, 2);
                $level = $this->memberLevelService->resolveLevelBySales($nextSalesAmount);
                $rewardRate = $level
                    ? round((float) $level->reward_rate, 2)
                    : $this->rewardRate();
                $rewardAmount = round($invoiceAmount * $rewardRate / 100, 2);

                if ($rewardAmount <= 0) {
                    return null;
                }

                if ($referrerProfile) {
                    $referrerProfile->forceFill([
                        'total_sales_amount' => $nextSalesAmount,
                        'member_level_id' => $level?->id,
                    ])->save();
                    $this->syncUserFromReferralProfile($lockedReferrer, $referrerProfile);
                } else {
                    $lockedReferrer->forceFill([
                        'total_sales_amount' => number_format($nextSalesAmount, 2, '.', ''),
                        'member_level_id' => $level?->id,
                    ])->save();
                }

                $referrerAccount = $this->accounts()->updateAccount($referrerAccount, [
                    'referral_frozen_balance' => round((float) $referrerAccount->referral_frozen_balance + $rewardAmount, 2),
                ]);

                $this->resetUserAggregateRelations($lockedReferrer);

                $invoiceNo = (string) ($invoice->invoice_no ?? '');

                $this->writeAccountLog(
                    user: $lockedReferrer,
                    type: self::ACCOUNT_LOG_TYPE_REWARD_FROZEN,
                    amount: $rewardAmount,
                    remark: "推荐奖励冻结，来源账单 {$invoiceNo}",
                    relatedId: $invoice->id,
                    relatedType: 'invoice',
                    operator: 'system',
                    traceId: $traceId,
                    balances: $this->buildReferralBalanceSnapshot($referrerAccount),
                );

                $this->operationLogService->write(
                    userId: $lockedReferrer->id,
                    userType: 'client',
                    action: 'referral.reward.frozen',
                    module: 'referral',
                    targetId: $invoice->id,
                    detail: [
                        'referred_user_id' => $buyer->id,
                        'invoice_no' => $invoiceNo,
                        'invoice_amount' => $invoiceAmount,
                        'reward_rate' => $rewardRate,
                        'reward_amount' => $rewardAmount,
                        'member_level' => $level?->name,
                    ],
                );

                return ReferralReward::query()->create([
                    'referrer_user_id' => $lockedReferrer->id,
                    'referred_user_id' => $buyer->id,
                    'invoice_id' => $invoice->id,
                    'product_id' => $invoice->product_id,
                    'order_amount' => $invoiceAmount,
                    'reward_rate' => $rewardRate,
                    'reward_amount' => $rewardAmount,
                    'status' => ReferralReward::STATUS_FROZEN,
                    'available_at' => now()->addDays($this->rewardFreezeDays()),
                    'operator' => 'system',
                    'remark' => '下级用户成功购买产品后进入冻结期',
                    'trace_id' => $traceId,
                    'rewarded_at' => now(),
                ]);
            });
        });
    }

    public function overview(User $user, string $origin): array
    {
        $this->releaseMaturedRewards($user);
        $code = $this->ensureReferralCode($user);
        $frontendBaseUrl = $this->resolveFrontendBaseUrl($origin);
        $user = $this->ensureUserLevel($user)->loadMissing(['memberLevel']);

        $levels = $this->memberLevelService->list(true);
        $currentLevel = $user->memberLevel;
        $nextLevel = $levels->first(function (MemberLevel $level) use ($user) {
            return (float) $level->sales_amount_min > (float) $user->total_sales_amount;
        });

        $summary = ReferralReward::query()
            ->where('referrer_user_id', $user->id)
            ->selectRaw('COUNT(*) as rewarded_orders_count')
            ->selectRaw('COALESCE(SUM(reward_amount), 0) as total_reward_amount')
            ->first();

        $directReferralCount = $this->directReferralCount((int) $user->id);
        $recentReferrals = $this->recentDirectReferrals((int) $user->id, 8, true);

        return [
            'referral_code' => $code,
            'register_path' => '/client/register?ref='.$code,
            'referral_link' => $frontendBaseUrl.'/client/register?ref='.$code,
            'reward_rate' => $currentLevel
                ? number_format((float) $currentLevel->reward_rate, 2, '.', '')
                : number_format($this->rewardRate(), 2, '.', ''),
            'reward_freeze_days' => $this->rewardFreezeDays(),
            'withdraw_min_amount' => number_format($this->withdrawMinAmount(), 2, '.', ''),
            'current_member_level' => $currentLevel ? [
                'id' => $currentLevel->id,
                'name' => $currentLevel->name,
                'code' => $currentLevel->code,
                'reward_rate' => number_format((float) $currentLevel->reward_rate, 2, '.', ''),
            ] : null,
            'next_member_level' => $nextLevel ? [
                'id' => $nextLevel->id,
                'name' => $nextLevel->name,
                'code' => $nextLevel->code,
                'reward_rate' => number_format((float) $nextLevel->reward_rate, 2, '.', ''),
                'sales_amount_min' => number_format((float) $nextLevel->sales_amount_min, 2, '.', ''),
                'distance_amount' => number_format(max((float) $nextLevel->sales_amount_min - (float) $user->total_sales_amount, 0), 2, '.', ''),
            ] : null,
            'member_levels' => $levels->map(fn (MemberLevel $level) => [
                'id' => $level->id,
                'name' => $level->name,
                'code' => $level->code,
                'reward_rate' => number_format((float) $level->reward_rate, 2, '.', ''),
                'sales_amount_min' => number_format((float) $level->sales_amount_min, 2, '.', ''),
                'sales_amount_max' => $level->sales_amount_max !== null
                    ? number_format((float) $level->sales_amount_max, 2, '.', '')
                    : null,
            ])->values()->all(),
            'total_sales_amount' => number_format((float) $user->total_sales_amount, 2, '.', ''),
            'referral_frozen_amount' => number_format((float) $user->referral_frozen_amount, 2, '.', ''),
            'referral_available_amount' => number_format((float) $user->referral_available_amount, 2, '.', ''),
            'referral_withdrawing_amount' => number_format((float) $user->referral_withdrawing_amount, 2, '.', ''),
            'referral_withdrawn_amount' => number_format((float) $user->referral_withdrawn_amount, 2, '.', ''),
            'direct_referral_count' => $directReferralCount,
            'rewarded_orders_count' => (int) ($summary?->rewarded_orders_count ?? 0),
            'total_reward_amount' => number_format((float) ($summary?->total_reward_amount ?? 0), 2, '.', ''),
            'recent_referrals' => $recentReferrals
                ->map(function (User $referredUser) {
                    return [
                        'id' => $referredUser->id,
                        'email' => $referredUser->email,
                        'nickname' => $referredUser->nickname,
                        'display_name' => $referredUser->display_name,
                        'created_at' => $referredUser->created_at?->format('Y-m-d H:i:s'),
                        'referred_at' => $referredUser->referred_at?->format('Y-m-d H:i:s'),
                    ];
                })
                ->filter()
                ->values()
                ->all(),
        ];
    }

    public function directReferralCount(int $referrerUserId): int
    {
        if ($referrerUserId <= 0) {
            return 0;
        }

        return $this->buildDirectReferralUserQuery($referrerUserId)->count();
    }

    /**
     * @return Collection<int, User>
     */
    public function recentDirectReferrals(int $referrerUserId, int $limit = 8, bool $withReadAggregates = false): Collection
    {
        if ($referrerUserId <= 0 || $limit <= 0) {
            return new Collection;
        }

        return $this->buildDirectReferralUserQuery($referrerUserId, $withReadAggregates)
            ->orderByDesc('referred_at')
            ->orderByDesc('users.id')
            ->limit($limit)
            ->get();
    }

    public function rewardLogs(User $user, int $perPage = 15): LengthAwarePaginator
    {
        $this->releaseMaturedRewards($user);

        return ReferralReward::query()
            ->with(array_merge(
                $this->referralUserWithRelations('referredUser'),
                [
                    'invoice:id,invoice_no,product_id,product_spec_snapshot,config_snapshot,paid_at',
                    'order:id,order_no,product_id,product_spec_snapshot,config_snapshot,paid_at',
                    'product:id,product_type,service_type_code,product_group_id,config_options,purchase_requires',
                ]
            ))
            ->where('referrer_user_id', $user->id)
            ->orderByDesc('rewarded_at')
            ->orderByDesc('id')
            ->paginate($perPage);
    }

    public function accountLogs(User $user, array $filters, int $perPage = 20): LengthAwarePaginator
    {
        $query = $this->buildReferralAccountLogQuery($user);

        if (! empty($filters['event_type'])) {
            $query->where('event_type', (string) $filters['event_type']);
        }

        return $query
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->paginate($perPage);
    }

    public function withdrawalLogs(User $user, int $perPage = 15): LengthAwarePaginator
    {
        $this->releaseMaturedRewards($user);

        return ReferralWithdrawal::query()
            ->with($this->referralUserWithRelations('user'))
            ->where('user_id', $user->id)
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->paginate($perPage);
    }

    public function createWithdrawal(User $user, array $data, ?string $traceId = null): ReferralWithdrawal
    {
        $this->releaseMaturedRewards($user);
        $user = User::query()->findOrFail($user->id);

        $amount = round((float) ($data['amount'] ?? 0), 2);
        $minAmount = $this->withdrawMinAmount();

        // 提现涉及资金外流，必须先完成实名认证（反洗钱/合规底线）。
        // is_verified / verification_status 字段已存在，此处对齐 hasCompletedVerification 判断。
        throw_if(! $user->hasCompletedVerification(), new BusinessException('请先完成实名认证后再申请提现'));

        throw_if($amount <= 0, new BusinessException('提现金额必须大于 0'));
        throw_if($amount < $minAmount, new BusinessException("奖励满 {$minAmount} 元才可提现"));
        throw_if((float) $user->referral_available_amount < $amount, new BusinessException('可提现奖励不足'));
        throw_if(
            ReferralWithdrawal::query()
                ->where('user_id', $user->id)
                ->where('status', ReferralWithdrawal::STATUS_PENDING)
                ->exists(),
            new BusinessException('已有待处理提现申请，请等待审核完成'),
        );

        $method = trim((string) ($data['method'] ?? ReferralWithdrawal::METHOD_ALIPAY)) ?: ReferralWithdrawal::METHOD_ALIPAY;
        throw_if(
            ! in_array($method, [ReferralWithdrawal::METHOD_BALANCE, ReferralWithdrawal::METHOD_ALIPAY], true),
            new BusinessException('不支持的提现方式'),
        );

        $accountName = '';
        $accountNo = '';

        if ($method === ReferralWithdrawal::METHOD_ALIPAY) {
            $accountName = TextSanitizer::clean((string) $user->alipay_real_name);
            $accountNo = trim((string) $user->alipay_account);

            throw_if($accountName === '' || $accountNo === '', new BusinessException('请先绑定支付宝'));
        }

        return DB::transaction(function () use ($user, $traceId, $amount, $method, $accountName, $accountNo, $data) {
            $lockedUser = User::query()->lockForUpdate()->findOrFail($user->id);
            $account = $this->lockUserAccount($lockedUser->id);

            throw_if((float) $account->referral_available_balance < $amount, new BusinessException('可提现奖励不足'));

            $account = $this->accounts()->updateAccount($account, [
                'referral_available_balance' => round((float) $account->referral_available_balance - $amount, 2),
                'referral_pending_withdrawal_balance' => round((float) $account->referral_pending_withdrawal_balance + $amount, 2),
            ]);

            $this->resetUserAggregateRelations($lockedUser);

            $this->writeAccountLog(
                user: $lockedUser,
                type: self::ACCOUNT_LOG_TYPE_WITHDRAW_APPLY,
                amount: -$amount,
                remark: '推荐奖励提现申请，金额已转入提现中',
                relatedType: 'withdrawal',
                operator: 'client',
                traceId: $traceId,
                balances: $this->buildReferralBalanceSnapshot($account),
            );

            $record = ReferralWithdrawal::query()->create([
                'user_id' => $lockedUser->id,
                'amount' => $amount,
                'method' => $method,
                'account_name' => $accountName,
                'account_no' => $accountNo,
                'status' => ReferralWithdrawal::STATUS_PENDING,
                'remark' => TextSanitizer::clean((string) ($data['remark'] ?? '')) ?: '推荐奖励提现申请',
                'operator' => 'client',
                'trace_id' => $traceId,
            ]);

            $this->operationLogService->write(
                userId: $lockedUser->id,
                userType: 'client',
                action: 'referral.withdraw.apply',
                module: 'referral_withdrawal',
                targetId: $record->id,
                detail: [
                    'amount' => $amount,
                    'method' => $record->method,
                    'account_name' => $record->account_name,
                    'account_no_masked' => $this->maskAccountNo($record->account_no),
                ],
            );

            return $record;
        });
    }

    public function adminRewardLogs(array $filters, int $perPage = 20): LengthAwarePaginator
    {
        $query = ReferralReward::query()
            ->with(array_merge(
                $this->referralUserWithRelations('referrer'),
                $this->referralUserWithRelations('referredUser'),
                [
                    'order:id,order_no,product_id,product_spec_snapshot,config_snapshot',
                    'product:id,product_type,service_type_code,product_group_id,config_options,purchase_requires',
                ]
            ));

        if (($filters['status'] ?? null) !== null && $filters['status'] !== '') {
            $query->where('status', (int) $filters['status']);
        }

        if (! empty($filters['keyword'])) {
            $keyword = trim((string) $filters['keyword']);
            $matchedUserIds = $this->resolveAdminReferralUserIdsByKeyword($keyword);
            $matchedOrderIds = $this->resolveOrderIdsByKeyword($keyword);
            $matchedProductIds = $this->resolveProductIdsByKeyword($keyword);

            $query->where(function (Builder $builder) use ($matchedUserIds, $matchedOrderIds, $matchedProductIds) {
                $hasCondition = false;

                if ($matchedUserIds !== []) {
                    $builder
                        ->whereIn('referrer_user_id', $matchedUserIds)
                        ->orWhereIn('referred_user_id', $matchedUserIds);
                    $hasCondition = true;
                }

                if ($matchedOrderIds !== []) {
                    if (! $hasCondition) {
                        $builder->whereIn('order_id', $matchedOrderIds);
                    } else {
                        $builder->orWhereIn('order_id', $matchedOrderIds);
                    }

                    $hasCondition = true;
                }

                if ($matchedProductIds !== []) {
                    if (! $hasCondition) {
                        $builder->whereIn('product_id', $matchedProductIds);
                    } else {
                        $builder->orWhereIn('product_id', $matchedProductIds);
                    }

                    $hasCondition = true;
                }

                if (! $hasCondition) {
                    $builder->whereRaw('1 = 0');
                }
            });
        }

        return $query->orderByDesc('rewarded_at')->orderByDesc('id')->paginate($perPage);
    }

    public function resolveRewardProductDisplayName(ReferralReward $reward): string
    {
        $orderDisplayName = trim((string) ($reward->order?->display_product_name ?? ''));
        if ($orderDisplayName !== '') {
            return $orderDisplayName;
        }

        if ($reward->product instanceof Product) {
            $resolved = $this->resolveProductDisplayNameResolver()->resolveForProduct(
                $reward->product,
                (array) ($reward->order?->config_snapshot ?? [])
            );

            return trim((string) ($resolved['product_display_name'] ?? ''));
        }

        return '';
    }

    public function adminAccountLogs(array $filters, int $perPage = 20): LengthAwarePaginator
    {
        $query = AccountTransaction::query()
            ->with([
                'user:id,email,phone,nickname,real_name,verification_status,is_verified',
            ])
            ->whereIn('event_type', self::ACCOUNT_LOG_EVENT_TYPES);

        if (! empty($filters['event_type'])) {
            $query->where('event_type', (string) $filters['event_type']);
        }

        if (! empty($filters['keyword'])) {
            $keyword = trim((string) $filters['keyword']);
            $matchedUserIds = $this->resolveAdminReferralUserIdsByKeyword($keyword);

            $query->where(function (Builder $builder) use ($keyword, $matchedUserIds) {
                $builder
                    ->where('remark', 'like', "%{$keyword}%")
                    ->when($matchedUserIds !== [], fn (Builder $query) => $query->orWhereIn('user_id', $matchedUserIds));
            });
        }

        return $query
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->paginate($perPage);
    }

    public function adminWithdrawalList(array $filters, int $perPage = 20): LengthAwarePaginator
    {
        $query = ReferralWithdrawal::query()
            ->with($this->referralUserWithRelations('user'));

        if (($filters['status'] ?? null) !== null && $filters['status'] !== '') {
            $query->where('status', (int) $filters['status']);
        }

        if (! empty($filters['keyword'])) {
            $keyword = trim((string) $filters['keyword']);
            $matchedUserIds = $this->resolveAdminReferralUserIdsByKeyword($keyword);

            $query->where(function (Builder $builder) use ($keyword, $matchedUserIds) {
                $builder
                    ->where('account_name', 'like', "%{$keyword}%")
                    ->orWhere('account_no', 'like', "%{$keyword}%")
                    ->when($matchedUserIds !== [], fn (Builder $query) => $query->orWhereIn('user_id', $matchedUserIds));
            });
        }

        return $query
            ->orderByRaw('CASE WHEN status = 0 THEN 0 ELSE 1 END')
            ->orderByDesc('created_at')
            ->paginate($perPage);
    }

    /**
     * @return array<string, mixed>
     */
    public function adminRewardProjection(ReferralReward $item, ?AdminPrivacy $privacy = null): array
    {
        $privacy ??= AdminPrivacy::current();

        return [
            'id' => (int) $item->id,
            'status' => (int) $item->status,
            'order_amount' => number_format((float) $item->order_amount, 2, '.', ''),
            'reward_rate' => number_format((float) $item->reward_rate, 2, '.', ''),
            'reward_amount' => number_format((float) $item->reward_amount, 2, '.', ''),
            'available_at' => $item->available_at?->format('Y-m-d H:i:s'),
            'released_at' => $item->released_at?->format('Y-m-d H:i:s'),
            'rewarded_at' => $item->rewarded_at?->format('Y-m-d H:i:s'),
            'remark' => $item->remark,
            'referrer' => $this->adminReferralUserProjection($item->referrer, $privacy),
            'referred_user' => $this->adminReferralUserProjection($item->referredUser, $privacy),
            'order' => $item->order ? [
                'id' => (int) $item->order->id,
                'order_no' => (string) $item->order->order_no,
                'product_display_name' => $this->resolveRewardProductDisplayName($item),
            ] : null,
            'product' => $item->product ? [
                'id' => (int) $item->product->id,
                'name' => (string) $item->product->name,
                'display_name' => $this->resolveRewardProductDisplayName($item),
            ] : null,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function adminWithdrawalProjection(ReferralWithdrawal $item, ?AdminPrivacy $privacy = null): array
    {
        $privacy ??= AdminPrivacy::current();

        return [
            'id' => (int) $item->id,
            'amount' => number_format((float) $item->amount, 2, '.', ''),
            'method' => (string) $item->method,
            'account_name' => $privacy->name($item->account_name_display),
            'account_no' => $privacy->account($item->account_no),
            'status' => (int) $item->status,
            'remark' => $item->remark,
            'operator' => $item->operator,
            'created_at' => $item->created_at?->format('Y-m-d H:i:s'),
            'processed_at' => $item->processed_at?->format('Y-m-d H:i:s'),
            'user' => $this->adminReferralUserProjection($item->user, $privacy),
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function adminReferralUserProjection(?User $user, AdminPrivacy $privacy): ?array
    {
        if (! $user) {
            return null;
        }

        return [
            'id' => (int) $user->id,
            'email' => $privacy->email($user->email),
            'nickname' => (string) $user->nickname,
            'display_name' => $privacy->displayName($user->display_name, $user->email, $user->phone, $user->real_name),
        ];
    }

    public function assertOrderRewardRefundable(Order $order): void
    {
        $reward = ReferralReward::query()
            ->where('order_id', $order->id)
            ->first();

        if (! $reward || (int) $reward->status !== ReferralReward::STATUS_REWARDED) {
            return;
        }

        $referrer = User::query()
            ->withReadAggregates()
            ->find((int) $reward->referrer_user_id);

        if (! $referrer) {
            throw new BusinessException(
                '该订单已产生推广奖励，但推荐人账户不存在，无法继续退款。',
                self::REFUND_BLOCKED_REFERRER_MISSING_CODE
            );
        }

        $rewardAmount = round((float) $reward->reward_amount, 2);
        $availableAmount = round((float) $referrer->referral_available_amount, 2);

        if ($availableAmount + 0.00001 < $rewardAmount) {
            // 命中"奖励已释放但可提余额不足"（典型为佣金已被提现）时，
            // 记一条阻断审计日志，便于管理员事后人工追回提现款或走挂账流程。
            $this->operationLogService->write(
                userId: (int) $referrer->id,
                userType: 'client',
                action: 'referral.reward.refund_blocked',
                module: 'referral',
                targetId: (int) $order->id,
                detail: [
                    'order_id' => (int) $order->id,
                    'referrer_user_id' => (int) $referrer->id,
                    'reward_amount' => number_format($rewardAmount, 2, '.', ''),
                    'available_balance' => number_format($availableAmount, 2, '.', ''),
                    'reason' => 'reward_released_but_available_insufficient',
                ],
            );

            throw new BusinessException(
                '该订单对应的推广奖励已释放且可提余额不足（可能已被提现）。需先追回推广返利资金或走人工挂账流程后再发起退款。错误码 42211。',
                self::REFUND_BLOCKED_REWARD_WITHDRAWN_CODE
            );
        }
    }

    public function reverseRewardForRefundedOrder(Order $order, ?string $traceId = null): ?ReferralReward
    {
        $lockKey = "lock:referral:reward:reverse:order:{$order->id}";

        return Cache::lock($lockKey, 30)->block(5, function () use ($order, $traceId) {
            return DB::transaction(function () use ($order, $traceId) {
                $reward = ReferralReward::query()
                    ->lockForUpdate()
                    ->where('order_id', $order->id)
                    ->first();

                if (! $reward || (int) $reward->status === ReferralReward::STATUS_REVERSED) {
                    return $reward;
                }

                if (! in_array((int) $reward->status, [ReferralReward::STATUS_FROZEN, ReferralReward::STATUS_REWARDED], true)) {
                    return $reward;
                }

                $referrer = User::query()
                    ->lockForUpdate()
                    ->find((int) $reward->referrer_user_id);

                if (! $referrer) {
                    throw new BusinessException(
                        '该订单已产生推广奖励，但推荐人账户不存在，无法继续退款。',
                        self::REFUND_BLOCKED_REFERRER_MISSING_CODE
                    );
                }

                $referrerProfile = $this->hasReferralProfilesTable()
                    ? $this->lockReferralProfile((int) $referrer->id)
                    : null;
                $referrerAccount = $this->lockUserAccount((int) $referrer->id);
                $rewardAmount = round((float) $reward->reward_amount, 2);
                $orderAmount = round((float) $reward->order_amount, 2);
                $accountType = 'referral_frozen';

                if ((int) $reward->status === ReferralReward::STATUS_FROZEN) {
                    throw_if(
                        (float) $referrerAccount->referral_frozen_balance + 0.00001 < $rewardAmount,
                        new BusinessException(
                            '推广冻结奖励余额不足，无法继续退款。错误码 42212。',
                            self::REFUND_BLOCKED_FROZEN_INSUFFICIENT_CODE
                        ),
                    );

                    $referrerAccount = $this->accounts()->updateAccount($referrerAccount, [
                        'referral_frozen_balance' => max(round((float) $referrerAccount->referral_frozen_balance - $rewardAmount, 2), 0),
                    ]);
                } else {
                    throw_if(
                        (float) $referrerAccount->referral_available_balance + 0.00001 < $rewardAmount,
                        new BusinessException(
                            '该订单对应的推广奖励已释放且可提余额不足（可能已被提现）。需先追回推广返利资金或走人工挂账流程后再发起退款。错误码 42211。',
                            self::REFUND_BLOCKED_REWARD_WITHDRAWN_CODE
                        ),
                    );

                    $referrerAccount = $this->accounts()->updateAccount($referrerAccount, [
                        'referral_available_balance' => max(round((float) $referrerAccount->referral_available_balance - $rewardAmount, 2), 0),
                    ]);

                    $accountType = 'referral_available';
                }

                $currentSalesAmount = $referrerProfile
                    ? (float) $referrerProfile->total_sales_amount
                    : (float) $referrer->total_sales_amount;
                $nextSalesAmount = max(round($currentSalesAmount - $orderAmount, 2), 0);
                $level = $this->memberLevelService->resolveLevelBySales($nextSalesAmount);

                if ($referrerProfile) {
                    $referrerProfile->forceFill([
                        'total_sales_amount' => $nextSalesAmount,
                        'member_level_id' => $level?->id,
                    ])->save();
                    $this->syncUserFromReferralProfile($referrer, $referrerProfile);
                } else {
                    $referrer->forceFill([
                        'total_sales_amount' => number_format($nextSalesAmount, 2, '.', ''),
                        'member_level_id' => $level?->id,
                    ])->save();
                }
                $this->resetUserAggregateRelations($referrer);

                $reward->forceFill([
                    'status' => ReferralReward::STATUS_REVERSED,
                    'remark' => '订单退款，推广奖励已撤销',
                    'trace_id' => $traceId ?: $reward->trace_id,
                ])->save();

                $this->writeAccountLog(
                    user: $referrer,
                    type: self::ACCOUNT_LOG_TYPE_REWARD_REVERSED,
                    amount: -$rewardAmount,
                    remark: "订单退款，推广奖励已撤销 #{$order->order_no}",
                    relatedId: (int) $reward->id,
                    relatedType: 'reward',
                    operator: 'system',
                    traceId: $traceId ?: $reward->trace_id,
                    balances: $this->buildReferralBalanceSnapshot($referrerAccount),
                    accountTypeOverride: $accountType,
                );

                $this->operationLogService->write(
                    userId: $referrer->id,
                    userType: 'system',
                    action: 'referral.reward.reversed',
                    module: 'referral',
                    targetId: $reward->id,
                    detail: [
                        'order_id' => (int) $order->id,
                        'order_no' => (string) $order->order_no,
                        'reward_amount' => $rewardAmount,
                        'order_amount' => $orderAmount,
                    ],
                );

                return $reward->refresh();
            });
        });
    }

    public function processWithdrawal(
        ReferralWithdrawal $withdrawal,
        string $action,
        int $operatorUserId,
        string $operator,
        ?string $remark = null,
        ?string $traceId = null,
    ): ReferralWithdrawal {
        return DB::transaction(function () use ($withdrawal, $action, $operatorUserId, $operator, $remark, $traceId) {
            $record = ReferralWithdrawal::query()->lockForUpdate()->findOrFail($withdrawal->id);
            throw_if($record->status !== ReferralWithdrawal::STATUS_PENDING, new BusinessException('该提现申请已处理'));

            $user = User::query()->lockForUpdate()->findOrFail($record->user_id);
            $account = $this->lockUserAccount($user->id);
            $amount = round((float) $record->amount, 2);

            if ($action === 'approve') {
                $approvedRemark = $remark ?: ($record->method === ReferralWithdrawal::METHOD_BALANCE ? '后台审核通过，已转入余额' : '后台审核通过');

                $accountPayload = [
                    'referral_pending_withdrawal_balance' => max(round((float) $account->referral_pending_withdrawal_balance - $amount, 2), 0),
                    'referral_withdrawn_balance' => round((float) $account->referral_withdrawn_balance + $amount, 2),
                ];

                if ($record->method === ReferralWithdrawal::METHOD_BALANCE) {
                    $accountPayload['cash_balance'] = round((float) $account->cash_balance + $amount, 2);
                }

                $account = $this->accounts()->updateAccount($account, $accountPayload);

                $this->resetUserAggregateRelations($user);

                if ($record->method === ReferralWithdrawal::METHOD_BALANCE) {
                    AccountTransaction::query()->create([
                        'user_id' => $user->id,
                        'account_type' => 'cash',
                        'event_type' => 'referral_withdraw_approved',
                        'change_amount' => $amount,
                        'balance_after' => $account->cash_balance,
                        'source_type' => 'referral_withdrawal',
                        'source_id' => $record->id,
                        'origin_type' => 'referral_withdrawal',
                        'origin_id' => $record->id,
                        'remark' => "推荐奖励提现转入余额 #{$record->id}",
                        'operator' => $operator,
                        'trace_id' => $traceId,
                    ]);
                }

                $record->forceFill([
                    'status' => ReferralWithdrawal::STATUS_APPROVED,
                    'operator' => $operator,
                    'trace_id' => $traceId,
                    'remark' => $approvedRemark,
                    'processed_at' => now(),
                ])->save();

                $this->writeAccountLog(
                    user: $user,
                    type: self::ACCOUNT_LOG_TYPE_WITHDRAW_APPROVED,
                    amount: -$amount,
                    remark: $record->method === ReferralWithdrawal::METHOD_BALANCE
                        ? '推荐奖励提现审核通过，金额已转入站内余额'
                        : '推荐奖励提现审核通过',
                    relatedId: $record->id,
                    relatedType: 'withdrawal',
                    operator: $operator,
                    traceId: $traceId,
                    balances: $this->buildReferralBalanceSnapshot($account),
                );

                $this->operationLogService->write(
                    userId: $operatorUserId,
                    userType: 'admin',
                    action: 'referral.withdraw.approved',
                    module: 'referral_withdrawal',
                    targetId: $record->id,
                    detail: [
                        'amount' => $amount,
                        'method' => $record->method,
                        'operator' => $operator,
                        'remark' => $record->remark,
                    ],
                );

                return $record->refresh()->load($this->referralUserWithRelations('user'));
            }

            throw_if($action !== 'reject', new BusinessException('不支持的提现处理动作'));

            $account = $this->accounts()->updateAccount($account, [
                'referral_pending_withdrawal_balance' => max(round((float) $account->referral_pending_withdrawal_balance - $amount, 2), 0),
                'referral_available_balance' => round((float) $account->referral_available_balance + $amount, 2),
            ]);

            $this->resetUserAggregateRelations($user);

            $record->forceFill([
                'status' => ReferralWithdrawal::STATUS_REJECTED,
                'operator' => $operator,
                'trace_id' => $traceId,
                'remark' => $remark ?: '后台审核拒绝',
                'processed_at' => now(),
            ])->save();

            $this->writeAccountLog(
                user: $user,
                type: self::ACCOUNT_LOG_TYPE_WITHDRAW_REJECTED,
                amount: $amount,
                remark: '推荐奖励提现被拒绝，金额已退回可提现余额',
                relatedId: $record->id,
                relatedType: 'withdrawal',
                operator: $operator,
                traceId: $traceId,
                balances: $this->buildReferralBalanceSnapshot($account),
            );

            $this->operationLogService->write(
                userId: $operatorUserId,
                userType: 'admin',
                action: 'referral.withdraw.rejected',
                module: 'referral_withdrawal',
                targetId: $record->id,
                detail: [
                    'amount' => $amount,
                    'method' => $record->method,
                    'operator' => $operator,
                    'remark' => $record->remark,
                ],
            );

            return $record->refresh()->load($this->referralUserWithRelations('user'));
        });
    }

    public function rewardRate(): float
    {
        $settingValue = Setting::getValue('referral', 'reward_rate');
        $rate = is_numeric($settingValue) ? (float) $settingValue : self::DEFAULT_REWARD_RATE;

        return max(0, min($rate, 100));
    }

    public function rewardFreezeDays(): int
    {
        $value = Setting::getValue('referral', 'reward_freeze_days', '4');
        $days = is_numeric($value) ? (int) $value : 4;

        return max(0, min($days, 365));
    }

    public function withdrawMinAmount(): float
    {
        $value = Setting::getValue('referral', 'withdraw_min_amount', '20');
        $amount = is_numeric($value) ? (float) $value : 20;

        return max(0, round($amount, 2));
    }

    public function referralEnabled(): bool
    {
        $settingValue = Setting::getValue('referral', 'enabled', '1');

        return (int) $settingValue === 1;
    }

    public function registerPathByCode(string $referralCode): string
    {
        return '/client/register?ref='.$referralCode;
    }

    public function ensureUserLevel(User $user): User
    {
        if (! $this->hasReferralProfilesTable()) {
            $salesAmount = round((float) $user->total_sales_amount, 2);
            $level = $this->memberLevelService->resolveLevelBySales($salesAmount);

            if ((int) ($user->member_level_id ?? 0) !== (int) ($level?->id ?? 0)) {
                $user->forceFill([
                    'member_level_id' => $level?->id,
                ])->save();
            }

            return $user->fresh(['memberLevel']) ?? $user->loadMissing(['memberLevel']);
        }

        $profile = $this->ensureReferralProfile($user);
        $salesAmount = round((float) $profile->total_sales_amount, 2);
        $level = $this->memberLevelService->resolveLevelBySales($salesAmount);

        if ((int) ($profile->member_level_id ?? 0) !== (int) ($level?->id ?? 0)) {
            $profile->forceFill([
                'member_level_id' => $level?->id,
            ])->save();
        }

        $this->syncUserFromReferralProfile($user, $profile);
        $this->resetUserAggregateRelations($user);

        return $user->fresh() ?? $user;
    }

    public function releaseMaturedRewards(?User $targetUser = null): int
    {
        $query = ReferralReward::query()
            ->where('status', ReferralReward::STATUS_FROZEN)
            ->whereNotNull('available_at')
            ->where('available_at', '<=', now());

        if ($targetUser) {
            $query->where('referrer_user_id', $targetUser->id);
        }

        $releasedCount = 0;

        $query->orderBy('id')->chunkById(100, function ($rewards) use (&$releasedCount) {
            foreach ($rewards as $reward) {
                DB::transaction(function () use ($reward, &$releasedCount) {
                    $freshReward = ReferralReward::query()->lockForUpdate()->find($reward->id);
                    if (! $freshReward || $freshReward->status !== ReferralReward::STATUS_FROZEN) {
                        return;
                    }

                    $user = User::query()->lockForUpdate()->find($freshReward->referrer_user_id);
                    if (! $user) {
                        return;
                    }

                    $account = $this->lockUserAccount($user->id);
                    $amount = round((float) $freshReward->reward_amount, 2);

                    $account = $this->accounts()->updateAccount($account, [
                        'referral_frozen_balance' => max(round((float) $account->referral_frozen_balance - $amount, 2), 0),
                        'referral_available_balance' => round((float) $account->referral_available_balance + $amount, 2),
                    ]);

                    if ($this->hasReferralProfilesTable()) {
                        $profile = $this->lockReferralProfile($user->id);
                        $this->syncUserFromReferralProfile($user, $profile);
                    }
                    $this->resetUserAggregateRelations($user);

                    $freshReward->forceFill([
                        'status' => ReferralReward::STATUS_REWARDED,
                        'released_at' => now(),
                        'remark' => '冻结期结束，奖励已转为可提现',
                    ])->save();

                    $this->writeAccountLog(
                        user: $user,
                        type: self::ACCOUNT_LOG_TYPE_REWARD_RELEASED,
                        amount: $amount,
                        remark: '冻结期结束，奖励已转为可提现',
                        relatedId: $freshReward->id,
                        relatedType: 'reward',
                        operator: 'system',
                        traceId: $freshReward->trace_id,
                        balances: $this->buildReferralBalanceSnapshot($account),
                    );

                    $this->operationLogService->write(
                        userId: $user->id,
                        userType: 'system',
                        action: 'referral.reward.released',
                        module: 'referral',
                        targetId: $freshReward->id,
                        detail: [
                            'reward_amount' => $amount,
                            'order_id' => $freshReward->order_id,
                        ],
                    );

                    try {
                        $this->invoiceService->createForReferralCredit(
                            $user,
                            $amount,
                            '推广返利入账，奖励ID: '.$freshReward->id,
                            (string) ($freshReward->trace_id ?? '')
                        );
                    } catch (\Throwable $e) {
                        Log::warning('[推广返利] 创建入账账单失败', [
                            'user_id' => $user->id,
                            'reward_id' => $freshReward->id,
                            'amount' => $amount,
                            'message' => $e->getMessage(),
                        ]);
                    }

                    $releasedCount++;
                });
            }
        });

        return $releasedCount;
    }

    private function generateUniqueReferralCode(): string
    {
        do {
            $code = strtoupper(Str::random(6));
        } while ($this->referralCodeExists($code));

        return $code;
    }

    /**
     * @return array<int, int>
     */
    private function resolveAdminReferralUserIdsByKeyword(string $keyword, int $limit = 200): array
    {
        $keyword = trim($keyword);
        if ($keyword === '') {
            return [];
        }

        $query = User::query()
            ->select('users.id')
            ->distinct();

        $query->where(function (Builder $builder) use ($keyword) {
            $builder
                ->where('users.email', 'like', "%{$keyword}%")
                ->orWhere('users.nickname', 'like', "%{$keyword}%");
        });

        return $query
            ->limit($limit)
            ->pluck('users.id')
            ->map(fn ($id) => (int) $id)
            ->values()
            ->all();
    }

    /**
     * @return array<int, int>
     */
    private function resolveOrderIdsByKeyword(string $keyword, int $limit = 200): array
    {
        $keyword = trim($keyword);
        if ($keyword === '') {
            return [];
        }

        return Order::query()
            ->where('order_no', 'like', "%{$keyword}%")
            ->limit($limit)
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->values()
            ->all();
    }

    /**
     * @return array<int, int>
     */
    private function resolveProductIdsByKeyword(string $keyword, int $limit = 200): array
    {
        $keyword = trim($keyword);
        if ($keyword === '') {
            return [];
        }

        return Product::query()
            ->select(['id', 'product_type', 'purchase_requires', 'config_options'])
            ->limit($limit)
            ->get()
            ->filter(function (Product $product) use ($keyword): bool {
                $displayName = trim((string) ($this->resolveProductDisplayNameResolver()->resolveForProduct($product)['product_display_name'] ?? ''));

                return $displayName !== '' && mb_stripos($displayName, $keyword) !== false;
            })
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->values()
            ->all();
    }

    /**
     * @return array<int, string>
     */
    private function referralUserWithRelations(string $relation): array
    {
        return ["{$relation}:id,email,phone,nickname,real_name,verification_status,is_verified"];
    }

    private function resolveFrontendBaseUrl(string $origin): string
    {
        return PublicUrl::website();
    }

    private function maskAccountNo(string $accountNo): string
    {
        $accountNo = trim($accountNo);
        if ($accountNo === '') {
            return '';
        }

        $length = mb_strlen($accountNo);
        if ($length <= 6) {
            return mb_substr($accountNo, 0, 1).'***'.mb_substr($accountNo, -1);
        }

        return mb_substr($accountNo, 0, 3).'***'.mb_substr($accountNo, -3);
    }

    private function writeAccountLog(
        User $user,
        string $type,
        float $amount,
        string $remark,
        ?int $relatedId = null,
        ?string $relatedType = null,
        ?string $operator = null,
        ?string $traceId = null,
        ?array $balances = null,
        ?string $accountTypeOverride = null,
    ): void {
        $balances ??= [
            'frozen_balance' => round((float) $user->referral_frozen_amount, 2),
            'available_balance' => round((float) $user->referral_available_amount, 2),
            'pending_withdrawal_balance' => round((float) $user->referral_withdrawing_amount, 2),
            'withdrawn_balance' => round((float) $user->referral_withdrawn_amount, 2),
        ];

        [$accountType, $balanceAfter] = $accountTypeOverride !== null
            ? [$accountTypeOverride, $this->resolveReferralBalanceAfter($accountTypeOverride, $balances)]
            : $this->resolveReferralAccountTypeAndBalance($type, $balances);

        AccountTransaction::query()->create([
            'user_id' => $user->id,
            'event_type' => $type,
            'account_type' => $accountType,
            'change_amount' => round($amount, 2),
            'balance_after' => $balanceAfter,
            'remark' => $remark,
            'source_id' => $relatedId,
            'source_type' => $relatedType,
            'origin_type' => 'referral_event',
            'origin_id' => $relatedId,
            'operator' => $operator,
            'trace_id' => $traceId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function transformAccountLogRecord(mixed $transaction): array
    {
        $privacy = AdminPrivacy::current();
        $frozenBalance = round((float) data_get($transaction, 'frozen_balance', 0), 2);
        $availableBalance = round((float) data_get($transaction, 'available_balance', 0), 2);
        $pendingWithdrawalBalance = round((float) data_get($transaction, 'pending_withdrawal_balance', 0), 2);
        $withdrawnBalance = round((float) data_get($transaction, 'withdrawn_balance', 0), 2);

        if ($frozenBalance === 0.0 && $availableBalance === 0.0 && $pendingWithdrawalBalance === 0.0 && $withdrawnBalance === 0.0) {
            [$frozenBalance, $availableBalance, $pendingWithdrawalBalance, $withdrawnBalance] = $this->resolveReferralBalanceFields($transaction);
        }

        $createdAt = data_get($transaction, 'created_at');
        if (! $createdAt instanceof CarbonInterface && $createdAt !== null && $createdAt !== '') {
            try {
                $createdAt = Carbon::parse((string) $createdAt);
            } catch (\Throwable) {
                $createdAt = null;
            }
        }

        return [
            'id' => (int) data_get($transaction, 'id', 0),
            'event_type' => (string) data_get($transaction, 'event_type', ''),
            'type' => (string) data_get($transaction, 'event_type', ''),
            'change_amount' => number_format((float) data_get($transaction, 'change_amount', 0), 2, '.', ''),
            'amount' => number_format((float) data_get($transaction, 'change_amount', 0), 2, '.', ''),
            'frozen_balance' => number_format($frozenBalance, 2, '.', ''),
            'frozen_amount' => number_format($frozenBalance, 2, '.', ''),
            'available_balance' => number_format($availableBalance, 2, '.', ''),
            'available_amount' => number_format($availableBalance, 2, '.', ''),
            'pending_withdrawal_balance' => number_format($pendingWithdrawalBalance, 2, '.', ''),
            'withdrawing_amount' => number_format($pendingWithdrawalBalance, 2, '.', ''),
            'withdrawn_balance' => number_format($withdrawnBalance, 2, '.', ''),
            'withdrawn_amount' => number_format($withdrawnBalance, 2, '.', ''),
            'remark' => (string) data_get($transaction, 'remark', ''),
            'operator' => (string) data_get($transaction, 'operator', ''),
            'created_at' => $createdAt?->format('Y-m-d H:i:s'),
            'user' => $transaction instanceof AccountTransaction && $transaction->relationLoaded('user') && $transaction->user ? [
                'id' => (int) $transaction->user->id,
                'email' => $privacy->email($transaction->user->email),
                'nickname' => (string) $transaction->user->nickname,
                'display_name' => $privacy->displayName($transaction->user->display_name, $transaction->user->email, $transaction->user->phone, $transaction->user->real_name),
            ] : null,
        ];
    }

    private function buildReferralAccountLogQuery(User $user)
    {
        return AccountTransaction::query()
            ->where('user_id', $user->id)
            ->whereIn('event_type', self::ACCOUNT_LOG_EVENT_TYPES);
    }

    private function buildDirectReferralUserQuery(int $referrerUserId, bool $withReadAggregates = false): Builder
    {
        $query = User::query();

        if ($withReadAggregates) {
            $query->withReadAggregates();
        }

        if (! $this->hasReferralProfilesTable()) {
            return $query->where('users.referrer_user_id', $referrerUserId);
        }

        return $query
            ->leftJoin('user_referrals as referral_profiles', 'referral_profiles.user_id', '=', 'users.id')
            ->select('users.*')
            ->selectRaw('COALESCE(referral_profiles.referral_code, users.referral_code) as referral_code')
            ->selectRaw('COALESCE(referral_profiles.referrer_user_id, users.referrer_user_id) as referrer_user_id')
            ->selectRaw('COALESCE(referral_profiles.referred_at, users.referred_at) as referred_at')
            ->selectRaw('COALESCE(referral_profiles.member_level_id, users.member_level_id) as member_level_id')
            ->selectRaw('COALESCE(referral_profiles.total_sales_amount, users.total_sales_amount) as total_sales_amount')
            ->where(function (Builder $builder) use ($referrerUserId) {
                $builder
                    ->where('referral_profiles.referrer_user_id', $referrerUserId)
                    ->orWhere(function (Builder $nested) use ($referrerUserId) {
                        $nested
                        ->whereNull('referral_profiles.referrer_user_id')
                        ->where('users.referrer_user_id', $referrerUserId);
                    });
            });
    }

    private function ensureReferralProfile(User $user, ?string $referralCode = null): UserReferral
    {
        if (! $this->hasReferralProfilesTable()) {
            throw new BusinessException('当前系统未启用推荐画像功能');
        }

        $profile = UserReferral::query()->find($user->id);

        return $profile ?? $this->createReferralProfile((int) $user->id, $referralCode);
    }

    private function lockReferralProfile(int $userId): UserReferral
    {
        if (! $this->hasReferralProfilesTable()) {
            throw new BusinessException('当前系统未启用推荐画像功能');
        }

        $profile = UserReferral::query()->lockForUpdate()->find($userId);
        if ($profile) {
            return $profile;
        }

        $this->createReferralProfile($userId);

        return UserReferral::query()->lockForUpdate()->findOrFail($userId);
    }

    private function lockUserAccount(int $userId): UserAccount
    {
        return $this->accounts()->ensureAccount($userId, true);
    }

    private function createReferralProfile(int $userId, ?string $referralCode = null): UserReferral
    {
        $resolvedCode = $this->normalizeReferralCode($referralCode);
        if (! $this->isReusableReferralCode($resolvedCode)) {
            $resolvedCode = $this->generateUniqueReferralCode();
        }

        return UserReferral::query()->create([
            'user_id' => $userId,
            'referral_code' => $resolvedCode,
            'referrer_user_id' => null,
            'referred_at' => null,
            'member_level_id' => null,
            'total_sales_amount' => '0.00',
        ]);
    }

    private function findReferrerByCode(string $referralCode, int $excludeUserId): ?User
    {
        if ($this->hasReferralProfilesTable()) {
            $referrerProfile = UserReferral::query()
                ->where('referral_code', $referralCode)
                ->where('user_id', '!=', $excludeUserId)
                ->whereHas('user', fn (Builder $query) => $query->active())
                ->with(['user' => fn (Builder $query) => $query->select(['id', 'status', 'last_login_ip'])])
                ->first();

            return $referrerProfile?->user;
        }

        return User::query()
            ->where('referral_code', $referralCode)
            ->where('id', '!=', $excludeUserId)
            ->active()
            ->first(['id', 'status', 'last_login_ip', 'referral_code', 'member_level_id', 'total_sales_amount']);
    }

    private function resolveProductDisplayNameResolver(): ProductDisplayNameResolver
    {
        return $this->productDisplayNameResolver ?? new ProductDisplayNameResolver;
    }

    private function resolveBuyerReferrerUserId(User $buyer): ?int
    {
        $referrerUserId = $buyer->referrer_user_id;
        if ($referrerUserId) {
            return (int) $referrerUserId;
        }

        if (! $this->hasReferralProfilesTable()) {
            return null;
        }

        if ($buyer->relationLoaded('referralProfile') && $buyer->referralProfile instanceof UserReferral) {
            return $buyer->referralProfile->referrer_user_id ? (int) $buyer->referralProfile->referrer_user_id : null;
        }

        $profileReferrerUserId = UserReferral::query()
            ->where('user_id', $buyer->id)
            ->value('referrer_user_id');

        return $profileReferrerUserId !== null ? (int) $profileReferrerUserId : null;
    }

    private function createUserAccount(int $userId): UserAccount
    {
        return $this->accounts()->ensureAccount($userId);
    }

    private function accounts(): AccountService
    {
        return $this->accountService ??= app(AccountService::class);
    }

    /**
     * @return array<string, float>
     */
    private function buildReferralBalanceSnapshot(UserAccount $account): array
    {
        return [
            'frozen_balance' => round((float) $account->referral_frozen_balance, 2),
            'available_balance' => round((float) $account->referral_available_balance, 2),
            'pending_withdrawal_balance' => round((float) $account->referral_pending_withdrawal_balance, 2),
            'withdrawn_balance' => round((float) $account->referral_withdrawn_balance, 2),
        ];
    }

    /**
     * @param  array<string, float>  $balances
     * @return array{0:string,1:float}
     */
    private function resolveReferralAccountTypeAndBalance(string $type, array $balances): array
    {
        return match ($type) {
            self::ACCOUNT_LOG_TYPE_REWARD_FROZEN => ['referral_frozen', round((float) ($balances['frozen_balance'] ?? 0), 2)],
            self::ACCOUNT_LOG_TYPE_REWARD_RELEASED => ['referral_available', round((float) ($balances['available_balance'] ?? 0), 2)],
            self::ACCOUNT_LOG_TYPE_REWARD_REVERSED => ['referral_available', round((float) ($balances['available_balance'] ?? 0), 2)],
            self::ACCOUNT_LOG_TYPE_WITHDRAW_APPLY => ['referral_pending_withdrawal', round((float) ($balances['pending_withdrawal_balance'] ?? 0), 2)],
            self::ACCOUNT_LOG_TYPE_WITHDRAW_APPROVED => ['referral_withdrawn', round((float) ($balances['withdrawn_balance'] ?? 0), 2)],
            self::ACCOUNT_LOG_TYPE_WITHDRAW_REJECTED => ['referral_available', round((float) ($balances['available_balance'] ?? 0), 2)],
            default => ['referral_available', round((float) ($balances['available_balance'] ?? 0), 2)],
        };
    }

    /**
     * @param  array<string, float>  $balances
     */
    private function resolveReferralBalanceAfter(string $accountType, array $balances): float
    {
        return match ($accountType) {
            'referral_frozen' => round((float) ($balances['frozen_balance'] ?? 0), 2),
            'referral_pending_withdrawal' => round((float) ($balances['pending_withdrawal_balance'] ?? 0), 2),
            'referral_withdrawn' => round((float) ($balances['withdrawn_balance'] ?? 0), 2),
            default => round((float) ($balances['available_balance'] ?? 0), 2),
        };
    }

    /**
     * @return array{0:float,1:float,2:float,3:float}
     */
    private function resolveReferralBalanceFields(mixed $transaction): array
    {
        $balanceAfter = round((float) data_get($transaction, 'balance_after', 0), 2);

        return match ((string) data_get($transaction, 'account_type', '')) {
            'referral_frozen' => [$balanceAfter, 0.0, 0.0, 0.0],
            'referral_pending_withdrawal' => [0.0, 0.0, $balanceAfter, 0.0],
            'referral_withdrawn' => [0.0, 0.0, 0.0, $balanceAfter],
            default => [0.0, $balanceAfter, 0.0, 0.0],
        };
    }

    private function resetUserAggregateRelations(User $user): void
    {
        $user->unsetRelation('referralProfile');
        $user->unsetRelation('memberLevel');
        $user->unsetRelation('account');
    }

    private function syncUserFromReferralProfile(User $user, UserReferral $profile): void
    {
        $user->forceFill([
            'referral_code' => $this->normalizeReferralCode($profile->referral_code),
            'referrer_user_id' => $profile->referrer_user_id,
            'referred_at' => $profile->referred_at,
            'member_level_id' => $profile->member_level_id,
            'total_sales_amount' => number_format((float) $profile->total_sales_amount, 2, '.', ''),
        ])->save();
    }

    private function normalizeReferralCode(mixed $code): string
    {
        return strtoupper(trim((string) ($code ?? '')));
    }

    private function isReusableReferralCode(string $code): bool
    {
        return preg_match('/^[A-Z0-9]{6}$/', $code) === 1;
    }

    private function referralCodeExists(string $code): bool
    {
        $normalizedCode = $this->normalizeReferralCode($code);
        if ($normalizedCode === '') {
            return false;
        }

        if (User::query()->where('referral_code', $normalizedCode)->exists()) {
            return true;
        }

        if ($this->hasReferralProfilesTable()) {
            return UserReferral::query()->where('referral_code', $normalizedCode)->exists();
        }

        return false;
    }

    private function hasReferralProfilesTable(): bool
    {
        static $resolved = null;

        if ($resolved !== null) {
            return $resolved;
        }

        $resolved = Schema::hasTable('user_referrals');

        return $resolved;
    }
}
