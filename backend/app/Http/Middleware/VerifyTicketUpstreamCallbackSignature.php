<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\Service;
use App\Services\Ticket\TicketDeliveryService;
use App\Services\Ticket\TicketUpstreamCallbackToken;
use App\Services\ZjmfUpstream\ZjmfSyncSignature;
use App\Support\ApiResponseBuilder;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
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

        $isTicketReply = $request->is('api/ticket_reply/sync');
        $isHostSync = $request->is('api/host/sync');
        if (! $isTicketReply && ! $isHostSync) {
            return $next($request);
        }

        $payload = $request->all();

        // 3.8.0 起上游默认用 v2 签名（HMAC-SHA256 + timestamp + nonce），
        // 3.7.6 及更早仍只有 legacy（id/token/rand_str 的 md5）。两条都收，
        // 按请求自己声明的 signature_version 走对应分支。
        $signatureVersion = strtolower(trim((string) ($payload['signature_version'] ?? '')));
        if ($signatureVersion !== '' && $signatureVersion !== ZjmfSyncSignature::VERSION_V2 && $signatureVersion !== 'legacy') {
            $this->recordLegacyFailure($isTicketReply, $request, 'unsupported_signature_version', '上游回调签名版本不受支持');

            return response()->json(['status' => 400, 'msg' => '签名验证失败'], 200);
        }

        if (ZjmfSyncSignature::isV2($payload)) {
            return $this->verifySyncV2($request, $next, $isTicketReply);
        }

        $id = (int) ($payload['id'] ?? 0);
        $rand = trim((string) ($payload['rand_str'] ?? ''));
        $signature = strtoupper(trim((string) ($payload['signature'] ?? '')));
        if ($id <= 0 || $rand === '' || $signature === '') {
            $this->recordLegacyFailure($isTicketReply, $request, 'missing_legacy_signature_fields', '上游回调缺少 legacy 签名字段');
            Log::warning('上游回调参数缺失', [
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
            Log::warning('上游回调 token 生成失败', ['service_id' => $id, 'message' => $exception->getMessage()]);

            return response()->json(['status' => 400, 'msg' => '签名错误'], 200);
        }

        if ($token === '') {
            $this->recordLegacyFailure($isTicketReply, $request, 'legacy_token_missing', '上游回调 token 未配置或服务不存在');
            Log::warning('上游回调 token 为空', [
                'callback_path' => $request->path(),
                'service_id' => $id,
                'reason' => 'legacy_token_missing',
            ]);

            return response()->json(['status' => 400, 'msg' => '签名验证失败'], 200);
        }

        if (! $this->legacySignatureMatches($id, $token, $rand, $signature)) {
            $this->recordLegacyFailure($isTicketReply, $request, 'legacy_signature_mismatch', '上游回调 legacy 签名验证失败');
            Log::warning('上游回调签名验证失败', [
                'callback_path' => $request->path(),
                'service_id' => $id,
                'reason' => 'legacy_signature_mismatch',
            ]);

            return response()->json(['status' => 400, 'msg' => '签名验证失败'], 200);
        }

        return $next($request);
    }

    /**
     * 对齐魔方财务 createSign：strtoupper(md5(json_encode(ksort(['id','token','rand_str']))))。
     * 兼容 id 的 int/string 两种 json 形式（魔方 createSign 传 int，本系统历史实现传 string）。
     */
    private function legacySignatureMatches(int $id, string $token, string $rand, string $signature): bool
    {
        $candidates = [
            ['id' => $id, 'token' => $token, 'rand_str' => $rand],
            ['id' => (string) $id, 'token' => $token, 'rand_str' => $rand],
        ];

        foreach ($candidates as $signed) {
            ksort($signed, SORT_STRING);
            if (hash_equals(strtoupper(md5((string) json_encode($signed))), $signature)) {
                return true;
            }
        }

        return false;
    }

    /**
     * v2 签名校验：token 取本系统为该服务登记的凭据（上游推送时不带 token），
     * timestamp 与 nonce 由 ZjmfSyncSignature 校验，这里额外做一次重放拦截。
     */
    private function verifySyncV2(Request $request, Closure $next, bool $isTicketReply): Response
    {
        $payload = $request->all();
        $id = (int) ($payload['id'] ?? 0);

        if ($id <= 0) {
            $this->recordLegacyFailure($isTicketReply, $request, 'missing_v2_id', '上游回调缺少 id');

            return response()->json(['status' => 400, 'msg' => '签名验证失败'], 200);
        }

        try {
            $service = Service::query()->find($id);
            $token = $this->legacyToken($service);
        } catch (\Throwable $exception) {
            Log::warning('上游回调 v2 token 生成失败', ['service_id' => $id, 'message' => $exception->getMessage()]);

            return response()->json(['status' => 400, 'msg' => '签名验证失败'], 200);
        }

        if ($token === '') {
            $this->recordLegacyFailure($isTicketReply, $request, 'v2_token_missing', '上游回调 v2 凭据未配置或服务不存在');
            Log::warning('上游回调 v2 凭据为空', [
                'callback_path' => $request->path(),
                'service_id' => $id,
                'reason' => 'v2_token_missing',
            ]);

            return response()->json(['status' => 400, 'msg' => '签名验证失败'], 200);
        }

        $bodyToken = trim((string) ($payload['token'] ?? ''));
        if ($bodyToken !== '' && ! hash_equals($token, $bodyToken)) {
            $this->recordLegacyFailure($isTicketReply, $request, 'v2_token_mismatch', '上游回调 v2 凭据与服务不匹配');
            Log::warning('上游回调 v2 凭据不匹配', [
                'callback_path' => $request->path(),
                'service_id' => $id,
                'reason' => 'v2_token_mismatch',
            ]);

            return response()->json(['status' => 400, 'msg' => '签名验证失败'], 200);
        }

        if (! ZjmfSyncSignature::verifyV2($payload, $token)) {
            $this->recordLegacyFailure($isTicketReply, $request, 'v2_signature_mismatch', '上游回调 v2 签名验证失败');
            Log::warning('上游回调 v2 签名验证失败', [
                'callback_path' => $request->path(),
                'service_id' => $id,
                'reason' => 'v2_signature_mismatch',
            ]);

            return response()->json(['status' => 400, 'msg' => '签名验证失败'], 200);
        }

        // 工单回调继续用共享缓存做一次性消费；主机同步由 HostSyncPushReceiver
        // 在服务更新事务内用 (service_id, nonce_hash) 唯一索引持久消费，避免
        // 业务失败后缓存已占用而无法重试，也能覆盖缓存重启/多实例场景。
        if ($request->is('api/host/sync')) {
            return $next($request);
        }

        // 按回调路径隔离 nonce 命名空间，避免同一上游在工单与主机回调中复用
        // nonce 时互相误判；Cache::add 在 Redis 等共享缓存后端上是原子消费。
        $nonceKey = sprintf(
            'zjmf:sync:v2:nonce:%s',
            hash('sha256', $request->path().'|'.$token.'|'.(string) $payload['nonce'])
        );
        if (! Cache::add($nonceKey, time(), 86400)) {
            $this->recordLegacyFailure($isTicketReply, $request, 'v2_nonce_replayed', '上游回调 v2 nonce 重放');

            return response()->json(['status' => 400, 'msg' => '签名验证失败'], 200);
        }

        return $next($request);
    }

    private function recordLegacyFailure(bool $isTicketReply, Request $request, string $reason, string $message): void
    {
        if (! $isTicketReply) {
            return;
        }

        $this->delivery->recordInboundCallbackFailure(
            (string) $request->input('tid', ''),
            $reason,
            $message
        );
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
