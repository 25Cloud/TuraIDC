<?php

declare(strict_types=1);

namespace App\Http\Controllers\Client\V2;

use App\Exceptions\BusinessException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Client\V2\Auth\ExchangeLoginAsCodeRequest;
use App\Http\Requests\Client\V2\Auth\LoginByCodeRequest;
use App\Http\Requests\Client\V2\Auth\LoginRequest;
use App\Http\Requests\Client\V2\Auth\RegisterRequest;
use App\Http\Requests\Client\V2\Auth\ResetPasswordRequest;
use App\Http\Requests\Client\V2\Auth\SendEmailCodeRequest;
use App\Http\Requests\Client\V2\Auth\SendPhoneCodeRequest;
use App\Http\Requests\Client\V2\Auth\UpdateAlipayAccountRequest;
use App\Http\Requests\Client\V2\Auth\UpdateEmailRequest;
use App\Http\Requests\Client\V2\Auth\UpdateNotificationPreferencesRequest;
use App\Http\Requests\Client\V2\Auth\UpdatePasswordRequest;
use App\Http\Requests\Client\V2\Auth\UpdatePhoneRequest;
use App\Http\Requests\Client\V2\Auth\UpdateProfileRequest;
use App\Models\User;
use App\Services\Auth\AuthService;
use App\Services\Auth\GeeTestService;
use App\Services\Auth\LoginRiskControlService;
use App\Services\Auth\MessageRateLimitService;
use App\Services\Auth\VerificationCodeService;
use App\Services\System\NotificationService;
use App\Services\System\SmsService;
use App\Support\AccountIdentifier;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\PersonalAccessToken;

class AuthController extends Controller
{
    public function __construct(
        private AuthService $authService,
        private GeeTestService $geeTestService,
        private LoginRiskControlService $loginRiskControlService,
        private MessageRateLimitService $messageRateLimitService,
        private NotificationService $notificationService,
        private SmsService $smsService,
        private VerificationCodeService $codeService,
    ) {}

    /**
     * 客户登录
     */
    public function login(LoginRequest $request)
    {
        $data = $request->validated();

        $normalizedAccount = AccountIdentifier::normalizeAccount((string) $data['account']);
        $requestIp = (string) $request->ip();

        // 验证码插件未启用时的兜底锁定：失败次数超阈值直接拒绝登录
        if ($this->loginRiskControlService->isLoginLocked($normalizedAccount, $requestIp)) {
            return $this->error(42900, '登录尝试次数过多，请稍后再试');
        }

        if (
            $this->loginRiskControlService->shouldRequireCaptcha($normalizedAccount, $requestIp)
            && ($response = $this->ensureGeeTestVerified($request, true))
        ) {
            return $response;
        }

        try {
            $result = $this->authService->clientLogin(
                $normalizedAccount,
                (string) $data['password'],
                $requestIp,
                $request->userAgent()
            );
        } catch (BusinessException $exception) {
            if ($exception->getErrorCode() === 40100) {
                $this->loginRiskControlService->recordFailedAttempt($normalizedAccount, $requestIp);
                $this->authService->notifyClientLoginFailureOnce(
                    $normalizedAccount,
                    $requestIp,
                    $request->userAgent()
                );
            }

            throw $exception;
        }

        $this->loginRiskControlService->clearSuccessfulLogin($normalizedAccount, $requestIp);

        return $this->success($result, '登录成功');
    }

    /**
     * 客户验证码登录
     */
    public function loginByCode(LoginByCodeRequest $request)
    {
        $data = $request->validated();

        $account = AccountIdentifier::normalizeAccount((string) $data['account']);
        $accountType = AccountIdentifier::detectType($account);
        $code = (string) $data['code'];

        // 验证码插件未启用时的兜底锁定：防止验证码登录通道被爆破。
        // 验证码本身就是第二因素，不连带密码通道的账号维度软锁定（避免第三方用密码通道失败锁定他人验证码登录）。
        if ($this->loginRiskControlService->isLoginLocked($account, (string) $request->ip(), false)) {
            return $this->error(42900, '登录尝试次数过多，请稍后再试');
        }

        // 先校验验证码再解析用户，未注册与验证码错误统一文案并保持时序一致
        $user = $this->authService->findClientByAccount($accountType, $account);

        $verified = $accountType === 'phone'
            ? $this->codeService->verifyPhoneCode('guest', $account, $code)
            : $this->codeService->verifyEmailCode('guest', $account, $code);

        if (! $verified && $user) {
            $verified = $accountType === 'phone'
                ? $this->codeService->verifyPhoneCode((int) $user->id, $account, $code)
                : $this->codeService->verifyEmailCode((int) $user->id, $account, $code);
        }

        if (! $verified || ! $user) {
            return $this->error(42200, '账号或验证码错误');
        }

        $result = $this->authService->clientLoginByCode(
            $account,
            $code,
            (string) $request->ip(),
            $request->userAgent()
        );

        return $this->success($result, '登录成功');
    }

    /**
     * 客户注册
     */
    public function register(RegisterRequest $request)
    {
        $data = $request->validated();

        $accountType = AccountIdentifier::detectType((string) $data['account']);
        $account = AccountIdentifier::normalizeAccount((string) $data['account']);
        $verified = $accountType === 'phone'
            ? $this->codeService->verifyPhoneCode('guest', $account, (string) $data['code'])
            : $this->codeService->verifyEmailCode('guest', $account, (string) $data['code']);

        if (! $verified) {
            return $this->error(42200, $accountType === 'phone' ? '短信验证码错误或已过期' : '邮箱验证码错误或已过期');
        }

        $result = $this->authService->clientRegister(
            array_merge($data, [
                'account' => $account,
                'email' => $accountType === 'email' ? $account : ($data['email'] ?? null),
                'phone' => $accountType === 'phone' ? $account : ($data['phone'] ?? null),
            ]),
            $request->ip()
        );

        return $this->success($result, '注册成功');
    }

    public function captchaConfig()
    {
        return $this->success([
            'enabled' => $this->geeTestService->isEnabled(),
            'captcha_id' => $this->geeTestService->getCaptchaId(),
            'script_url' => $this->geeTestService->getScriptUrl(),
        ]);
    }

    public function captchaScript()
    {
        try {
            $scriptContent = $this->geeTestService->getScriptContent();
        } catch (\Throwable $exception) {
            report($exception);

            $scriptContent = $this->geeTestService->getFallbackScriptContent();
        }

        return response($scriptContent, 200, [
            'Content-Type' => 'application/javascript; charset=UTF-8',
            'Cache-Control' => 'public, max-age=43200',
        ]);
    }

    public function exchangeLoginAsCode(ExchangeLoginAsCodeRequest $request)
    {
        $data = $request->validated();

        return $this->success(
            $this->authService->exchangeAdminLoginAsCode(
                (string) $data['code'],
                (string) $request->ip(),
                (string) ($request->userAgent() ?? '')
            ),
            '代登录成功'
        );
    }

    /**
     * 获取当前用户信息
     */
    public function info(Request $request)
    {
        $user = $request->user();
        $user->loadMissing([
            'memberLevel',
            'account',
        ]);
        $memberLevel = $user->memberLevel;

        return $this->success([
            'id' => $user->id,
            'email' => (string) ($user->email ?? ''),
            'nickname' => $user->nickname,
            'display_name' => (string) ($user->display_name ?? ''),
            'phone' => (string) ($user->phone ?? ''),
            'cash_balance' => (string) $user->balance,
            'credit_limit' => (string) $user->credit_limit,
            'referral_frozen_balance' => (string) $user->referral_frozen_amount,
            'referral_available_balance' => (string) $user->referral_available_amount,
            'referral_pending_withdrawal_balance' => (string) $user->referral_withdrawing_amount,
            'referral_withdrawn_balance' => (string) $user->referral_withdrawn_amount,
            'referral_code' => $user->referral_code,
            'referrer_user_id' => $user->referrer_user_id,
            'member_level_id' => $user->member_level_id,
            'total_sales_amount' => $user->total_sales_amount,
            'member_level' => $memberLevel ? [
                'id' => $memberLevel->id,
                'name' => $memberLevel->name,
                'code' => $memberLevel->code,
                'reward_rate' => $memberLevel->reward_rate,
            ] : null,
            'status' => $user->status,
            'is_verified' => $user->is_verified,
            'real_name' => $user->real_name,
            'id_card_masked' => $this->maskIdCard((string) $user->id_card),
            'verification_status' => $user->verification_status,
            'verification_message' => $user->verification_message,
            'verification_certify_id' => $user->verification_certify_id,
            'login_email_alert' => (int) $user->login_email_alert,
            'login_notify' => (int) (($user->login_notify ?? null) ?? $user->login_email_alert),
            'login_location_alert' => (int) ($user->login_location_alert ?? 1),
            'password_change_alert' => (int) ($user->password_change_alert ?? 1),
            'phone_change_alert' => (int) ($user->phone_change_alert ?? 1),
            'email_change_alert' => (int) ($user->email_change_alert ?? 1),
            'marketing_alert' => (int) ($user->marketing_alert ?? 0),
            'alipay_account' => [
                'real_name' => $user->alipay_real_name,
                'account' => $user->alipay_account,
                'is_bound' => $this->hasBoundAlipayAccount($user),
            ],
            'last_login_at' => $user->last_login_at?->format('Y-m-d H:i:s'),
            'last_login_ip' => (string) ($user->last_login_ip ?? ''),
            'verified_at' => $user->verified_at?->format('Y-m-d H:i:s'),
            'created_at' => $user->created_at?->format('Y-m-d H:i:s'),
        ]);
    }

    /**
     * 登出
     */
    public function logout(Request $request)
    {
        $request->user()->currentAccessToken()->delete();

        return $this->success(null, '已退出登录');
    }

    public function updateProfile(UpdateProfileRequest $request)
    {
        $data = $request->validated();

        $freshUser = $this->authService->updateClientProfile(
            $request->user(),
            $data,
            $this->clientOperationContext($request)
        );

        return $this->success([
            'nickname' => $freshUser?->nickname,
            'display_name' => $freshUser?->display_name,
        ], '资料更新成功');
    }

    public function alipayAccount(Request $request)
    {
        $user = $request->user();

        return $this->success([
            'real_name' => $user->alipay_real_name,
            'account' => $user->alipay_account,
            'is_bound' => $this->hasBoundAlipayAccount($user),
        ]);
    }

    public function updateAlipayAccount(UpdateAlipayAccountRequest $request)
    {
        $data = $request->validated();

        $user = $request->user();
        $requestIp = (string) $request->ip();
        $account = AccountIdentifier::normalizeAccount((string) ($user->email ?? $user->phone ?? ''));

        // 密码二次确认失败会累积登录风险计数；先按锁定状态拒绝，防止该接口成为
        // 无速率限制的密码爆破预言机（拿到 token 即可高频尝试、凭响应差异还原密码）。
        if ($this->loginRiskControlService->isLoginLocked($account, $requestIp)) {
            return $this->error(42900, '登录尝试次数过多，请稍后再试');
        }

        // 提现账户改绑必须登录密码二次确认，防止登录态被滥用直接改绑提现账户
        if (! Hash::check((string) $data['password'], (string) $user->password)) {
            $this->loginRiskControlService->recordFailedAttempt($account, $requestIp);

            return $this->error(42200, '登录密码错误');
        }

        // 密码已确认：解除该账号密码通道的失败计数，避免后续验证码校验失败把已确认密码的用户连带锁定。
        $this->loginRiskControlService->clearSuccessfulLogin($account, $requestIp);

        $phone = trim((string) $data['account']);
        $verified = $this->codeService->verifyPhoneCode($user->id, $phone, (string) $data['code']);

        if (! $verified) {
            return $this->error(42200, '短信验证码错误或已过期');
        }

        $freshUser = $this->authService->updateClientAlipayAccount(
            $user,
            [
                'real_name' => (string) $data['real_name'],
                'account' => $phone,
            ],
            $this->clientOperationContext($request)
        );

        return $this->success([
            'real_name' => $freshUser?->alipay_real_name,
            'account' => $freshUser?->alipay_account,
            'is_bound' => $freshUser ? $this->hasBoundAlipayAccount($freshUser) : true,
        ], '支付宝资料保存成功');
    }

    public function notificationPreferences(Request $request)
    {
        $user = $request->user();

        return $this->success([
            'login_notify' => (int) (($user->login_notify ?? null) ?? $user->login_email_alert),
            'login_location_alert' => (int) ($user->login_location_alert ?? 1),
            'password_change_alert' => (int) ($user->password_change_alert ?? 1),
            'phone_change_alert' => (int) ($user->phone_change_alert ?? 1),
            'email_change_alert' => (int) ($user->email_change_alert ?? 1),
            'marketing_alert' => (int) ($user->marketing_alert ?? 0),
        ]);
    }

    public function updateNotificationPreferences(UpdateNotificationPreferencesRequest $request)
    {
        $data = $request->validated();

        $freshUser = $this->authService->updateClientNotificationPreferences(
            $request->user(),
            $data,
            $this->clientOperationContext($request)
        );

        return $this->success([
            'login_notify' => (int) (($freshUser->login_notify ?? null) ?? $freshUser->login_email_alert),
            'login_location_alert' => (int) ($freshUser->login_location_alert ?? 1),
            'password_change_alert' => (int) ($freshUser->password_change_alert ?? 1),
            'phone_change_alert' => (int) ($freshUser->phone_change_alert ?? 1),
            'email_change_alert' => (int) ($freshUser->email_change_alert ?? 1),
            'marketing_alert' => (int) ($freshUser->marketing_alert ?? 0),
        ], '通知设置更新成功');
    }

    public function updatePhone(UpdatePhoneRequest $request)
    {
        $data = $request->validated();

        $user = $request->user();
        $phone = (string) $data['phone'];
        $oldPhone = trim((string) ($user->phone ?? ''));

        // 已有绑定手机时，必须验证旧手机验证码，防止登录态被直接换绑
        if ($oldPhone !== '' && ! $this->codeService->verifyPhoneCode($user->id, $oldPhone, (string) $data['old_code'])) {
            return $this->error(42200, '原手机验证码错误或已过期');
        }

        $verified = $this->codeService->verifyPhoneCode($user->id, $phone, (string) $data['code']);
        if (! $verified) {
            return $this->error(42200, '短信验证码错误或已过期');
        }

        $freshUser = $this->authService->updateClientPhone(
            $user,
            $phone,
            $this->clientOperationContext($request)
        );

        return $this->success([
            'phone' => $freshUser->phone,
        ], '手机号修改成功');
    }

    public function sendPhoneCode(SendPhoneCodeRequest $request)
    {
        $data = $request->validated();

        if ($response = $this->ensureGeeTestVerified($request)) {
            return $response;
        }

        $phone = (string) $data['phone'];

        // 登录场景：校验账号是否存在且正常
        if (($data['purpose'] ?? null) === 'login') {
            $accountType = AccountIdentifier::detectType($phone);
            $user = $this->authService->findClientByAccount($accountType, $phone);
            if (! $user) {
                return $this->error(42200, '手机号未注册');
            }
            if ($user->status !== 1) {
                return $this->error(40300, '账号已被禁用');
            }
        }

        // 旧手机验证场景：目标必须是当前账号已绑定的手机，防止向任意号码发送旧码
        if (in_array((string) ($data['purpose'] ?? ''), ['verify_bound_phone', 'verify_phone'], true)) {
            $currentPhone = trim((string) ($request->user()->phone ?? ''));
            if ($currentPhone === '' || $currentPhone !== $phone) {
                return $this->error(42200, '目标手机号与当前绑定不一致');
            }
        }

        $userId = $this->resolveCodeOwnerId($request);
        $code = (string) random_int(100000, 999999);
        $ip = $request->ip();

        if ($response = $this->ensureMessageRateLimitPassed('sms', $phone, $ip)) {
            return $response;
        }

        try {
            $this->smsService->sendVerifyCode($phone, $code, [
                'purpose' => (string) ($data['purpose'] ?? 'generic'),
            ]);
        } catch (\Throwable $exception) {
            report($exception);

            return $this->error(42200, '短信服务暂不可用，请稍后重试');
        }

        $this->messageRateLimitService->hit('sms', $phone, $ip);
        $this->codeService->storePhoneCode($userId, $phone, $code);

        return $this->success(null, '短信验证码已发送');
    }

    public function updateEmail(UpdateEmailRequest $request)
    {
        $data = $request->validated();

        $user = $request->user();
        $email = (string) $data['email'];
        $oldEmail = trim((string) ($user->email ?? ''));

        // 已有绑定邮箱时，必须验证旧邮箱验证码，防止登录态被直接换绑
        if ($oldEmail !== '' && ! $this->codeService->verifyEmailCode($user->id, $oldEmail, (string) $data['old_code'])) {
            return $this->error(42200, '原邮箱验证码错误或已过期');
        }

        $verified = $this->codeService->verifyEmailCode($user->id, $email, (string) $data['code']);
        if (! $verified) {
            return $this->error(42200, '邮箱验证码错误或已过期');
        }

        $freshUser = $this->authService->updateClientEmail(
            $user,
            $email,
            $this->clientOperationContext($request)
        );

        return $this->success([
            'email' => $freshUser->email,
        ], '邮箱修改成功');
    }

    public function sendEmailCode(SendEmailCodeRequest $request)
    {
        $data = $request->validated();

        if ($response = $this->ensureGeeTestVerified($request)) {
            return $response;
        }

        $email = (string) $data['email'];

        // 登录场景：校验账号是否存在且正常
        if (($data['purpose'] ?? null) === 'login') {
            $accountType = AccountIdentifier::detectType($email);
            $user = $this->authService->findClientByAccount($accountType, $email);
            if (! $user) {
                return $this->error(42200, '邮箱未注册');
            }
            if ($user->status !== 1) {
                return $this->error(40300, '账号已被禁用');
            }
        }

        // 旧邮箱验证场景：目标必须是当前账号已绑定的邮箱，防止向任意邮箱发送旧码
        if (in_array((string) ($data['purpose'] ?? ''), ['verify_bound_email', 'change_email'], true)) {
            $currentEmail = trim((string) ($request->user()->email ?? ''));
            if ($currentEmail === '' || $currentEmail !== $email) {
                return $this->error(42200, '目标邮箱与当前绑定不一致');
            }
        }

        $userId = $this->resolveCodeOwnerId($request);
        $code = (string) random_int(100000, 999999);
        $ip = $request->ip();

        if ($response = $this->ensureMessageRateLimitPassed('email', $email, $ip)) {
            return $response;
        }

        try {
            $this->notificationService->sendEmailCode($email, $code);
        } catch (\Throwable $exception) {
            report($exception);

            return $this->error(42200, '邮件服务暂不可用，请稍后重试');
        }

        $this->messageRateLimitService->hit('email', $email, $ip);
        $this->codeService->storeEmailCode($userId, $email, $code);

        return $this->success(null, '邮箱验证码已发送');
    }

    public function resetPassword(ResetPasswordRequest $request)
    {
        $data = $request->validated();

        $accountType = AccountIdentifier::detectType((string) $data['account']);
        $account = AccountIdentifier::normalizeAccount((string) $data['account']);

        // 先校验验证码再解析用户，未注册与验证码错误统一文案并保持时序一致
        $verified = $accountType === 'phone'
            ? $this->codeService->verifyPhoneCode('guest', $account, (string) $data['code'])
            : $this->codeService->verifyEmailCode('guest', $account, (string) $data['code']);

        $user = $this->authService->findClientByAccount($accountType, $account);

        if (! $verified && $user) {
            $verified = $accountType === 'phone'
                ? $this->codeService->verifyPhoneCode((int) $user->id, $account, (string) $data['code'])
                : $this->codeService->verifyEmailCode((int) $user->id, $account, (string) $data['code']);
        }

        if (! $verified || ! $user) {
            return $this->error(42200, '账号或验证码错误');
        }

        $this->authService->resetClientPassword($user, (string) $data['password']);

        return $this->success(null, '密码重置成功');
    }

    public function updatePassword(UpdatePasswordRequest $request)
    {
        $data = $request->validated();

        $this->authService->updateClientPassword(
            $request->user(),
            (string) $data['oldPassword'],
            (string) $data['newPassword'],
            $this->clientOperationContext($request)
        );

        return $this->success(null, '密码修改成功');
    }

    private function clientOperationContext(Request $request): array
    {
        return [
            'ip_address' => (string) $request->ip(),
            'user_agent' => (string) ($request->userAgent() ?? ''),
            'trace_id' => (string) $request->header('X-Request-Id', ''),
        ];
    }

    private function resolveCodeOwnerId(Request $request): int|string
    {
        $user = $request->user();
        if ($user) {
            return $user->id;
        }

        $token = $request->bearerToken();
        if ($token) {
            $accessToken = PersonalAccessToken::findToken($token);
            if ($accessToken?->tokenable instanceof User) {
                return (int) $accessToken->tokenable->id;
            }
        }

        return 'guest';
    }

    private function hasBoundAlipayAccount(User $user): bool
    {
        return trim((string) $user->alipay_real_name) !== ''
            && trim((string) $user->alipay_account) !== '';
    }

    private function ensureGeeTestVerified(Request $request, bool $captchaRequired = false)
    {
        $result = $this->geeTestService->verify($request->input('captcha'), (string) $request->ip());

        if (! ($result['ok'] ?? false)) {
            return $this->error(
                42210,
                $result['message'] ?? '行为验证未通过，请重试',
                $captchaRequired ? ['captcha_required' => true] : null
            );
        }

        return null;
    }

    private function ensureMessageRateLimitPassed(string $channel, string $target, ?string $ip)
    {
        $result = $this->messageRateLimitService->check($channel, $target, $ip);

        if (! ($result['ok'] ?? false)) {
            return $this->error(42200, $result['message'] ?? '发送过于频繁，请稍后重试');
        }

        return null;
    }

    private function maskIdCard(string $idCard): string
    {
        if ($idCard === '') {
            return '';
        }

        $length = mb_strlen($idCard);
        if ($length <= 8) {
            return $idCard;
        }

        return mb_substr($idCard, 0, 1).str_repeat('*', max($length - 2, 1)).mb_substr($idCard, -1);
    }
}
