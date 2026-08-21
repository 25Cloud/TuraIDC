<?php

declare(strict_types=1);

namespace App\Services\Auth;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\RateLimiter;

class LoginRiskControlService
{
    /**
     * 风控命名空间。
     *
     * 计数键此前只按 sha1(account) 归一，管理端与客户端共用同一份计数：
     * 名为 cerbo 的管理员与名为 cerbo 的客户会互相累加失败次数，也会互相解锁。
     * 加入作用域后两侧完全隔离。
     *
     * 注意：作用域参与 hash 计算，本次升级会使既有计数键全部失效（相当于清零一次），
     * 这个方向只会放宽而不会误锁，属于可接受的一次性影响。
     */
    public const SCOPE_CLIENT = 'client';

    public const SCOPE_ADMIN = 'admin';

    private const ACCOUNT_IP_MAX_ATTEMPTS = 1;

    private const ACCOUNT_IP_DECAY_SECONDS = 900;

    private const ACCOUNT_MAX_ATTEMPTS = 1;

    private const ACCOUNT_DECAY_SECONDS = 1800;

    private const IP_MAX_ATTEMPTS = 8;

    private const IP_DECAY_SECONDS = 1800;

    // GeeTest 未启用时的软锁定阈值：比验证码触发阈值宽松，避免误伤正常用户
    private const SOFT_LOCK_ACCOUNT_IP_MAX_ATTEMPTS = 5;

    private const SOFT_LOCK_ACCOUNT_MAX_ATTEMPTS = 10;

    // 账号维度软锁定仅当失败来自多个不同 IP（多 IP 轮换攻击特征）时才生效，
    // 防止第三方从单一 IP 用目标账号名反复尝试即可锁定他人账号（账号级 DoS）。
    private const SOFT_LOCK_ACCOUNT_MIN_IPS = 3;

    private const ACCOUNT_FAILED_IPS_DECAY_SECONDS = 1800;

    private const FAILURE_ALERT_DECAY_SECONDS = self::ACCOUNT_DECAY_SECONDS;

    public function __construct(
        private GeeTestService $geeTestService,
    ) {}

    /**
     * GeeTest 未启用时的兜底锁定：账号+IP 或账号维度失败次数超阈值即锁定登录，
     * 防止验证码插件未配置时暴力破解完全无防护。
     *
     * $includeAccountDimension 为 true 时才启用账号维度（多 IP 轮换触发）；
     * 验证码登录通道应传 false——验证码本身就是第二因素，不应被密码通道的失败连带封死。
     */
    /**
     * @param  bool|null  $captchaActive  本场景是否真的会要求人机验证。
     *                                    传 null 时退回旧行为（仅看插件是否启用）。
     *                                    有了场景开关之后，「插件已启用」不再等于「本场景受验证码保护」——
     *                                    若插件启用而该场景开关被关掉，软锁定必须继续生效，
     *                                    否则该入口会既无验证码又无失败次数限制。
     */
    public function isLoginLocked(
        string $account,
        ?string $ip = null,
        bool $includeAccountDimension = true,
        string $scope = self::SCOPE_CLIENT,
        ?bool $captchaActive = null,
    ): bool {
        if ($captchaActive ?? $this->geeTestService->isEnabled()) {
            return false;
        }

        $account = $this->normalizeAccount($account);
        $ip = $this->normalizeIp($ip);
        $scope = $this->normalizeScope($scope);

        if ($account !== '' && $ip !== '' && RateLimiter::tooManyAttempts(
            $this->accountIpKey($scope, $account, $ip),
            self::SOFT_LOCK_ACCOUNT_IP_MAX_ATTEMPTS
        )) {
            return true;
        }

        return $includeAccountDimension
            && $account !== ''
            && $this->distinctFailedIpCount($scope, $account) >= self::SOFT_LOCK_ACCOUNT_MIN_IPS
            && RateLimiter::tooManyAttempts(
                $this->accountKey($scope, $account),
                self::SOFT_LOCK_ACCOUNT_MAX_ATTEMPTS
            );
    }

    public function shouldRequireCaptcha(
        string $account,
        ?string $ip = null,
        string $scope = self::SCOPE_CLIENT,
    ): bool {
        if (! $this->geeTestService->isEnabled()) {
            return false;
        }

        $account = $this->normalizeAccount($account);
        $ip = $this->normalizeIp($ip);
        $scope = $this->normalizeScope($scope);

        if ($account !== '' && $ip !== '' && RateLimiter::tooManyAttempts(
            $this->accountIpKey($scope, $account, $ip),
            self::ACCOUNT_IP_MAX_ATTEMPTS
        )) {
            return true;
        }

        if ($account !== '' && RateLimiter::tooManyAttempts(
            $this->accountKey($scope, $account),
            self::ACCOUNT_MAX_ATTEMPTS
        )) {
            return true;
        }

        return $ip !== '' && RateLimiter::tooManyAttempts(
            $this->ipKey($scope, $ip),
            self::IP_MAX_ATTEMPTS
        );
    }

    public function recordFailedAttempt(
        string $account,
        ?string $ip = null,
        string $scope = self::SCOPE_CLIENT,
    ): void {
        $account = $this->normalizeAccount($account);
        $ip = $this->normalizeIp($ip);
        $scope = $this->normalizeScope($scope);

        if ($account !== '' && $ip !== '') {
            RateLimiter::hit($this->accountIpKey($scope, $account, $ip), self::ACCOUNT_IP_DECAY_SECONDS);
            $this->recordFailedIpForAccount($scope, $account, $ip);
        }

        if ($account !== '') {
            RateLimiter::hit($this->accountKey($scope, $account), self::ACCOUNT_DECAY_SECONDS);
        }

        if ($ip !== '') {
            RateLimiter::hit($this->ipKey($scope, $ip), self::IP_DECAY_SECONDS);
        }
    }

    public function clearSuccessfulLogin(
        string $account,
        ?string $ip = null,
        string $scope = self::SCOPE_CLIENT,
    ): void {
        $account = $this->normalizeAccount($account);
        $ip = $this->normalizeIp($ip);
        $scope = $this->normalizeScope($scope);

        if ($account !== '' && $ip !== '') {
            RateLimiter::clear($this->accountIpKey($scope, $account, $ip));
        }

        if ($account !== '') {
            RateLimiter::clear($this->accountKey($scope, $account));
            Cache::store('redis_volatile')->forget($this->failureAlertKey($scope, $account));
            Cache::store('redis_volatile')->forget($this->accountFailedIpsKey($scope, $account));
        }

        if ($ip !== '') {
            RateLimiter::clear($this->ipKey($scope, $ip));
        }
    }

    public function acquireFailureAlertLock(string $account, string $scope = self::SCOPE_CLIENT): bool
    {
        $account = $this->normalizeAccount($account);
        if ($account === '') {
            return false;
        }

        return Cache::store('redis_volatile')->add(
            $this->failureAlertKey($this->normalizeScope($scope), $account),
            1,
            now()->addSeconds(self::FAILURE_ALERT_DECAY_SECONDS)
        );
    }

    private function accountIpKey(string $scope, string $account, string $ip): string
    {
        return 'login-risk:account-ip:'.sha1($scope.'|'.$account.'|'.$ip);
    }

    private function accountKey(string $scope, string $account): string
    {
        return 'login-risk:account:'.sha1($scope.'|'.$account);
    }

    private function ipKey(string $scope, string $ip): string
    {
        return 'login-risk:ip:'.sha1($scope.'|'.$ip);
    }

    private function failureAlertKey(string $scope, string $account): string
    {
        return 'login-risk:failure-alert:'.sha1($scope.'|'.$account);
    }

    private function accountFailedIpsKey(string $scope, string $account): string
    {
        return 'login-risk:failed-ips:'.sha1($scope.'|'.$account);
    }

    private function recordFailedIpForAccount(string $scope, string $account, string $ip): void
    {
        $store = Cache::store('redis_volatile');
        $key = $this->accountFailedIpsKey($scope, $account);
        $ips = (array) $store->get($key, []);
        $ips[$ip] = true;
        $store->put($key, $ips, now()->addSeconds(self::ACCOUNT_FAILED_IPS_DECAY_SECONDS));
    }

    private function distinctFailedIpCount(string $scope, string $account): int
    {
        try {
            $ips = (array) Cache::store('redis_volatile')->get($this->accountFailedIpsKey($scope, $account), []);
        } catch (\Throwable) {
            return 0;
        }

        return count($ips);
    }

    /**
     * 归一化作用域，非法值统一落到客户端作用域（不放宽任何限制）。
     */
    private function normalizeScope(string $scope): string
    {
        $normalized = mb_strtolower(trim($scope));

        return in_array($normalized, [self::SCOPE_CLIENT, self::SCOPE_ADMIN], true)
            ? $normalized
            : self::SCOPE_CLIENT;
    }

    private function normalizeAccount(string $account): string
    {
        return mb_strtolower(trim($account));
    }

    private function normalizeIp(?string $ip): string
    {
        return trim((string) $ip);
    }
}
