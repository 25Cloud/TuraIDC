<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\Service;
use App\Services\Ticket\TicketDeliveryService;
use App\Services\Ticket\TicketUpstreamCallbackToken;
use App\Support\ApiResponseBuilder;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

final class VerifyTicketUpstreamCallbackSignature
{
    public function __construct(
        private readonly TicketDeliveryService $delivery,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        if ($request->is('api/v2/client/tickets/upstream/replies')) {
            return $this->verifyModern($request, $next);
        }

        if (! $request->is('api/ticket_reply/sync')) {
            return $next($request);
        }

        $payload = $request->all();
        $id = (int) ($payload['id'] ?? 0);
        $rand = trim((string) ($payload['rand_str'] ?? ''));
        $signature = strtoupper(trim((string) ($payload['signature'] ?? '')));
        if ($id <= 0 || $rand === '' || $signature === '') {
            $this->delivery->recordInboundCallbackFailure(
                (string) $request->input('tid', ''),
                'missing_legacy_signature_fields',
                '上游工单回调缺少 legacy 签名字段'
            );
            Log::warning('上游工单回调参数缺失', [
                'callback_path' => $request->path(),
                'service_id' => $id > 0 ? $id : null,
                'reason' => 'missing_legacy_signature_fields',
            ]);

            return response()->json(['status' => 400, 'msg' => '签名错误'], 200);
        }

        try {
            $service = Service::query()->find($id);
            $token = $this->legacyToken($service);
        } catch (\Throwable $exception) {
            Log::warning('上游工单回调 token 生成失败', ['service_id' => $id, 'message' => $exception->getMessage()]);

            return response()->json(['status' => 400, 'msg' => '签名错误'], 200);
        }

        $signed = ['id' => (string) $id, 'token' => $token, 'rand_str' => $rand];
        ksort($signed, SORT_STRING);
        $expected = strtoupper(md5((string) json_encode($signed)));
        if (! hash_equals($expected, $signature)) {
            $this->delivery->recordInboundCallbackFailure(
                (string) $request->input('tid', ''),
                'legacy_signature_mismatch',
                '上游工单 legacy 签名验证失败'
            );
            Log::warning('上游工单回调签名验证失败', [
                'callback_path' => $request->path(),
                'service_id' => $id,
                'reason' => 'legacy_signature_mismatch',
            ]);

            return response()->json(['status' => 400, 'msg' => '签名验证失败'], 200);
        }

        return $next($request);
    }

    private function verifyModern(Request $request, Closure $next): Response
    {
        $signature = trim((string) $request->input('signature', ''));
        $secret = trim((string) config('ticket_upstream.callback_secret', ''));
        if ($signature === '' || $secret === '') {
            $this->delivery->recordInboundCallbackFailure(
                (string) $request->input('tid', ''),
                $signature === '' ? 'missing_signature' : 'callback_secret_missing',
                '上游工单新版回调签名字段缺失'
            );
            Log::warning('上游工单新版回调签名字段缺失', [
                'callback_path' => $request->path(),
                'reason' => $signature === '' ? 'missing_signature' : 'callback_secret_missing',
            ]);

            return ApiResponseBuilder::error(40100, '上游回调签名无效', null, 401);
        }

        $payload = $request->all();
        unset($payload['signature']);
        ksort($payload);
        $expected = hash_hmac('sha256', (string) json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), $secret);
        if (! hash_equals($expected, $signature)) {
            $this->delivery->recordInboundCallbackFailure(
                (string) $request->input('tid', ''),
                'modern_signature_mismatch',
                '上游工单新版回调签名验证失败'
            );
            Log::warning('上游工单新版回调签名验证失败', [
                'callback_path' => $request->path(),
                'upstream_ticket_id' => trim((string) $request->input('tid', '')) ?: null,
                'reason' => 'modern_signature_mismatch',
            ]);

            return ApiResponseBuilder::error(40100, '上游回调签名无效', null, 401);
        }

        return $next($request);
    }

    private function legacyToken(?Service $service): string
    {
        if (! $service instanceof Service) {
            return '';
        }

        return TicketUpstreamCallbackToken::forServiceId((int) $service->id);
    }
}
