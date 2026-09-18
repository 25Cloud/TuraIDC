<?php

declare(strict_types=1);

namespace App\Http\Controllers\Client\V2;

use App\Http\Controllers\Controller;
use App\Http\Requests\Client\V2\UpstreamApi\UpdateUpstreamApiPolicyRequest;
use App\Models\ApiKeyUsageLog;
use App\Services\ZjmfUpstream\UpstreamApiCredentialService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * 用户自助管理「魔方财务上游 API」凭据。
 *
 * 与开放接口密钥（/api/v2/client/api-keys）是两条独立链路：
 * 本端点服务魔方财务上游协议（/api/v2/zjmf）的账号凭据。
 *
 * 协议无法合并，但凭据治理与开放接口对齐：同样支持 IP 白名单、有效期，
 * 同样有调用审计（channel=zjmf_upstream），关闭时也会一并停用开放接口密钥。
 */
class UpstreamApiController extends Controller
{
    public function __construct(private readonly UpstreamApiCredentialService $credentials) {}

    public function status(Request $request): JsonResponse
    {
        return $this->success($this->credentials->status($request->user()));
    }

    public function enable(Request $request): JsonResponse
    {
        // 明文密码仅本次返回，前端需提示用户立即保存
        return $this->success(
            $this->credentials->enable($request->user(), $request->only(['ip_allowlist', 'expires_at'])),
            '上游 API 已开启，请立即保存密码（仅显示一次）'
        );
    }

    public function disable(Request $request): JsonResponse
    {
        $this->credentials->disable($request->user());

        return $this->success([], '上游 API 已关闭，同账号的开放接口密钥也已停用');
    }

    public function resetPassword(Request $request): JsonResponse
    {
        return $this->success(
            $this->credentials->resetPassword($request->user()),
            '密码已重置，请立即保存（仅显示一次）'
        );
    }

    public function updatePolicy(UpdateUpstreamApiPolicyRequest $request): JsonResponse
    {
        $user = $this->credentials->updatePolicy($request->user(), $request->validated());

        return $this->success($this->credentials->status($user), '安全策略已更新');
    }

    /**
     * 魔方链路的调用审计：登录换 JWT 的记录。
     *
     * api_key_id 为 0 哨兵（该链路凭据不是 api_keys 行），与开放接口用
     * channel 区分归属。
     */
    public function usageLogs(Request $request): JsonResponse
    {
        $logs = ApiKeyUsageLog::query()
            ->where('user_id', (int) $request->user()->id)
            ->where('channel', ApiKeyUsageLog::CHANNEL_ZJMF_UPSTREAM)
            ->orderByDesc('created_at')
            ->limit(50)
            ->get()
            ->map(fn (ApiKeyUsageLog $log) => [
                'method' => (string) $log->method,
                'path' => (string) $log->path,
                'status_code' => (int) $log->status_code,
                'ip' => (string) $log->ip,
                'duration_ms' => (int) $log->duration_ms,
                'created_at' => $log->created_at?->format('Y-m-d H:i:s'),
            ]);

        return $this->success(['list' => $logs]);
    }
}
