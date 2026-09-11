<?php

declare(strict_types=1);

namespace App\Http\Controllers\ZjmfUpstream;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\ZjmfUpstream\PushService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;

/**
 * 上游推送/透传接口（被魔方财务对接）。
 * 协议约束：固定 HTTP 200，业务状态放 body.status。
 */
class PushController extends Controller
{
    public function __construct(
        private readonly PushService $push,
    ) {}

    public function ticketReplySync(Request $request): JsonResponse
    {
        return response()->json($this->push->ticketReplySync($request->all()), 200);
    }

    public function uploadImage(Request $request): JsonResponse
    {
        $file = $request->file('file');

        if (! $file instanceof UploadedFile) {
            return response()->json(['status' => 400, 'msg' => '缺少文件参数 file'], 200);
        }

        return response()->json($this->push->uploadImage($file), 200);
    }

    public function provisionCustom(Request $request, string $id): JsonResponse
    {
        $user = $this->user($request);

        $result = $this->push->provisionCustom($user, (int) $id, $request->all());

        return response()->json($result, 200);
    }

    private function user(Request $request): User
    {
        $user = $request->attributes->get('zjmf_upstream_user');

        return $user instanceof User ? $user : $request->user();
    }
}
