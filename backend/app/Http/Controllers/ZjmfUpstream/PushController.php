<?php

declare(strict_types=1);

namespace App\Http\Controllers\ZjmfUpstream;

use App\Http\Controllers\Controller;
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
        // 自定义模块操作透传：TuraIDC 无对应能力，幂等受理避免下游卡流程。
        return response()->json([
            'status' => 200,
            'msg' => '操作成功',
            'data' => ['id' => (int) $id],
        ], 200);
    }
}
