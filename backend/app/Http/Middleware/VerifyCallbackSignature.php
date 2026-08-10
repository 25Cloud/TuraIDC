<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Exceptions\BusinessException;
use App\Services\Verification\Contracts\VerifiesVerificationCallbacks;
use App\Services\Verification\Data\VerificationCallbackRequest;
use App\Services\Verification\VerificationDriverManager;
use App\Support\ApiResponseBuilder;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

class VerifyCallbackSignature
{
    private const MAX_TIMESTAMP_SKEW_SECONDS = 300;

    public function __construct(
        private readonly VerificationDriverManager $verificationDriverManager,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        try {
            $driver = $this->verificationDriverManager->resolve();
        } catch (BusinessException $exception) {
            $this->auditFailure('driver_unavailable', $request);

            return ApiResponseBuilder::error(40001, '签名验证失败', null, 401);
        }

        if (! $driver instanceof VerifiesVerificationCallbacks) {
            $this->auditFailure('driver_without_callback_verifier', $request);

            return ApiResponseBuilder::error(40001, '签名验证失败', null, 401);
        }

        $result = $driver->verifyCallback(new VerificationCallbackRequest(
            payload: $request->all(),
            headers: $this->flattenHeaders($request),
            method: $request->method(),
            path: $request->path(),
            rawBody: $request->getContent(),
        ));

        if (! $result->passed) {
            $this->auditFailure('signature_rejected_by_plugin', $request, $result->message);

            return ApiResponseBuilder::error($result->code, $result->message, null, $result->httpStatus);
        }

        if ($result->replayKey !== null && ! Cache::add(
            'callback:verification:nonce:'.hash('sha256', $result->replayKey),
            true,
            now()->addSeconds(self::MAX_TIMESTAMP_SKEW_SECONDS * 2)
        )) {
            $this->auditFailure('nonce_replayed', $request);

            return ApiResponseBuilder::error(40001, '回调请求已处理，请勿重复提交', null, 409);
        }

        return $next($request);
    }

    /**
     * @return array<string, string>
     */
    private function flattenHeaders(Request $request): array
    {
        $headers = [];
        foreach ($request->headers->all() as $name => $values) {
            $headers[strtolower((string) $name)] = implode(',', array_map('strval', (array) $values));
        }

        return $headers;
    }

    private function auditFailure(string $reason, Request $request, ?string $message = null): void
    {
        $payload = $request->all();
        $certifyId = $payload['certify_id'] ?? $payload['order_no'] ?? '';
        $timestamp = (string) ($request->header('X-Timestamp') ?? ($payload['timestamp'] ?? ''));
        $nonce = (string) ($request->header('X-Nonce') ?? ($payload['nonce'] ?? ''));

        Log::warning('[实名认证回调] 签名验证失败', [
            'reason' => $reason,
            'certify_id_hash' => hash('sha256', (string) $certifyId),
            'timestamp' => $timestamp,
            'nonce_hash' => hash('sha256', $nonce),
            'message' => $message,
        ]);
    }
}
