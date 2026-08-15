<?php

namespace App\Services\Auth;

use App\Support\CacheKey;
use Illuminate\Support\Facades\Cache;

class VerificationCodeService
{
    private const MAX_VERIFY_ATTEMPTS = 5;

    /**
     * 存储邮箱验证码
     */
    public function storeEmailCode(int|string $userId, string $email, string $code, int $minutes = 10): void
    {
        $key = $this->emailCodeKey($userId, $email);
        Cache::store('redis_volatile')->put(CacheKey::verificationCode($key), $code, now()->addMinutes($minutes));
        Cache::store('redis_volatile')->forget($this->emailCodeAttemptKey($userId, $email));
    }

    /**
     * 验证邮箱验证码
     */
    public function verifyEmailCode(int|string $userId, string $email, string $code): bool
    {
        $key = $this->emailCodeKey($userId, $email);
        $cacheKey = CacheKey::verificationCode($key);
        $cached = Cache::store('redis_volatile')->get($cacheKey);

        if ($cached !== null && $cached === $code) {
            Cache::store('redis_volatile')->forget($cacheKey);
            Cache::store('redis_volatile')->forget($this->emailCodeAttemptKey($userId, $email));

            return true;
        }

        return $cached !== null && $this->recordFailedAttempt($this->emailCodeAttemptKey($userId, $email), $cacheKey);
    }

    /**
     * 存储手机验证码
     */
    public function storePhoneCode(int|string $userId, string $phone, string $code, int $minutes = 5): void
    {
        $key = $this->phoneCodeKey($userId, $phone);
        Cache::store('redis_volatile')->put(CacheKey::verificationCode($key), $code, now()->addMinutes($minutes));
        Cache::store('redis_volatile')->forget($this->phoneCodeAttemptKey($userId, $phone));
    }

    /**
     * 验证手机验证码
     */
    public function verifyPhoneCode(int|string $userId, string $phone, string $code): bool
    {
        $key = $this->phoneCodeKey($userId, $phone);
        $cacheKey = CacheKey::verificationCode($key);
        $cached = Cache::store('redis_volatile')->get($cacheKey);

        if ($cached !== null && $cached === $code) {
            Cache::store('redis_volatile')->forget($cacheKey);
            Cache::store('redis_volatile')->forget($this->phoneCodeAttemptKey($userId, $phone));

            return true;
        }

        return $cached !== null && $this->recordFailedAttempt($this->phoneCodeAttemptKey($userId, $phone), $cacheKey);
    }

    /**
     * 记录错误尝试，超过上限后作废验证码
     */
    private function recordFailedAttempt(string $attemptKey, string $cacheKey): bool
    {
        $attempts = (int) Cache::store('redis_volatile')->increment($attemptKey, 1);
        Cache::store('redis_volatile')->put($attemptKey, $attempts, now()->addMinutes(10));

        if ($attempts >= self::MAX_VERIFY_ATTEMPTS) {
            Cache::store('redis_volatile')->forget($cacheKey);
            Cache::store('redis_volatile')->forget($attemptKey);
        }

        return false;
    }

    private function emailCodeKey(int|string $userId, string $email): string
    {
        return 'email_code:'.$userId.':'.md5(mb_strtolower($email));
    }

    private function phoneCodeKey(int|string $userId, string $phone): string
    {
        return 'phone_code:'.$userId.':'.preg_replace('/\D+/', '', $phone);
    }

    private function emailCodeAttemptKey(int|string $userId, string $email): string
    {
        return 'email_code_attempts:'.$userId.':'.md5(mb_strtolower($email));
    }

    private function phoneCodeAttemptKey(int|string $userId, string $phone): string
    {
        return 'phone_code_attempts:'.$userId.':'.preg_replace('/\D+/', '', $phone);
    }
}
