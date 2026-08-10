<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\AdminUser;
use App\Support\ApiResponseBuilder;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureAdminAuthenticated
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user) {
            return ApiResponseBuilder::error(40100, '未认证');
        }

        if (! $user instanceof AdminUser) {
            return ApiResponseBuilder::error(40300, '仅允许管理员访问');
        }

        if ((int) $user->status !== 1) {
            return ApiResponseBuilder::error(40300, '管理员账号已被禁用');
        }

        return $next($request);
    }
}
