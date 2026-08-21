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
        if ($id <= 0 || $token === '') {
            Log::warning('上游工单附件上传缺少凭证', [
                'reason' => 'missing_upload_token_fields',
                'ip' => $request->ip(),
            ]);

            return response()->json(['status' => 400, 'msg' => '上传失败'], 200);
        }

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
