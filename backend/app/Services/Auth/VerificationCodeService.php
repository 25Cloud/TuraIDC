<?php

namespace App\Services\Auth;

use App\Support\CacheKey;
use Illuminate\Support\Facades\Cache;

class VerificationCodeService
{
    /**
     * 存储邮箱验证码
     */
    public function storeEmailCode(int|string $userId, string $email, string $code, int $minutes = 10): void
    {
        $key = $this->emailCodeKey($userId, $email);
        Cache::store('redis_volatile')->put(CacheKey::verificationCode($key), $code, now()->addMinutes($minutes));
    }

    /**
     * 验证邮箱验证码
     */
    public function verifyEmailCode(int|string $userId, string $email, string $code): bool
    {
        $key = $this->emailCodeKey($userId, $email);
        $cached = Cache::store('redis_volatile')->get(CacheKey::verificationCode($key));

        if ($cached === $code) {
            Cache::store('redis_volatile')->forget(CacheKey::verificationCode($key));

            return true;
        }

        return false;
    }

    /**
     * 存储手机验证码
     */
    public function storePhoneCode(int|string $userId, string $phone, string $code, int $minutes = 5): void
    {
        $key = $this->phoneCodeKey($userId, $phone);
        Cache::store('redis_volatile')->put(CacheKey::verificationCode($key), $code, now()->addMinutes($minutes));
    }

    /**
     * 验证手机验证码
     */
    public function verifyPhoneCode(int|string $userId, string $phone, string $code): bool
    {
        $key = $this->phoneCodeKey($userId, $phone);
        $cached = Cache::store('redis_volatile')->get(CacheKey::verificationCode($key));

        if ($cached === $code) {
            Cache::store('redis_volatile')->forget(CacheKey::verificationCode($key));

            return true;
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
}
