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
use App\Services\Auth\CaptchaPolicyService;
use App\Services\Auth\GeeTestService;
use App\Support\TextSanitizer;
use Illuminate\Http\Request;

class AuthController extends Controller
{
    public function __construct(
        private readonly AuthService $authService,
        private readonly AdminStaffService $adminStaffService,
        private readonly GeeTestService $captchaService,
        private readonly CaptchaPolicyService $captchaPolicyService,
    ) {}

    public function login(LoginRequest $request)
    {
        // 管理员登录此前完全没有人机验证，只有路由上的 throttle:5,1 与 AuthService 内部
        // 按用户名计数的失败锁定（5 次 / 30 分钟）。管理员是权限最高的账号，这里补上验证。
        // 开关位于验证码插件配置的「管理员登录」项，关闭后仍保留上述两层限制。
        if ($response = $this->ensureCaptchaVerified($request)) {
            return $response;
        }

        $result = $this->authService->adminLogin(
            (string) $request->input('username'),
            (string) $request->input('password'),
            (string) $request->ip(),
        );

        return $this->success(AdminAuthSessionResource::make($result)->resolve(), '登录成功');
    }

    /**
     * 校验管理员登录的人机验证。
     *
     * 返回 null 表示放行（开关未开、插件未启用，或验证已通过）；否则返回错误响应。
     * 与客户端一致地带上 captcha_required，供前端唤起验证组件后重试。
     */
    private function ensureCaptchaVerified(Request $request)
    {
        if (! $this->captchaPolicyService->requiresCaptcha(CaptchaPolicyService::SCENE_ADMIN_LOGIN)) {
            return null;
        }

        $verification = $this->captchaService->verify($request->input('captcha'), (string) $request->ip());

        if (! ($verification['ok'] ?? false)) {
            return $this->error(
                CaptchaPolicyService::VERIFY_FAILED_ERROR_CODE,
                $verification['message'] ?? '行为验证未通过，请重试',
                ['captcha_required' => true]
            );
        }

        return null;
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
