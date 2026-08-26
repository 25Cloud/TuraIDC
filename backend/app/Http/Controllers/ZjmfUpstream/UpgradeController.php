<?php

declare(strict_types=1);

namespace App\Http\Controllers\ZjmfUpstream;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\ZjmfUpstream\UpgradeService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * 上游升降级接口（被魔方财务对接）。
 * 协议约束：固定 HTTP 200，业务状态放 body.status。
 */
class UpgradeController extends Controller
{
    public function __construct(
        private readonly UpgradeService $upgrade,
    ) {}

    public function configPost(Request $request): JsonResponse
    {
        return response()->json($this->upgrade->configUpgrade($this->user($request), $request->all()), 200);
    }

    public function checkoutConfig(Request $request): JsonResponse
    {
        return response()->json($this->upgrade->checkoutConfigUpgrade($this->user($request), $request->all()), 200);
    }

    public function productPost(Request $request): JsonResponse
    {
        return response()->json($this->upgrade->productUpgrade($this->user($request), $request->all()), 200);
    }

    public function checkoutProduct(Request $request): JsonResponse
    {
        return response()->json($this->upgrade->checkoutProductUpgrade($this->user($request), $request->all()), 200);
    }

    private function user(Request $request): User
    {
        $user = $request->attributes->get('zjmf_upstream_user');

        return $user instanceof User ? $user : $request->user();
    }
}
