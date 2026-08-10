<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin\V2;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\V2\Auth\LoginRequest;
use App\Http\Requests\Admin\V2\Auth\UpdatePasswordRequest;
use App\Http\Requests\Admin\V2\Auth\UpdateProfileRequest;
use App\Http\Resources\Admin\V2\AdminAuthProfileResource;
use App\Http\Resources\Admin\V2\AdminAuthSessionResource;
use App\Services\Admin\Rbac\AdminStaffService;
use App\Services\Auth\AuthService;
use App\Support\TextSanitizer;
use Illuminate\Http\Request;

class AuthController extends Controller
{
    public function __construct(
        private readonly AuthService $authService,
        private readonly AdminStaffService $adminStaffService,
    ) {}

    public function login(LoginRequest $request)
    {
        $result = $this->authService->adminLogin(
            (string) $request->input('username'),
            (string) $request->input('password'),
            (string) $request->ip(),
        );

        return $this->success(AdminAuthSessionResource::make($result)->resolve(), '登录成功');
    }

    public function info(Request $request)
    {
        $admin = $request->user();
        $admin->loadMissing('role');

        return $this->success([
            'admin' => AdminAuthProfileResource::make($admin)->resolve(),
        ]);
    }

    public function updateProfile(UpdateProfileRequest $request)
    {
        $data = $request->validated();
        $admin = $request->user();
        $nickname = TextSanitizer::clean((string) ($data['nickname'] ?? ''));

        $admin->update([
            'nickname' => $nickname !== '' ? $nickname : null,
        ]);

        $admin->loadMissing('role');

        return $this->success([
            'admin' => AdminAuthProfileResource::make($admin)->resolve(),
        ], '资料更新成功');
    }

    public function updatePassword(UpdatePasswordRequest $request)
    {
        $payload = $request->payload();

        $this->adminStaffService->updateOwnPassword(
            staff: $request->user(),
            currentPassword: (string) $payload['current_password'],
            password: (string) $payload['password'],
            ipAddress: (string) $request->ip(),
        );

        return $this->success(null, '密码已更新');
    }

    public function logout(Request $request)
    {
        $request->user()->currentAccessToken()?->delete();

        return $this->success(null, '已退出登录');
    }
}
