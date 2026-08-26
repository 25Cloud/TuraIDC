<?php

declare(strict_types=1);

namespace App\Http\Controllers\ZjmfUpstream;

use App\Http\Controllers\Controller;
use App\Services\ZjmfUpstream\ZjmfUpstreamService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AuthController extends Controller
{
    public function __construct(
        private readonly ZjmfUpstreamService $service,
    ) {}

    /**
     * 魔方财务 zjmf_api_login：username/password 换 JWT。
     */
    public function login(Request $request): JsonResponse
    {
        return response()->json($this->service->login(
            (string) $request->input('username', ''),
            (string) $request->input('password', ''),
        ), 200);
    }
}
