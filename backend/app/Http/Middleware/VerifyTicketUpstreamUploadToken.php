<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\Service;
use App\Services\Ticket\TicketUpstreamCallbackToken;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

final class VerifyTicketUpstreamUploadToken
{
    public function handle(Request $request, Closure $next): Response
    {
        $id = (int) $request->input('id', 0);
        $token = trim((string) $request->input('token', ''));

        // 兼容模式：上游系统尚未同步凭证配套修改时，管理员可显式关闭强制校验。
        // 此模式重新暴露匿名上传风险，仅用于过渡，部署上游配套修改后必须恢复。
        $tokenRequired = filter_var(
            config('ticket_upstream.upload_token_required', true),
            FILTER_VALIDATE_BOOLEAN,
            FILTER_NULL_ON_FAILURE
        ) ?? true;

        if (! $tokenRequired) {
            if ($id > 0 && $token !== '') {
                return $this->verifyCredential($request, $next, $id, $token);
            }
            Log::warning('上游工单附件上传在兼容模式下放行（未配置凭证校验）', [
                'reason' => 'upload_token_required_disabled',
                'ip' => $request->ip(),
            ]);

            return $next($request);
        }

        if ($id <= 0 || $token === '') {
            Log::warning('上游工单附件上传缺少凭证', [
                'reason' => 'missing_upload_token_fields',
                'ip' => $request->ip(),
            ]);

            return response()->json(['status' => 400, 'msg' => '上传失败'], 200);
        }

        return $this->verifyCredential($request, $next, $id, $token);
    }

    private function verifyCredential(Request $request, Closure $next, int $id, string $token): Response
    {
        $service = Service::query()->find($id);
        if (! $service instanceof Service
            || ! hash_equals(TicketUpstreamCallbackToken::forServiceId((int) $service->id), $token)
        ) {
            Log::warning('上游工单附件上传凭证校验失败', [
                'service_id' => $id,
                'reason' => 'upload_token_mismatch',
            ]);

            return response()->json(['status' => 400, 'msg' => '上传失败'], 200);
        }

        return $next($request);
    }
}
