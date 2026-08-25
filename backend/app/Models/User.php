<?php

namespace App\Models;

use App\Casts\LegacyEncrypted;
use App\Models\Concerns\ReleasesUniqueKeysOnDelete;
use App\Services\User\AccountService;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Carbon;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable
{
    use HasApiTokens, HasFactory, Notifiable, SoftDeletes;
    use ReleasesUniqueKeysOnDelete;

    protected $fillable = [
        'email', 'password', 'phone', 'status',
        'nickname', 'company', 'qq', 'admin_note',
        'referral_code', 'referrer_user_id', 'referred_at', 'member_level_id', 'total_sales_amount',
        'agent_group_id',
        'is_verified', 'real_name', 'id_card', 'verification_status', 'verification_message', 'verification_certify_id', 'verified_at',
        'alipay_real_name', 'alipay_account',
        'login_email_alert', 'login_notify', 'login_location_alert', 'password_change_alert', 'phone_change_alert', 'email_change_alert', 'marketing_alert', 'last_login_ip', 'last_login_at',
    ];

    // $fillable 已显式声明可填充字段，$guarded 无需重复定义

    // 从 JSON 序列化中隐藏密码
    protected $hidden = ['password'];

    protected function casts(): array
    {
        return [
            'id_card' => LegacyEncrypted::class,
            'login_email_alert' => 'boolean',
            'login_notify' => 'boolean',
            'login_location_alert' => 'boolean',
            'password_change_alert' => 'boolean',
            'phone_change_alert' => 'boolean',
            'email_change_alert' => 'boolean',
            'marketing_alert' => 'boolean',
            'last_login_at' => 'datetime',
            'referred_at' => 'datetime',
            'verified_at' => 'datetime',
            'total_sales_amount' => 'decimal:2',
            'is_verified' => 'integer',
            'verification_status' => 'integer',
            'member_level_id' => 'integer',
            'agent_group_id' => 'integer',
            'referrer_user_id' => 'integer',
            'password' => 'hashed',
        ];
    }

    /**
     * 软删时释放 email/phone 全局唯一键（见 ReleasesUniqueKeysOnDelete）。
     * 删除是终局性操作（UserService::deleteUser 含资产保护），原始值由操作日志留痕。
     *
     * @return array<int, string>
     */
    public function uniqueKeysToReleaseOnDelete(): array
    {
        return ['email', 'phone'];
    }

    public function getNicknameAttribute(mixed $value): string
    {
        $nickname = trim((string) ($value ?? ''));

        return $this->hasReadableNickname($nickname) ? $nickname : '';
    }

    public function getCompanyAttribute(mixed $value): string
    {
        return trim((string) ($value ?? ''));
    }

    public function getQqAttribute(mixed $value): string
    {
        return trim((string) ($value ?? ''));
    }

    public function getAdminNoteAttribute(mixed $value): ?string
    {
        $normalized = trim((string) ($value ?? ''));

        return $normalized === '' ? null : $normalized;
    }

    public function getBalanceAttribute(mixed $value): string
    {
        return $this->normalizeDecimalString($this->resolveAccountValue('cash_balance'));
    }

    public function setBalanceAttribute(string|float|int $value): void
    {
        // 写入真源 user_accounts，不写 users 表（该列已删除）
        if ($this->exists) {
            app(AccountService::class)->setCashBalance($this, (float) $value);
        }
    }

    public function getCreditLimitAttribute(mixed $value): string
    {
        return $this->normalizeDecimalString($this->resolveAccountValue('credit_limit'));
    }

    public function setCreditLimitAttribute(string|float|int $value): void
    {
        // 写入真源 user_accounts，不写 users 表（该列已删除）
        if ($this->exists) {
            app(AccountService::class)->updateAccount($this, ['credit_limit' => $value]);
        }
    }

    public function getRealNameAttribute(mixed $value): string
    {
        return trim((string) ($value ?? ''));
    }

    public function getVerificationStatusAttribute(mixed $value): int
    {
        return (int) ($value ?? 0);
    }

    public function getVerificationMessageAttribute(mixed $value): string
    {
        return trim((string) ($value ?? ''));
    }

    public function getVerificationCertifyIdAttribute(mixed $value): ?string
    {
        $normalized = trim((string) ($value ?? ''));

        return $normalized === '' ? null : $normalized;
    }

    public function getVerifiedAtAttribute(mixed $value): ?CarbonInterface
    {
        if ($value instanceof CarbonInterface || $value === null) {
            return $value;
        }

        $normalized = trim((string) $value);

        return $normalized === '' ? null : Carbon::parse($normalized);
    }

    public function getIsVerifiedAttribute(mixed $value): int
    {
        return (int) ($value ?? 0);
    }

    public function getReferralCodeAttribute(mixed $value): string
    {
        return trim((string) ($value ?? ''));
    }

    public function getReferrerUserIdAttribute(mixed $value): ?int
    {
        return $this->normalizeNullableInt($value);
    }

    public function getReferredAtAttribute(mixed $value): mixed
    {
        if ($value instanceof CarbonInterface || $value === null) {
            return $value;
        }

        $normalized = trim((string) $value);

        return $normalized === '' ? null : Carbon::parse($normalized);
    }

    public function getMemberLevelIdAttribute(mixed $value): ?int
    {
        return $this->normalizeNullableInt($value);
    }

    public function getTotalSalesAmountAttribute(mixed $value): string
    {
        return $this->normalizeDecimalString($value);
    }

    public function getReferralFrozenAmountAttribute(mixed $value): string
    {
        return $this->normalizeDecimalString($this->resolveAccountValue('referral_frozen_balance'));
    }

    public function getReferralAvailableAmountAttribute(mixed $value): string
    {
        return $this->normalizeDecimalString($this->resolveAccountValue('referral_available_balance'));
    }

    public function getReferralWithdrawingAmountAttribute(mixed $value): string
    {
        return $this->normalizeDecimalString($this->resolveAccountValue('referral_pending_withdrawal_balance'));
    }

    public function getReferralWithdrawnAmountAttribute(mixed $value): string
    {
        return $this->normalizeDecimalString($this->resolveAccountValue('referral_withdrawn_balance'));
    }

    public function getAlipayRealNameAttribute(mixed $value): string
    {
        return trim((string) ($value ?? ''));
    }

    public function getAlipayAccountAttribute(mixed $value): string
    {
        return trim((string) ($value ?? ''));
    }

    public function getDisplayNameAttribute(): string
    {
        $realName = trim((string) $this->real_name);
        if ($realName !== '' && $this->hasCompletedVerification()) {
            return $realName;
        }

        $nickname = trim((string) $this->nickname);

        if ($this->hasReadableNickname($nickname)) {
            return $nickname;
        }

        $email = trim((string) $this->email);
        if ($email !== '') {
            return $email;
        }

        return trim((string) $this->phone);
    }

    public function hasCompletedVerification(): bool
    {
        return (int) $this->is_verified === 1 || (int) $this->verification_status === 2;
    }

    /**
     * 实名状态中文名。
     *
     * 状态 5 曾写作「已解绑」，与前端两处的「已驳回」不一致，看上去像两个业务事件，
     * 实际只有一个：VerificationService::unbind() 是它唯一的写入点，仅管理员可调
     * （routes/v2-admin.php 的 verifications/{id}/unbindings），前置条件是当前已认证
     * （verification_status === 2），落库时一并写入驳回原因（缺省「管理员驳回」）。
     *
     * 「解绑」是这件事对数据的效果，「驳回」是管理员在界面上做的动作。取后者：
     * 本常量只被 5 个 Admin/V2 Resource 输出，读它的是管理员，而管理端整条流程
     * （canReject / openReject /「请输入驳回原因」）和 shared/statusConfig.js 都叫「驳回」。
     * unbind 保留为 API 与权限标识符，不随文案改动。
     */
    public const VERIFICATION_STATUS_LABELS = [
        0 => '未认证',
        1 => '待认证',
        2 => '已认证',
        3 => '认证失败',
        4 => '待认证',
        5 => '已驳回',
    ];

    public static function verificationStatusLabel(int $status): string
    {
        return self::VERIFICATION_STATUS_LABELS[$status] ?? (string) $status;
    }

    // -------- 关联 --------

    public function orders(): HasMany
    {
        return $this->hasMany(Order::class);
    }

    public function referralProfile(): HasOne
    {
        return $this->hasOne(UserReferral::class, 'user_id');
    }

    public function memberLevel(): BelongsTo
    {
        return $this->belongsTo(MemberLevel::class, 'member_level_id');
    }

    /** @return BelongsTo<AgentGroup, $this> */
    public function agentGroup(): BelongsTo
    {
        return $this->belongsTo(AgentGroup::class, 'agent_group_id');
    }

    public function account(): HasOne
    {
        return $this->hasOne(UserAccount::class, 'user_id');
    }

    public function referrer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'referrer_user_id');
    }

    public function referrals(): HasMany
    {
        return $this->hasMany(User::class, 'referrer_user_id');
    }

    public function referralRewards(): HasMany
    {
        return $this->hasMany(ReferralReward::class, 'referrer_user_id');
    }

    public function referredRewards(): HasMany
    {
        return $this->hasMany(ReferralReward::class, 'referred_user_id');
    }

    public function referralWithdrawals(): HasMany
    {
        return $this->hasMany(ReferralWithdrawal::class);
    }

    public function services(): HasMany
    {
        return $this->hasMany(Service::class);
    }

    public function userCoupons(): HasMany
    {
        return $this->hasMany(UserCoupon::class);
    }

    public function invoices(): HasMany
    {
        return $this->hasMany(Invoice::class);
    }

    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class);
    }

    public function accountTransactions(): HasMany
    {
        return $this->hasMany(AccountTransaction::class, 'user_id');
    }

    public function rechargeRecords(): HasMany
    {
        return $this->hasMany(RechargeRecord::class);
    }

    public function refunds(): HasMany
    {
        return $this->hasMany(Refund::class);
    }

    public function tickets(): HasMany
    {
        return $this->hasMany(Ticket::class);
    }

    public function verificationHistories(): HasMany
    {
        return $this->hasMany(VerificationHistory::class);
    }

    // -------- 作用域 --------

    public function scopeActive($query)
    {
        return $query->where('status', 1);
    }

    public function scopeWithReadAggregates(Builder $query): Builder
    {
        $relations = [];

        $relations[] = 'account';

        return $query->with($relations);
    }

    public function scopeSearch(Builder $query, ?string $keyword): Builder
    {
        if (! $keyword) {
            return $query;
        }

        $keyword = trim($keyword);

        // 注意：id_card 由 LegacyEncrypted 加密存储，明文 LIKE 永不命中，故不纳入搜索字段。
        return $query->where(function ($q) use ($keyword) {
            if (ctype_digit($keyword)) {
                $q->where('id', (int) $keyword)
                    ->orWhere('email', 'like', '%'.$keyword.'%')
                    ->orWhere('phone', 'like', '%'.$keyword.'%')
                    ->orWhere('nickname', 'like', '%'.$keyword.'%')
                    ->orWhere('company', 'like', '%'.$keyword.'%')
                    ->orWhere('qq', 'like', '%'.$keyword.'%')
                    ->orWhere('real_name', 'like', '%'.$keyword.'%');

                return;
            }

            $q->where('email', 'like', '%'.$keyword.'%')
                ->orWhere('phone', 'like', '%'.$keyword.'%')
                ->orWhere('nickname', 'like', '%'.$keyword.'%')
                ->orWhere('company', 'like', '%'.$keyword.'%')
                ->orWhere('qq', 'like', '%'.$keyword.'%')
                ->orWhere('real_name', 'like', '%'.$keyword.'%');
        });
    }

    private function hasReadableNickname(string $nickname): bool
    {
        if ($nickname === '') {
            return false;
        }

        return preg_replace('/[\s\?？\x{FFFD}]+/u', '', $nickname) !== '';
    }

    private function nullableValue(mixed $value): ?string
    {
        $normalized = trim((string) $value);

        return $normalized === '' ? null : $normalized;
    }

    private function normalizeNullableInt(mixed $value): ?int
    {
        if ($value === null || $value === '' || ! is_numeric($value)) {
            return null;
        }

        return (int) $value;
    }

    private function normalizeDecimal(mixed $value): string
    {
        if (! is_numeric($value)) {
            return '0.00';
        }

        return number_format((float) $value, 2, '.', '');
    }

    private function normalizeDecimalString(mixed $value): string
    {
        return $this->normalizeDecimal($value);
    }

    private function resolveAccountValue(string $field): mixed
    {
        if ($this->relationLoaded('account')) {
            $account = $this->getRelation('account');
            if ($account instanceof UserAccount) {
                return $account->{$field} ?? 0;
            }

            return 0;
        }

        if ($this->exists && (int) ($this->attributes['id'] ?? 0) > 0) {
            $account = $this->account()->first();
            if ($account instanceof UserAccount) {
                $this->setRelation('account', $account);

                return $account->{$field} ?? 0;
            }
        }

        return 0;
    }
}
