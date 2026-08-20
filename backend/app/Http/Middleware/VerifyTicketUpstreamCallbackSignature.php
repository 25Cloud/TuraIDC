<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\Service;
use App\Services\Integrations\Plugins\PluginBindingResolver;
use App\Services\Ticket\TicketUpstreamCallbackToken;
use App\Support\ApiResponseBuilder;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

final class VerifyTicketUpstreamCallbackSignature
{
    public function __construct(
        private readonly PluginBindingResolver $bindings,
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
            return response()->json(['status' => 400, 'msg' => '签名验证失败'], 200);
        }

        return $next($request);
    }

    private function verifyModern(Request $request, Closure $next): Response
    {
        $signature = trim((string) $request->input('signature', ''));
        $secret = trim((string) config('ticket_upstream.callback_secret', ''));
        if ($signature === '' || $secret === '') {
            return ApiResponseBuilder::error(40100, '上游回调签名无效', null, 401);
        }

        $payload = $request->all();
        unset($payload['signature']);
        ksort($payload);
        $expected = hash_hmac('sha256', (string) json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), $secret);
        if (! hash_equals($expected, $signature)) {
            return ApiResponseBuilder::error(40100, '上游回调签名无效', null, 401);
        }

        return $next($request);
    }

    private function legacyToken(?Service $service): string
    {
        if (! $service instanceof Service) {
            return '';
        }

        $projection = $this->bindings->serviceProvisionProjection($service, includeSecrets: true);
        foreach (['downstream_token', 'ticket_callback_token', 'callback_token'] as $key) {
            $token = trim((string) ($projection[$key] ?? ''));
            if ($token !== '') {
                return $token;
            }
        }

        return TicketUpstreamCallbackToken::forServiceId((int) $service->id);
    }
}
