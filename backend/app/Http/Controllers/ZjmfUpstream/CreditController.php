<?php

declare(strict_types=1);

namespace App\Http\Controllers\ZjmfUpstream;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\ZjmfUpstream\CreditService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * 上游余额/信用额支付接口（被魔方财务对接）。
 * 协议约束：固定 HTTP 200，业务状态放 body.status（1001=余额支付完成并开通）。
 */
class CreditController extends Controller
{
    public function __construct(
        private readonly CreditService $credit,
    ) {}

    public function applyCredit(Request $request): JsonResponse
    {
        return response()->json($this->credit->applyCredit($this->user($request), $request->all()), 200);
    }

    public function applyCreditLimit(Request $request): JsonResponse
    {
        return response()->json($this->credit->applyCreditLimit($this->user($request), $request->all()), 200);
    }

    private function user(Request $request): User
    {
        $user = $request->attributes->get('zjmf_upstream_user');

        return $user instanceof User ? $user : $request->user();
    }
}
