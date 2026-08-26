<?php

declare(strict_types=1);

namespace App\Http\Controllers\ZjmfUpstream;

use App\Http\Controllers\Controller;
use App\Services\ZjmfUpstream\ProductService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * 上游商品接口（被魔方财务对接）。
 *
 * 协议约束：所有响应固定 HTTP 200，业务状态放 body.status。
 * 魔方财务 GET 用 query string 传参（http_build_query）。
 */
class ProductController extends Controller
{
    public function __construct(
        private readonly ProductService $products,
    ) {}

    public function all(): JsonResponse
    {
        return response()->json([
            'status' => 200,
            'msg' => '请求成功',
            'data' => $this->products->all(),
        ], 200);
    }

    public function proInfo(Request $request): JsonResponse
    {
        return response()->json([
            'status' => 200,
            'msg' => '请求成功',
            'data' => $this->products->infos($this->pidsFromRequest($request)),
        ], 200);
    }

    public function proDetail(Request $request): JsonResponse
    {
        return response()->json([
            'status' => 200,
            'msg' => '请求成功',
            'data' => $this->products->details($this->pidsFromRequest($request)),
        ], 200);
    }

    public function config(Request $request): JsonResponse
    {
        $result = $this->products->config((int) $request->query('pid', 0));

        return response()->json($result, 200);
    }

    public function trialLimit(Request $request): JsonResponse
    {
        $result = $this->products->trialLimit((int) $request->query('pid', 0));

        return response()->json($result, 200);
    }

    /**
     * pids 兼容单值与数组两种传参（魔方财务 http_build_query 数组形式）。
     *
     * @return list<int>
     */
    private function pidsFromRequest(Request $request): array
    {
        $pids = (array) $request->query('pids', []);

        return array_values(array_filter(
            array_map('intval', $pids),
            static fn (int $pid): bool => $pid > 0
        ));
    }
}
