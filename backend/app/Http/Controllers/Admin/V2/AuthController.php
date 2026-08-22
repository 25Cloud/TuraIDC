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
use App\Services\Auth\GeeTestService;
use App\Support\TextSanitizer;
use Illuminate\Http\Request;

class AuthController extends Controller
{
    public function __construct(
        private readonly AuthService $authService,
        private readonly AdminStaffService $adminStaffService,
        private readonly GeeTestService $geeTestService,
    ) {}

    /**
     * 返回管理员登录页初始化人机验证所需的公开配置，不返回任何密钥。
     */
    public function captchaConfig()
    {
        return $this->success([
            'enabled' => $this->geeTestService->isEnabled(),
            'provider' => $this->geeTestService->getProvider(),
            'captcha_id' => $this->geeTestService->getCaptchaId(),
            'cache_key' => $this->geeTestService->getConfigCacheKey(),
            'script_url' => $this->geeTestService->getAdminScriptUrl(),
        ]);
    }

    /**
     * 返回当前人机验证插件的适配脚本；上游不可用时返回会主动失败的兜底脚本。
     */
    public function captchaScript()
    {
        $status = 200;
        try {
            $scriptContent = $this->geeTestService->getScriptContent();
        } catch (\Throwable $exception) {
            report($exception);

            $scriptContent = $this->geeTestService->getFallbackScriptContent();
            $status = 503;
        }

        return response($scriptContent, $status, [
            'Content-Type' => 'application/javascript; charset=UTF-8',
            'Cache-Control' => 'no-store, max-age=0',
        ]);
    }

    /**
     * 先完成服务端人机验证，再执行管理员账号密码认证。
     */
    public function login(LoginRequest $request)
    {
        $captchaResult = $this->geeTestService->verify(
            $this->captchaPayload($request->input('captcha')),
            (string) $request->ip(),
        );
        if (! ($captchaResult['ok'] ?? false)) {
            return $this->error(42210, $captchaResult['message'] ?? '行为验证未通过，请重试');
        }

        $result = $this->authService->adminLogin(
            (string) $request->input('username'),
            (string) $request->input('password'),
            (string) $request->ip(),
        );

        return $this->success(AdminAuthSessionResource::make($result)->resolve(), '登录成功');
    }

    /**
     * 仅保留各验证码插件可能使用的字符串字段，避免离线模式附带字段污染验证请求。
     *
     * @return array<string, string>|null
     */
    private function captchaPayload(mixed $payload): ?array
    {
        if (! is_array($payload)) {
            return null;
        }

        $allowedKeys = [
            'lot_number', 'captcha_output', 'pass_token', 'gen_time',
            'token', 'knock', 'dfu', 'provider',
        ];

        return array_filter(
            array_intersect_key($payload, array_fill_keys($allowedKeys, true)),
            static fn (mixed $value): bool => is_string($value),
        );
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
