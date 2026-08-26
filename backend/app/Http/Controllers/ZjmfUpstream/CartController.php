<?php

declare(strict_types=1);

namespace App\Http\Controllers\ZjmfUpstream;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\ZjmfUpstream\CartService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * 上游购物车/下单接口（被魔方财务对接）。
 * 协议约束：固定 HTTP 200，业务状态放 body.status。
 */
class CartController extends Controller
{
    public function __construct(
        private readonly CartService $cart,
    ) {}

    public function userInfo(Request $request): JsonResponse
    {
        return response()->json($this->cart->userInfo($this->user($request)), 200);
    }

    public function clear(Request $request): JsonResponse
    {
        return response()->json($this->cart->clear($this->downstreamFrom($request)), 200);
    }

    public function addToShop(Request $request): JsonResponse
    {
        $result = $this->cart->addToShop(
            $this->user($request),
            $request->all(),
        );

        return response()->json($result, 200);
    }

    public function settle(Request $request): JsonResponse
    {
        $cartData = $request->input('cart_data', []);
        if (! is_array($cartData)) {
            $cartData = [];
        }

        $result = $this->cart->settle(
            $this->user($request),
            $cartData,
            $this->downstreamFrom($request),
        );

        return response()->json($result, 200);
    }

    private function user(Request $request): User
    {
        $user = $request->attributes->get('zjmf_upstream_user');

        return $user instanceof User ? $user : $request->user();
    }

    /**
     * 提取下游（魔方财务）回调信息。
     *
     * @return array{downstream_url:string,downstream_token:string,downstream_id:int,ip:string}
     */
    private function downstreamFrom(Request $request): array
    {
        return [
            'downstream_url' => (string) $request->input('downstream_url', ''),
            'downstream_token' => (string) $request->input('downstream_token', ''),
            'downstream_id' => (int) $request->input('downstream_id', 0),
            'ip' => (string) $request->ip(),
        ];
    }
}
