<?php

declare(strict_types=1);

namespace App\Http\Controllers\ZjmfUpstream;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\ZjmfUpstream\UpstreamProvisionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * 上游模块命令接口（被魔方财务对接）：/provision/default。
 */
class ProvisionController extends Controller
{
    public function __construct(
        private readonly UpstreamProvisionService $provision,
    ) {}

    public function execute(Request $request): JsonResponse
    {
        $user = $request->attributes->get('zjmf_upstream_user');
        $user = $user instanceof User ? $user : $request->user();

        $result = $this->provision->execute($user, $request->all());

        return response()->json($result, 200);
    }
}
