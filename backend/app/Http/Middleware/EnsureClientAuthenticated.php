<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\User;
use App\Support\ApiResponseBuilder;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureClientAuthenticated
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user) {
            return ApiResponseBuilder::error(40100, '未认证');
        }

        if (! $user instanceof User) {
            return ApiResponseBuilder::error(40300, '仅允许客户访问');
        }

        if ((int) $user->status !== 1) {
            return ApiResponseBuilder::error(40300, '当前账号已被禁用');
        }

        if (method_exists($user, 'trashed') && $user->trashed()) {
            return ApiResponseBuilder::error(40300, '当前账号不可用');
        }

        return $next($request);
    }
}
