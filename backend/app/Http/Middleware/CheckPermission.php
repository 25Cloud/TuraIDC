<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\AdminUser;
use App\Support\ApiResponseBuilder;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class CheckPermission
{
    public function handle(Request $request, Closure $next, string $permission): Response
    {
        $admin = $request->user();

        if (! $admin instanceof AdminUser) {
            return ApiResponseBuilder::error(40100, '未认证');
        }

        $admin->loadMissing('role');

        if ((int) $admin->status !== 1) {
            return ApiResponseBuilder::error(40300, '管理员账号已被禁用');
        }

        if (! $admin->hasPermission($permission)) {
            return ApiResponseBuilder::error(40300, '无操作权限');
        }

        return $next($request);
    }
}
