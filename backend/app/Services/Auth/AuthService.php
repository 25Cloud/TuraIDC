<?php

declare(strict_types=1);

namespace App\Services\Auth;

use App\Exceptions\BusinessException;
use App\Jobs\SendClientLoginEmailAlertJob;
use App\Jobs\SendClientLoginFailureEmailAlertJob;
use App\Models\AdminUser;
use App\Models\User;
use App\Services\Referral\ReferralService;
use App\Services\System\NotificationService;
use App\Services\System\OperationLogService;
use App\Services\User\AdminRoleBridgeService;
use App\Support\AccountIdentifier;
use App\Support\SensitiveDataSanitizer;
use App\Support\TextSanitizer;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class AuthService
{
    private const ADMIN_LOGIN_AS_CODE_TTL_SECONDS = 120;

    private const ADMIN_LOGIN_MAX_FAILED_ATTEMPTS = 5;

    private const ADMIN_LOGIN_FAILED_WINDOW_SECONDS = 1800;

    private const PASSWORD_TIMING_GUARD_HASH = '$2y$10$9i4SuhzLa07GghDFTutcTeB5w1sRFYJhPguXpXxeSElBVdggfyff2';

    private const CLIENT_LOGIN_COLUMNS = [
        'id',
        'email',
        'phone',
        'password',
        'nickname',
        'status',
        'login_email_alert',
        'login_notify',
        'login_location_alert',
        'password_change_alert',
        'phone_change_alert',
        'email_change_alert',
        'marketing_alert',
        'last_login_ip',
    ];

    public function __construct(
        private NotificationService $notificationService,
        private ReferralService $referralService,
        private OperationLogService $operationLogService,
        private AdminRoleBridgeService $adminRoleBridgeService,
        private LoginRiskControlService $loginRiskControlService,
        private LegacyPasswordVerifier $legacyPasswordVerifier,
    ) {}

    /**
     * 客户登录
     */
    public function clientLogin(string $account, string $password, string $ip, ?string $userAgent = null): array
    {
        $accountType = AccountIdentifier::detectType($account);
        if (! $accountType) {
            throw new BusinessException('请输入正确的邮箱或手机号', 42200, 422);
        }

        $normalizedAccount = AccountIdentifier::normalizeAccount($account);
        $user = $this->findClientByAccount($accountType, $normalizedAccount);

        // 旧站迁移账号（密码为 ###+MD5 等非 bcrypt 格式）不再支持直接登录，
        // 强制用户走“忘记密码”流程重置密码，避免 Hash::check 对非 bcrypt 抛 RuntimeException。
        if ($user instanceof User && ! $this->isBcryptHash((string) ($user->password ?? ''))) {
            throw new BusinessException('该账号为旧站迁移账号，请使用忘记密码重置密码', 40100, 422);
        }

        $needsPasswordRehash = false;
        $passwordValid = $user
            ? $this->verifyPassword($password, $user->password ?? '', $needsPasswordRehash)
            : Hash::check($password, self::PASSWORD_TIMING_GUARD_HASH);

        if (! $user) {
            throw new BusinessException('账号或密码错误', 40100, 422);
        }

        if (! $passwordValid) {
            throw new BusinessException('账号或密码错误', 40100, 422);
        }

        if ($user->status !== 1) {
            throw new BusinessException('账号已被禁用', 40300, 403);
        }

        $loginAt = now();

        // 与「改密即吊销全部 token」序列化，消除竞态。
        //
        // 上面的校验是无锁快照读：本次登录可能已用旧密码通过校验，随后管理员改密并
        // 吊销全部 token 的事务提交，而本次登录才执行 createToken——于是吊销之后又长出
        // 一个有效 token，「处置盗号」这条通道恰好失效。改密侧的 UPDATE users 虽然持有
        // 行锁，但拦不住无锁读。
        //
        // 锁住 users 行后重新读哈希再验一次即可闭合：改密事务先提交，这里读到新哈希、
        // 旧密码校验失败，登录被拒；本次登录先提交，改密事务的 tokens()->delete() 能看到
        // 并删掉刚签发的 token。两种交错都安全。
        //
        // 上面的校验保留不动——它让无效登录快速失败，不必进入事务与行锁，同时保住对
        // 不存在账号的时序防护（假 hash 比对）。
        $token = DB::transaction(function () use ($user, $password): string {
            $lockedUser = User::query()->lockForUpdate()->find((int) $user->id);

            if (! $lockedUser instanceof User) {
                throw new BusinessException('账号或密码错误', 40100, 422);
            }

            $rehashAfterLock = false;
            if (! $this->isBcryptHash((string) ($lockedUser->password ?? ''))
                || ! $this->verifyPassword($password, (string) ($lockedUser->password ?? ''), $rehashAfterLock)) {
                throw new BusinessException('账号或密码错误', 40100, 422);
            }

            if ((int) $lockedUser->status !== 1) {
                throw new BusinessException('账号已被禁用', 40300, 403);
            }

            return $lockedUser->createToken('client-token')->plainTextToken;
        });

        $this->finishClientLoginAfterResponse(
            userId: (int) $user->id,
            loginAt: $loginAt->format('Y-m-d H:i:s'),
            ip: $ip,
            email: trim((string) $user->email),
            displayName: (string) $user->display_name,
            userAgent: $userAgent,
            loginNotifyEnabled: (bool) (($user->login_notify ?? null) ?? $user->login_email_alert),
            loginLocationAlertEnabled: (bool) ($user->login_location_alert ?? true),
            previousIp: trim((string) ($user->last_login_ip ?? '')),
            passwordToRehash: $needsPasswordRehash ? $password : null,
        );

        return [
            'token' => $token,
            'user' => [
                'id' => $user->id,
                'email' => (string) ($user->email ?? ''),
                'phone' => (string) ($user->phone ?? ''),
                'nickname' => $user->nickname,
                'display_name' => (string) ($user->display_name ?? ''),
                'login_email_alert' => (int) $user->login_email_alert,
                'login_notify' => (int) (($user->login_notify ?? null) ?? $user->login_email_alert),
                'login_location_alert' => (int) ($user->login_location_alert ?? 1),
                'password_change_alert' => (int) ($user->password_change_alert ?? 1),
                'phone_change_alert' => (int) ($user->phone_change_alert ?? 1),
                'email_change_alert' => (int) ($user->email_change_alert ?? 1),
                'marketing_alert' => (int) ($user->marketing_alert ?? 0),
                'cash_balance' => (string) $user->balance,
                'credit_limit' => (string) $user->credit_limit,
                'referral_frozen_balance' => (string) $user->referral_frozen_amount,
                'referral_available_balance' => (string) $user->referral_available_amount,
                'referral_pending_withdrawal_balance' => (string) $user->referral_withdrawing_amount,
                'referral_withdrawn_balance' => (string) $user->referral_withdrawn_amount,
                'last_login_at' => $loginAt->format('Y-m-d H:i:s'),
                'last_login_ip' => $ip,
            ],
        ];
    }

    /**
     * 客户验证码登录
     */
    public function clientLoginByCode(string $account, string $code, string $ip, ?string $userAgent = null): array
    {
        $accountType = AccountIdentifier::detectType($account);
        if (! $accountType) {
            throw new BusinessException('请输入正确的邮箱或手机号', 42200, 422);
        }

        $normalizedAccount = AccountIdentifier::normalizeAccount($account);
        $user = $this->findClientByAccount($accountType, $normalizedAccount);

        if (! $user) {
            throw new BusinessException('账号或验证码错误', 40100, 422);
        }

        if ($user->status !== 1) {
            throw new BusinessException('账号已被禁用', 40300, 403);
        }

        $loginAt = now();
        $token = $user->createToken('client-token')->plainTextToken;
        $this->finishClientLoginAfterResponse(
            userId: (int) $user->id,
            loginAt: $loginAt->format('Y-m-d H:i:s'),
            ip: $ip,
            email: trim((string) $user->email),
            displayName: (string) $user->display_name,
            userAgent: $userAgent,
            loginNotifyEnabled: (bool) (($user->login_notify ?? null) ?? $user->login_email_alert),
            loginLocationAlertEnabled: (bool) ($user->login_location_alert ?? true),
            previousIp: trim((string) ($user->last_login_ip ?? '')),
        );

        return [
            'token' => $token,
            'user' => [
                'id' => $user->id,
                'email' => (string) ($user->email ?? ''),
                'phone' => (string) ($user->phone ?? ''),
                'nickname' => $user->nickname,
                'display_name' => (string) ($user->display_name ?? ''),
                'login_email_alert' => (int) $user->login_email_alert,
                'login_notify' => (int) (($user->login_notify ?? null) ?? $user->login_email_alert),
                'login_location_alert' => (int) ($user->login_location_alert ?? 1),
                'password_change_alert' => (int) ($user->password_change_alert ?? 1),
                'phone_change_alert' => (int) ($user->phone_change_alert ?? 1),
                'email_change_alert' => (int) ($user->email_change_alert ?? 1),
                'marketing_alert' => (int) ($user->marketing_alert ?? 0),
                'cash_balance' => (string) $user->balance,
                'credit_limit' => (string) $user->credit_limit,
                'referral_frozen_balance' => (string) $user->referral_frozen_amount,
                'referral_available_balance' => (string) $user->referral_available_amount,
                'referral_pending_withdrawal_balance' => (string) $user->referral_withdrawing_amount,
                'referral_withdrawn_balance' => (string) $user->referral_withdrawn_amount,
                'last_login_at' => $loginAt->format('Y-m-d H:i:s'),
                'last_login_ip' => $ip,
            ],
        ];
    }

    /**
     * 客户注册
     */
    public function clientRegister(array $data, string $ip): array
    {
        $accountType = AccountIdentifier::detectType((string) ($data['account'] ?? ''));
        if (! $accountType) {
            throw new BusinessException('请输入正确的邮箱或手机号', 42200, 422);
        }

        $account = AccountIdentifier::normalizeAccount((string) $data['account']);
        $email = AccountIdentifier::normalizeOptionalEmail((string) ($data['email'] ?? ''));
        $phone = AccountIdentifier::normalizeOptionalPhone((string) ($data['phone'] ?? ''));

        if ($accountType === 'email') {
            $email = $account;
        } else {
            $phone = $account;
        }

        $this->ensureUniqueClientEmail($email);
        $this->ensureUniqueClientPhone($phone);

        $storablePhone = $phone !== null && $phone !== '' ? $phone : null;

        $user = DB::transaction(function () use ($data, $ip, $email, $storablePhone) {
            $nickname = TextSanitizer::clean((string) ($data['nickname'] ?? ''));
            $normalizedNickname = $nickname !== '' ? $nickname : '';

            $user = User::create([
                'email' => $email,
                'password' => $data['password'],
                'phone' => $storablePhone,
                'nickname' => $normalizedNickname,
                'login_email_alert' => $email !== null ? 1 : 0,
                'last_login_at' => now(),
                'last_login_ip' => $ip,
            ]);

            $this->referralService->ensureReferralCode($user);
            $this->referralService->bindReferrer($user, $data['referral_code'] ?? null, [
                'ip' => $ip,
            ]);

            return $user->fresh() ?? $user;
        });

        if ($user->referrer_user_id) {
            $this->operationLogService->write(
                userId: $user->id,
                userType: 'client',
                action: 'client.register.referral_bound',
                module: 'referral',
                targetId: $user->id,
                detail: [
                    'referrer_user_id' => $user->referrer_user_id,
                    'referral_code' => $data['referral_code'] ?? '',
                ],
                ipAddress: $ip,
            );
        }

        $token = $user->createToken('client-token')->plainTextToken;

        return [
            'token' => $token,
            'user' => [
                'id' => $user->id,
                'email' => (string) ($user->email ?? ''),
                'phone' => (string) ($user->phone ?? ''),
                'nickname' => $user->nickname,
                'display_name' => (string) ($user->display_name ?? ''),
                'cash_balance' => '0.00',
                'credit_limit' => '0.00',
                'referral_frozen_balance' => '0.00',
                'referral_available_balance' => '0.00',
                'referral_pending_withdrawal_balance' => '0.00',
                'referral_withdrawn_balance' => '0.00',
                'last_login_at' => now()->format('Y-m-d H:i:s'),
                'last_login_ip' => $ip,
            ],
        ];
    }

    public function notifyClientLoginFailureOnce(string $account, string $ip, ?string $userAgent = null): void
    {
        $accountType = AccountIdentifier::detectType($account);
        if (! $accountType) {
            return;
        }

        $normalizedAccount = AccountIdentifier::normalizeAccount($account);
        $user = $this->resolveClientForFailureAlert($accountType, $normalizedAccount);
        if (! $user) {
            return;
        }

        $email = trim((string) $user->email);
        if ($email === '' || ! (bool) $user->login_email_alert) {
            return;
        }

        if (! $this->loginRiskControlService->acquireFailureAlertLock($normalizedAccount)) {
            return;
        }

        $this->dispatchClientLoginFailureAlert(
            userId: (int) $user->id,
            email: $email,
            displayName: (string) $user->display_name,
            account: $normalizedAccount,
            attemptAt: now()->format('Y-m-d H:i:s'),
            ip: $ip,
            userAgent: $userAgent,
        );
    }

    public function findClientByAccount(string $accountType, string $account): ?User
    {
        if ($accountType === 'email') {
            return User::query()
                ->withReadAggregates()
                ->select(self::CLIENT_LOGIN_COLUMNS)
                ->where('email', AccountIdentifier::normalizeEmail($account))
                ->first();
        }

        $phone = AccountIdentifier::normalizePhone($account);
        $matches = User::query()
            ->withReadAggregates()
            ->select(self::CLIENT_LOGIN_COLUMNS)
            ->where('phone', $phone)
            ->limit(2)
            ->get();

        if ($matches->count() > 1) {
            throw new BusinessException('该手机号关联了多个账号，请联系客服处理', 42200, 422);
        }

        return $matches->first();
    }

    public function ensureUniqueClientEmail(?string $email, ?int $ignoreUserId = null): void
    {
        if ($email === null || $email === '') {
            return;
        }

        $query = User::query()->where('email', $email);
        if ($ignoreUserId !== null) {
            $query->where('id', '<>', $ignoreUserId);
        }

        if ($query->exists()) {
            throw new BusinessException('邮箱已被注册', 40900, 409);
        }
    }

    public function ensureUniqueClientPhone(?string $phone, ?int $ignoreUserId = null): void
    {
        if ($phone === null || $phone === '') {
            return;
        }

        $query = User::query()->where('phone', $phone);
        if ($ignoreUserId !== null) {
            $query->where('id', '<>', $ignoreUserId);
        }

        if ($query->exists()) {
            throw new BusinessException('手机号已被注册', 40900, 409);
        }
    }

    /**
     * 忘记密码流程重置客户密码，并吊销全部已签发 token。
     *
     * 原实现是裸的两条语句，各自 autocommit：UPDATE 提交后行锁即释放，delete 是独立
     * 语句，中间的窗口比 updateClientPassword 更宽。与登录侧的 lockForUpdate 配对后，
     * 「改密 + 吊销」成为一个不可分割的临界区，并发登录无法在吊销之后签出新 token。
     */
    public function resetClientPassword(User $user, string $password): void
    {
        DB::transaction(function () use ($user, $password): void {
            $lockedUser = User::query()->lockForUpdate()->findOrFail((int) $user->id);

            $lockedUser->update([
                'password' => $password,
            ]);
            $lockedUser->tokens()->delete();
        });
    }

    public function updateClientProfile(User $user, array $data, array $context = []): User
    {
        $nickname = TextSanitizer::clean((string) ($data['nickname'] ?? ''));
        $normalizedNickname = $nickname !== '' ? $nickname : null;

        return DB::transaction(function () use ($user, $normalizedNickname, $context) {
            $lockedUser = User::query()->lockForUpdate()->findOrFail((int) $user->id);
            $lockedUser->update([
                'nickname' => $normalizedNickname,
            ]);

            $this->operationLogService->write(
                userId: (int) $lockedUser->id,
                userType: 'client',
                action: 'profile.nickname.update',
                module: 'auth',
                targetId: (int) $lockedUser->id,
                detail: $this->buildClientAuthLogDetail([
                    'nickname' => $normalizedNickname ?? '',
                ], $context),
                ipAddress: $this->resolveContextIpAddress($context),
            );

            return $this->refreshClientUser($lockedUser);
        });
    }

    public function updateClientAlipayAccount(User $user, array $data, array $context = []): User
    {
        $realName = TextSanitizer::clean((string) ($data['real_name'] ?? ''));
        $account = AccountIdentifier::normalizePhone((string) ($data['account'] ?? ''));

        return DB::transaction(function () use ($user, $realName, $account, $context) {
            $lockedUser = User::query()->lockForUpdate()->findOrFail((int) $user->id);
            $lockedUser->update([
                'alipay_real_name' => $realName,
                'alipay_account' => $account,
            ]);

            $this->operationLogService->write(
                userId: (int) $lockedUser->id,
                userType: 'client',
                action: 'profile.alipay.bind',
                module: 'auth',
                targetId: (int) $lockedUser->id,
                detail: $this->buildClientAuthLogDetail([
                    'real_name' => $realName,
                    'account' => $account,
                ], $context),
                ipAddress: $this->resolveContextIpAddress($context),
            );

            return $this->refreshClientUser($lockedUser);
        });
    }

    public function updateClientNotificationPreferences(User $user, array $data, array $context = []): User
    {
        $normalized = $this->normalizeNotificationPreferences($data);

        return DB::transaction(function () use ($user, $normalized, $context) {
            $lockedUser = User::query()->lockForUpdate()->findOrFail((int) $user->id);
            $lockedUser->update($normalized);

            $this->operationLogService->write(
                userId: (int) $lockedUser->id,
                userType: 'client',
                action: 'profile.notification.update',
                module: 'auth',
                targetId: (int) $lockedUser->id,
                detail: $this->buildClientAuthLogDetail($normalized, $context),
                ipAddress: $this->resolveContextIpAddress($context),
            );

            return $this->refreshClientUser($lockedUser);
        });
    }

    public function updateClientPhone(User $user, string $phone, array $context = []): User
    {
        $normalizedPhone = AccountIdentifier::normalizePhone($phone);

        return DB::transaction(function () use ($user, $normalizedPhone, $context) {
            $lockedUser = User::query()->lockForUpdate()->findOrFail((int) $user->id);
            $this->ensureUniqueClientPhone($normalizedPhone, (int) $lockedUser->id);
            $oldPhone = trim((string) ($lockedUser->phone ?? ''));
            $notificationEmail = trim((string) ($lockedUser->email ?? ''));
            $displayName = (string) $lockedUser->display_name;
            $alertEnabled = (bool) ($lockedUser->phone_change_alert ?? true);

            $lockedUser->update([
                'phone' => $normalizedPhone,
            ]);

            $this->operationLogService->write(
                userId: (int) $lockedUser->id,
                userType: 'client',
                action: 'security.phone.update',
                module: 'auth',
                targetId: (int) $lockedUser->id,
                detail: $this->buildClientAuthLogDetail([
                    'phone' => $normalizedPhone,
                ], $context),
                ipAddress: $this->resolveContextIpAddress($context),
            );

            $freshUser = $this->refreshClientUser($lockedUser);

            if ($alertEnabled && $notificationEmail !== '') {
                $this->dispatchPhoneChangedAlert(
                    email: $notificationEmail,
                    displayName: $displayName,
                    oldPhone: $oldPhone,
                    newPhone: $normalizedPhone,
                    changedAt: now()->format('Y-m-d H:i:s'),
                    ip: (string) ($context['ip_address'] ?? ''),
                    userAgent: $context['user_agent'] ?? null,
                );
            }

            return $freshUser;
        });
    }

    public function updateClientEmail(User $user, string $email, array $context = []): User
    {
        $normalizedEmail = AccountIdentifier::normalizeEmail($email);

        return DB::transaction(function () use ($user, $normalizedEmail, $context) {
            $lockedUser = User::query()->lockForUpdate()->findOrFail((int) $user->id);
            $this->ensureUniqueClientEmail($normalizedEmail, (int) $lockedUser->id);
            $oldEmail = trim((string) ($lockedUser->email ?? ''));
            $displayName = (string) $lockedUser->display_name;
            $emailChangeAlertEnabled = (bool) ($lockedUser->email_change_alert ?? true);
            $loginNotifyEnabled = (bool) (($lockedUser->login_notify ?? null) ?? $lockedUser->login_email_alert);

            $lockedUser->update([
                'email' => $normalizedEmail,
                'login_email_alert' => $loginNotifyEnabled && $normalizedEmail !== '' ? 1 : 0,
            ]);

            $this->operationLogService->write(
                userId: (int) $lockedUser->id,
                userType: 'client',
                action: 'security.email.update',
                module: 'auth',
                targetId: (int) $lockedUser->id,
                detail: $this->buildClientAuthLogDetail([
                    'email' => $normalizedEmail,
                ], $context),
                ipAddress: $this->resolveContextIpAddress($context),
            );

            $freshUser = $this->refreshClientUser($lockedUser);

            if ($emailChangeAlertEnabled) {
                $this->dispatchEmailChangedAlert(
                    oldEmail: $oldEmail,
                    newEmail: $normalizedEmail,
                    displayName: $displayName,
                    changedAt: now()->format('Y-m-d H:i:s'),
                    ip: (string) ($context['ip_address'] ?? ''),
                    userAgent: $context['user_agent'] ?? null,
                );
            }

            return $freshUser;
        });
    }

    public function updateClientPassword(User $user, string $oldPassword, string $newPassword, array $context = []): void
    {
        DB::transaction(function () use ($user, $oldPassword, $newPassword, $context): void {
            $lockedUser = User::query()->lockForUpdate()->findOrFail((int) $user->id);
            $notificationEmail = trim((string) ($lockedUser->email ?? ''));
            $displayName = (string) $lockedUser->display_name;
            $alertEnabled = (bool) ($lockedUser->password_change_alert ?? true);

            // 非 bcrypt 格式（旧站迁移密码等）一律视为无效，绝不交给 Hash::check，避免 500。
            if (! $this->isBcryptHash((string) $lockedUser->password)) {
                throw new BusinessException('原密码错误', 42200, 422);
            }

            if (! Hash::check($oldPassword, (string) $lockedUser->password)) {
                throw new BusinessException('原密码错误', 42200, 422);
            }

            $lockedUser->update([
                'password' => $newPassword,
            ]);

            $lockedUser->tokens()->delete();

            $this->operationLogService->write(
                userId: (int) $lockedUser->id,
                userType: 'client',
                action: 'security.password.update',
                module: 'auth',
                targetId: (int) $lockedUser->id,
                detail: $this->buildClientAuthLogDetail([
                    'logout_all_tokens' => true,
                ], $context),
                ipAddress: $this->resolveContextIpAddress($context),
            );

            if ($alertEnabled && $notificationEmail !== '') {
                $this->dispatchPasswordChangedAlert(
                    email: $notificationEmail,
                    displayName: $displayName,
                    changedAt: now()->format('Y-m-d H:i:s'),
                    ip: (string) ($context['ip_address'] ?? ''),
                    userAgent: $context['user_agent'] ?? null,
                );
            }
        });
    }

    /**
     * 管理员登录
     */
    public function adminLogin(string $username, string $password, string $ip): array
    {
        if (! AdminUser::query()->exists()) {
            throw new BusinessException('后台管理员未初始化，请先执行数据初始化', 42200, 422);
        }

        $normalizedUsername = mb_strtolower(trim($username));
        $this->ensureAdminLoginNotLocked($normalizedUsername);

        $admin = AdminUser::query()
            ->with('role')
            ->where('username', $username)
            ->first();

        // 防止时序攻击：即使用户不存在也执行 Hash::check
        $passwordValid = $admin !== null
            ? Hash::check($password, (string) $admin->password)
            : Hash::check($password, self::PASSWORD_TIMING_GUARD_HASH);

        if ($admin === null || ! $passwordValid) {
            $this->recordAdminLoginFailure($normalizedUsername);

            throw new BusinessException('用户名或密码错误', 40100, 401);
        }

        if ($admin->status !== 1) {
            throw new BusinessException('账号已被禁用', 40300, 403);
        }

        $this->clearAdminLoginFailures($normalizedUsername);

        $admin->update([
            'last_login_at' => now(),
            'last_login_ip' => $ip,
        ]);
        $this->adminRoleBridgeService->syncPrimaryRole($admin);
        $admin->unsetRelation('roles');

        $this->operationLogService->write(
            userId: (int) $admin->id,
            userType: 'admin',
            action: 'admin.login',
            module: 'auth',
            targetId: (int) $admin->id,
            detail: [
                'admin_username' => (string) $admin->username,
                'admin_nickname' => (string) ($admin->nickname ?? ''),
                'role_name' => $admin->resolvedRoleLabel(),
            ],
            ipAddress: $ip,
        );

        $token = $admin->createToken('admin-token')->plainTextToken;

        return [
            'token' => $token,
            'admin' => [
                'id' => $admin->id,
                'username' => $admin->username,
                'nickname' => $admin->nickname,
                'email' => (string) ($admin->email ?? ''),
                'role' => $admin->resolvedRoleLabel(),
                'permissions' => $admin->resolvedPermissions(),
            ],
        ];
    }

    public function issueAdminLoginAsCode(User $user, array $context = []): array
    {
        $this->ensureClientAvailable($user);

        $code = Str::random(64);
        $targetUrl = $this->resolveAdminLoginAsTargetUrl();
        $cacheKey = $this->buildAdminLoginAsCacheKey($code);
        $adminId = (int) ($context['admin_id'] ?? 0);
        $ipAddress = trim((string) ($context['ip_address'] ?? ''));
        $userAgentHash = $this->hashLoginAsUserAgent((string) ($context['user_agent'] ?? ''));

        Cache::store('redis_volatile')->put($cacheKey, [
            'user_id' => (int) $user->id,
            'admin_id' => $adminId > 0 ? $adminId : null,
            'issued_ip' => $ipAddress !== '' ? $ipAddress : null,
            'issued_user_agent_hash' => $userAgentHash !== '' ? $userAgentHash : null,
            'issued_at' => now()->format('Y-m-d H:i:s'),
        ], now()->addSeconds(self::ADMIN_LOGIN_AS_CODE_TTL_SECONDS));

        $this->operationLogService->write(
            userId: $adminId > 0 ? $adminId : null,
            userType: 'admin',
            action: 'admin.user.login_as.issue',
            module: 'auth',
            targetId: (int) $user->id,
            detail: [
                'client_user_id' => (int) $user->id,
                'expires_in_seconds' => self::ADMIN_LOGIN_AS_CODE_TTL_SECONDS,
            ],
            ipAddress: $ipAddress !== '' ? $ipAddress : null,
        );

        return [
            'login_code' => $code,
            'expires_in' => self::ADMIN_LOGIN_AS_CODE_TTL_SECONDS,
            'user' => [
                'id' => (int) $user->id,
                'email' => (string) $user->email,
                'nickname' => (string) $user->nickname,
            ],
            'target_url' => $targetUrl,
        ];
    }

    private function resolveAdminLoginAsTargetUrl(): string
    {
        $consoleUrl = $this->normalizeConfiguredUrl((string) config('app.client_console_url', ''));
        if ($consoleUrl === '') {
            throw new BusinessException('CLIENT_CONSOLE_URL 未配置，无法生成客户端代登录链接', 50000, 500);
        }

        $configuredAdminUrl = trim((string) config('app.admin_url', ''));
        $adminUrl = $this->normalizeConfiguredUrl($configuredAdminUrl);
        if ($configuredAdminUrl !== '' && $adminUrl === '') {
            throw new BusinessException('ADMIN_URL 配置无效，无法生成客户端代登录链接', 50000, 500);
        }

        if ($adminUrl !== '' && $this->sameUrlOrigin($consoleUrl, $adminUrl)) {
            throw new BusinessException('CLIENT_CONSOLE_URL 不能与 ADMIN_URL 相同，无法生成客户端代登录链接', 50000, 500);
        }

        return $consoleUrl.'/client/login-as';
    }

    private function normalizeConfiguredUrl(string $url): string
    {
        $normalized = trim($url);
        if ($normalized === '') {
            return '';
        }

        $parts = parse_url($normalized);
        if (! is_array($parts)) {
            return '';
        }

        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        $path = (string) ($parts['path'] ?? '');
        if (! in_array($scheme, ['http', 'https'], true)
            || trim((string) ($parts['host'] ?? '')) === ''
            || isset($parts['user'])
            || isset($parts['pass'])
            || isset($parts['query'])
            || isset($parts['fragment'])
            || ($path !== '' && $path !== '/')) {
            return '';
        }

        return rtrim($normalized, '/');
    }

    private function sameUrlOrigin(string $left, string $right): bool
    {
        $leftOrigin = $this->urlOrigin($left);
        $rightOrigin = $this->urlOrigin($right);

        return $leftOrigin !== '' && $leftOrigin === $rightOrigin;
    }

    private function urlOrigin(string $url): string
    {
        $parts = parse_url($url);
        if (! is_array($parts)) {
            return '';
        }

        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        $host = strtolower((string) ($parts['host'] ?? ''));
        if ($scheme === '' || $host === '') {
            return '';
        }

        $port = (int) ($parts['port'] ?? 0);
        if (($scheme === 'http' && $port === 80) || ($scheme === 'https' && $port === 443)) {
            $port = 0;
        }

        return $scheme.'://'.$host.($port > 0 ? ':'.$port : '');
    }

    public function exchangeAdminLoginAsCode(string $code, string $ip, ?string $userAgent = null): array
    {
        $code = trim($code);
        if ($code === '') {
            throw new BusinessException('代登录凭证不能为空', 42200, 422);
        }

        $payload = Cache::store('redis_volatile')->pull($this->buildAdminLoginAsCacheKey($code));
        if (! is_array($payload)) {
            throw new BusinessException('代登录凭证已失效，请重新发起', 41000, 410);
        }

        // IP 校验已移除：生产环境 Admin 端与 Client 端可能经过不同代理链，
        // IP 经常不一致；凭证本身已有 64 字符随机 + 单次消费 + 120s TTL 保护。

        $issuedUserAgentHash = trim((string) ($payload['issued_user_agent_hash'] ?? ''));
        $currentUserAgentHash = $this->hashLoginAsUserAgent((string) ($userAgent ?? ''));

        // 签发时记录了 UA 就必须能对上；**交换侧 UA 为空一律拒绝**。
        //
        // 原实现要求两侧都非空才比对（$issued !== '' && $current !== '' && ! hash_equals），
        // 而 issued 侧由管理员自己的请求写入、攻击者控制不了，攻击者能控制的只有交换侧：
        // 截获 code 后发一个不带 User-Agent 的请求，$current 为空串，整段绑定校验被跳过。
        // 也就是说这道防线对唯一会攻击它的人恰好失效。
        //
        // issued 为空（签发请求本身没带 UA，例如脚本化的管理端调用）时无从绑定，
        // 维持放行——该情形不受攻击者摆布，凭证本身仍有 64 字符随机 + 单次消费 + 120s TTL。
        if ($issuedUserAgentHash !== '') {
            if ($currentUserAgentHash === '' || ! hash_equals($issuedUserAgentHash, $currentUserAgentHash)) {
                throw new BusinessException('代登录环境校验失败，请在原浏览器窗口重新发起', 40300, 403);
            }
        }

        $user = User::query()->find((int) ($payload['user_id'] ?? 0));
        if (! $user) {
            throw new BusinessException('目标用户不存在', 40400, 404);
        }

        $this->ensureClientAvailable($user);

        // 代登录同样要与改密吊销序列化：凭证在改密之前签发、在吊销之后才被交换时，
        // 无锁路径会在「全部 token 已吊销」之后又签出一个 2 小时有效的代登录 token。
        $token = DB::transaction(function () use ($user) {
            $lockedUser = User::query()->lockForUpdate()->find((int) $user->id);

            if (! $lockedUser instanceof User) {
                throw new BusinessException('目标用户不存在', 40400, 404);
            }

            // 持锁后复查可用性：改密事务可能同时禁用了账号。
            $this->ensureClientAvailable($lockedUser);

            $lockedUser->tokens()->where('name', 'admin-login-as')->delete();

            return $lockedUser->createToken('admin-login-as', ['*'], now()->addHours(2));
        });

        $this->operationLogService->write(
            userId: (int) $user->id,
            userType: 'client',
            action: 'client.login_as.exchange',
            module: 'auth',
            targetId: (int) $user->id,
            detail: [
                'admin_id' => isset($payload['admin_id']) ? (int) $payload['admin_id'] : null,
            ],
            ipAddress: $ip !== '' ? $ip : null,
        );

        return [
            'token' => $token->plainTextToken,
            'user' => [
                'id' => (int) $user->id,
                'email' => (string) $user->email,
                'nickname' => (string) $user->nickname,
            ],
        ];
    }

    private function verifyPassword(string $plaintext, string $stored, bool &$needsPasswordRehash = false): bool
    {
        // 非 bcrypt 格式（旧站迁移密码等）一律视为无效，绝不交给 Hash::check，避免 500。
        if (! $this->isBcryptHash($stored)) {
            return false;
        }

        $legacyMatched = $this->legacyPasswordVerifier->verify($plaintext, $stored, $needsPasswordRehash);
        if ($legacyMatched !== null) {
            return $legacyMatched;
        }

        return Hash::check($plaintext, $stored);
    }

    /**
     * 判断是否为 bcrypt 哈希。旧站迁移密码（###+MD5 等）不属于 bcrypt，直接登录一律拒绝。
     */
    private function isBcryptHash(string $stored): bool
    {
        return str_starts_with($stored, '$2y$')
            || str_starts_with($stored, '$2a$')
            || str_starts_with($stored, '$2b$');
    }

    private function finishClientLoginAfterResponse(
        int $userId,
        string $loginAt,
        string $ip,
        string $email,
        string $displayName,
        ?string $userAgent,
        bool $loginNotifyEnabled,
        bool $loginLocationAlertEnabled,
        string $previousIp,
        ?string $passwordToRehash = null
    ): void {
        app()->terminating(function () use (
            $userId,
            $loginAt,
            $ip,
            $email,
            $displayName,
            $userAgent,
            $loginNotifyEnabled,
            $loginLocationAlertEnabled,
            $previousIp,
            $passwordToRehash
        ): void {
            $this->persistClientLoginState($userId, $loginAt, $ip, $passwordToRehash);

            if ($email !== '' && $loginNotifyEnabled) {
                $this->dispatchClientLoginEmailAlert($userId, $email, $displayName, $loginAt, $ip, $userAgent);
            }

            if (
                $email !== ''
                && $loginLocationAlertEnabled
                && $previousIp !== ''
                && $previousIp !== $ip
            ) {
                $this->dispatchClientLoginLocationAlert($userId, $email, $displayName, $loginAt, $ip, $previousIp, $userAgent);
            }
        });
    }

    private function persistClientLoginState(int $userId, string $loginAt, string $ip, ?string $passwordToRehash = null): void
    {
        try {
            $payload = [
                'last_login_at' => $loginAt,
                'last_login_ip' => $ip,
            ];

            if ($passwordToRehash !== null && $passwordToRehash !== '') {
                $payload['password'] = Hash::make($passwordToRehash);
            }

            User::query()
                ->whereKey($userId)
                ->update($payload);
        } catch (\Throwable $exception) {
            Log::warning('登录后更新用户登录状态失败', SensitiveDataSanitizer::sanitize([
                'user_id' => $userId,
                'ip' => $ip,
                'message' => $exception->getMessage(),
            ]));
        }
    }

    /**
     * 异步投递登录邮件提醒任务，避免 SMTP 同步阻塞登录响应
     */
    private function dispatchClientLoginEmailAlert(
        int $userId,
        string $email,
        string $displayName,
        string $loginAt,
        string $ip,
        ?string $userAgent
    ): void {
        // sync 队列驱动下降级为同步发送（开发环境兜底）
        if ((string) config('queue.default', 'sync') === 'sync') {
            try {
                $this->notificationService->sendLoginEmailAlertToAddress(
                    $email, $displayName, $loginAt, $ip, $userAgent
                );
            } catch (\Throwable $exception) {
                Log::warning('同步发送用户登录邮件提醒失败', SensitiveDataSanitizer::sanitize([
                    'user_id' => $userId,
                    'email' => $email,
                    'ip' => $ip,
                    'message' => $exception->getMessage(),
                ]));
            }

            return;
        }

        try {
            SendClientLoginEmailAlertJob::dispatch(
                userId: $userId,
                email: $email,
                displayName: $displayName,
                loginAt: $loginAt,
                ip: $ip,
                userAgent: $userAgent,
            );
        } catch (\Throwable $exception) {
            Log::warning('投递用户登录邮件提醒任务失败', SensitiveDataSanitizer::sanitize([
                'user_id' => $userId,
                'email' => $email,
                'ip' => $ip,
                'message' => $exception->getMessage(),
            ]));
        }
    }

    private function dispatchClientLoginFailureAlert(
        int $userId,
        string $email,
        string $displayName,
        string $account,
        string $attemptAt,
        string $ip,
        ?string $userAgent
    ): void {
        if ((string) config('queue.default', 'sync') === 'sync') {
            try {
                $this->notificationService->sendLoginFailureEmailAlertToAddress(
                    $email,
                    $displayName,
                    $account,
                    $attemptAt,
                    $ip,
                    $userAgent
                );
            } catch (\Throwable $exception) {
                Log::warning('同步发送用户登录失败提醒邮件失败', SensitiveDataSanitizer::sanitize([
                    'user_id' => $userId,
                    'email' => $email,
                    'account' => $account,
                    'ip' => $ip,
                    'message' => $exception->getMessage(),
                ]));
            }

            return;
        }

        try {
            SendClientLoginFailureEmailAlertJob::dispatch(
                userId: $userId,
                email: $email,
                displayName: $displayName,
                account: $account,
                attemptAt: $attemptAt,
                ip: $ip,
                userAgent: $userAgent,
            );
        } catch (\Throwable $exception) {
            Log::warning('投递用户登录失败提醒邮件任务失败', SensitiveDataSanitizer::sanitize([
                'user_id' => $userId,
                'email' => $email,
                'account' => $account,
                'ip' => $ip,
                'message' => $exception->getMessage(),
            ]));
        }
    }

    private function dispatchClientLoginLocationAlert(
        int $userId,
        string $email,
        string $displayName,
        string $loginAt,
        string $ip,
        string $previousIp,
        ?string $userAgent
    ): void {
        try {
            $this->notificationService->sendLoginLocationEmailAlertToAddress(
                $email,
                $displayName,
                $loginAt,
                $ip,
                $previousIp,
                $userAgent
            );
        } catch (\Throwable $exception) {
            Log::warning('发送用户异地登录提醒失败', SensitiveDataSanitizer::sanitize([
                'user_id' => $userId,
                'email' => $email,
                'ip' => $ip,
                'previous_ip' => $previousIp,
                'message' => $exception->getMessage(),
            ]));
        }
    }

    private function dispatchPasswordChangedAlert(
        string $email,
        string $displayName,
        string $changedAt,
        string $ip,
        ?string $userAgent
    ): void {
        try {
            $this->notificationService->sendPasswordChangedEmailAlertToAddress(
                $email,
                $displayName,
                $changedAt,
                $ip,
                $userAgent
            );
        } catch (\Throwable $exception) {
            Log::warning('发送密码变更提醒失败', SensitiveDataSanitizer::sanitize([
                'email' => $email,
                'ip' => $ip,
                'message' => $exception->getMessage(),
            ]));
        }
    }

    private function dispatchPhoneChangedAlert(
        string $email,
        string $displayName,
        string $oldPhone,
        string $newPhone,
        string $changedAt,
        string $ip,
        ?string $userAgent
    ): void {
        try {
            $this->notificationService->sendPhoneChangedEmailAlertToAddress(
                $email,
                $displayName,
                $oldPhone,
                $newPhone,
                $changedAt,
                $ip,
                $userAgent
            );
        } catch (\Throwable $exception) {
            Log::warning('发送手机号变更提醒失败', SensitiveDataSanitizer::sanitize([
                'email' => $email,
                'ip' => $ip,
                'message' => $exception->getMessage(),
            ]));
        }
    }

    private function dispatchEmailChangedAlert(
        string $oldEmail,
        string $newEmail,
        string $displayName,
        string $changedAt,
        string $ip,
        ?string $userAgent
    ): void {
        try {
            $this->notificationService->sendEmailChangedEmailAlertToAddress(
                $oldEmail,
                $newEmail,
                $displayName,
                $changedAt,
                $ip,
                $userAgent
            );
        } catch (\Throwable $exception) {
            Log::warning('发送邮箱变更提醒失败', SensitiveDataSanitizer::sanitize([
                'old_email' => $oldEmail,
                'new_email' => $newEmail,
                'ip' => $ip,
                'message' => $exception->getMessage(),
            ]));
        }
    }

    /**
     * @return array<string, bool|int>
     */
    private function normalizeNotificationPreferences(array $data): array
    {
        $loginNotify = (bool) ($data['login_notify'] ?? $data['login_email_alert'] ?? false);

        return [
            'login_email_alert' => $loginNotify,
            'login_notify' => $loginNotify,
            'login_location_alert' => (bool) ($data['login_location_alert'] ?? true),
            'password_change_alert' => (bool) ($data['password_change_alert'] ?? true),
            'phone_change_alert' => (bool) ($data['phone_change_alert'] ?? true),
            'email_change_alert' => (bool) ($data['email_change_alert'] ?? true),
            'marketing_alert' => (bool) ($data['marketing_alert'] ?? false),
        ];
    }

    private function resolveClientForFailureAlert(string $accountType, string $account): ?User
    {
        try {
            return $this->findClientByAccount($accountType, $account);
        } catch (BusinessException $exception) {
            if ($exception->getErrorCode() !== 42200) {
                throw $exception;
            }

            Log::warning('登录失败提醒用户解析失败', SensitiveDataSanitizer::sanitize([
                'account_type' => $accountType,
                'account' => $account,
                'message' => $exception->getMessage(),
            ]));

            return null;
        }
    }

    private function ensureClientAvailable(User $user): void
    {
        if ((int) $user->status !== 1) {
            throw new BusinessException('该客户账号已被禁用，无法代登录', 40300, 403);
        }

        if (method_exists($user, 'trashed') && $user->trashed()) {
            throw new BusinessException('该客户账号不可用，无法代登录', 40300, 403);
        }
    }

    private function refreshClientUser(User $user): User
    {
        return $user->fresh() ?? $user;
    }

    private function buildClientAuthLogDetail(array $detail, array $context = []): array
    {
        $traceId = trim((string) ($context['trace_id'] ?? ''));
        if ($traceId !== '') {
            $detail['trace_id'] = $traceId;
        }

        $userAgent = trim((string) ($context['user_agent'] ?? ''));
        if ($userAgent !== '') {
            $detail['user_agent'] = $userAgent;
        }

        return $detail;
    }

    private function resolveContextIpAddress(array $context = []): ?string
    {
        $ipAddress = trim((string) ($context['ip_address'] ?? ''));

        return $ipAddress !== '' ? $ipAddress : null;
    }

    private function buildAdminLoginAsCacheKey(string $code): string
    {
        return 'auth:admin_login_as:'.hash('sha256', $code);
    }

    private function ensureAdminLoginNotLocked(string $normalizedUsername): void
    {
        $attempts = (int) Cache::store('redis_volatile')->get($this->adminLoginFailureKey($normalizedUsername), 0);

        if ($attempts >= self::ADMIN_LOGIN_MAX_FAILED_ATTEMPTS) {
            throw new BusinessException('登录失败次数过多，请 30 分钟后再试', 42900, 429);
        }
    }

    private function recordAdminLoginFailure(string $normalizedUsername): void
    {
        $key = $this->adminLoginFailureKey($normalizedUsername);
        $attempts = (int) Cache::store('redis_volatile')->increment($key, 1);
        Cache::store('redis_volatile')->put($key, $attempts, now()->addSeconds(self::ADMIN_LOGIN_FAILED_WINDOW_SECONDS));
    }

    private function clearAdminLoginFailures(string $normalizedUsername): void
    {
        Cache::store('redis_volatile')->forget($this->adminLoginFailureKey($normalizedUsername));
    }

    private function adminLoginFailureKey(string $normalizedUsername): string
    {
        return 'admin-login-fail:account:'.sha1($normalizedUsername);
    }

    private function hashLoginAsUserAgent(string $userAgent): string
    {
        $normalized = preg_replace('/\s+/u', ' ', mb_strtolower(trim($userAgent), 'UTF-8')) ?? '';

        return $normalized !== '' ? hash('sha256', $normalized) : '';
    }
}
