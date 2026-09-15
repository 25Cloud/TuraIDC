<?php

declare(strict_types=1);

namespace App\Http\Controllers\Client\V2;

use App\Http\Controllers\Controller;
use App\Services\ZjmfUpstream\UpstreamApiCredentialService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * 用户自助管理「魔方财务上游 API」凭据。
 *
 * 与开放接口密钥（/api/v2/client/api-keys）是两条独立链路：
 * 本端点服务魔方财务上游协议（/api/v2/zjmf）的账号凭据。
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
            $this->credentials->enable($request->user()),
            '上游 API 已开启，请立即保存密码（仅显示一次）'
        );
    }

    public function disable(Request $request): JsonResponse
    {
        $this->credentials->disable($request->user());

        return $this->success([], '上游 API 已关闭');
    }

    public function resetPassword(Request $request): JsonResponse
    {
        return $this->success(
            $this->credentials->resetPassword($request->user()),
            '密码已重置，请立即保存（仅显示一次）'
        );
    }
}
