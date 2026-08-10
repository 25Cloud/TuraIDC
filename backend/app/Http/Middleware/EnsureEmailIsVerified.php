<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Support\ApiResponseBuilder;
use Closure;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureEmailIsVerified
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user) {
            return ApiResponseBuilder::error(40100, '未认证');
        }

        if ($user instanceof MustVerifyEmail && ! $user->hasVerifiedEmail()) {
            return $this->forbiddenResponse('请先完成邮箱验证');
        }

        if ($this->supportsIdentityVerification($user) && ! $this->hasCompletedIdentityVerification($user)) {
            return $this->forbiddenResponse('请先完成实名认证');
        }

        return $next($request);
    }

    private function supportsIdentityVerification(object $user): bool
    {
        return isset($user->is_verified) || isset($user->verification_status);
    }

    private function hasCompletedIdentityVerification(object $user): bool
    {
        return (int) ($user->is_verified ?? 0) === 1 || (int) ($user->verification_status ?? 0) === 2;
    }

    private function forbiddenResponse(string $message): Response
    {
        return ApiResponseBuilder::error(40301, $message);
    }
}
