<?php

namespace App\Http\Middleware;

use App\Models\AdminUser;
use App\Models\User;
use App\Services\System\OperationLogService;
use App\Support\SensitiveDataSanitizer;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

class LogOperation
{
    public function __construct(
        private OperationLogService $operationLogService,
    ) {}

    public function handle(Request $request, Closure $next)
    {
        $startedAt = microtime(true);
        $requestTime = now()->format('Y-m-d H:i:s.u');
        $requestId = $this->resolveRequestId($request);

        try {
            $response = $next($request);
        } catch (\Throwable $exception) {
            $this->writeApiAccessLog($request, null, $exception, $startedAt, $requestTime, $requestId);

            throw $exception;
        }

        $response->headers->set('X-Request-Id', $requestId);
        $this->writeApiAccessLog($request, $response, null, $startedAt, $requestTime, $requestId);

        return $response;
    }

    private function writeApiAccessLog(
        Request $request,
        ?Response $response = null,
        ?\Throwable $exception = null,
        ?float $startedAt = null,
        ?string $requestTime = null,
        ?string $requestId = null,
    ): void {
        if (! $this->shouldLog($request)) {
            return;
        }

        try {
            $user = $request->user();
            $requestId = trim((string) ($requestId ?? $request->attributes->get('request_id', $request->header('X-Request-Id', ''))));
            $userAgent = trim((string) $request->userAgent());
            $statusCode = $response?->getStatusCode();

            if ($statusCode === null) {
                $statusCode = $exception instanceof HttpExceptionInterface
                    ? $exception->getStatusCode()
                    : (is_int($exception?->getCode()) && $exception->getCode() >= 100 && $exception->getCode() <= 599
                        ? (int) $exception->getCode()
                        : 500);
            }

            if ($exception === null && $this->shouldSkipSuccessfulPollingRequest($request, $statusCode)) {
                return;
            }

            $detail = [
                'request_time' => $requestTime ?? now()->format('Y-m-d H:i:s.u'),
                'method' => $request->method(),
                'path' => $request->path(),
                'params' => SensitiveDataSanitizer::sanitize($request->all()),
                'status' => $statusCode,
                'request_id' => $requestId,
                'duration_ms' => $this->elapsedMilliseconds($startedAt),
                'user_agent' => $userAgent,
                'service' => (string) config('app.name', 'EwyFinance-backend'),
            ];

            if ($exception !== null) {
                $detail['exception'] = class_basename($exception);
                $detail['exception_message'] = SensitiveDataSanitizer::sanitizeText($exception->getMessage());
            }

            $this->operationLogService->write(
                userId: $user?->id ? (int) $user->id : null,
                userType: $this->resolveUserType($user),
                action: $request->method().' '.$request->path(),
                module: $this->resolveModule($request),
                targetId: null,
                detail: $detail,
                ipAddress: $request->ip(),
            );
        } catch (\Throwable) {
            // 日志记录失败不影响主流程
        }
    }

    private function shouldLog(Request $request): bool
    {
        if (! $request->is('api/*')) {
            return false;
        }

        if (in_array($request->method(), ['OPTIONS', 'HEAD'], true)) {
            return false;
        }

        if ($request->is('api/v2/admin/logs') || $request->is('api/v2/admin/logs/*')) {
            return false;
        }

        if ($request->is('api/health', 'api/ready')) {
            return false;
        }

        return true;
    }

    private function shouldSkipSuccessfulPollingRequest(Request $request, int $statusCode): bool
    {
        if ($statusCode >= 400 || $request->method() !== 'GET') {
            return false;
        }

        return $request->is('api/v2/client/verification/status')
            || $request->is('api/v2/client/recharge/*/status')
            || $request->is('api/v2/client/invoices/*/pay/alipay/status');
    }

    private function resolveUserType(mixed $user): string
    {
        if ($user instanceof AdminUser) {
            return 'admin';
        }

        if ($user instanceof User) {
            return 'client';
        }

        return 'guest';
    }

    private function resolveModule(Request $request): string
    {
        $segments = array_values(array_filter(explode('/', trim($request->path(), '/'))));

        if (($segments[0] ?? '') === 'api') {
            array_shift($segments);
        }

        if (($segments[0] ?? '') === 'v2') {
            array_shift($segments);
        }

        if (in_array($segments[0] ?? '', ['admin', 'client', 'site'], true)) {
            array_shift($segments);
        }

        return trim((string) ($segments[0] ?? 'unknown')) ?: 'unknown';
    }

    private function resolveRequestId(Request $request): string
    {
        $requestId = trim((string) ($request->headers->get('X-Request-Id') ?: $request->attributes->get('request_id', '')));

        if ($requestId === '') {
            $requestId = (string) Str::ulid();
        }

        $requestId = mb_substr($requestId, 0, 64);

        $request->headers->set('X-Request-Id', $requestId);
        $request->attributes->set('request_id', $requestId);

        return $requestId;
    }

    private function elapsedMilliseconds(?float $startedAt): int
    {
        if ($startedAt === null) {
            return 0;
        }

        return max(0, (int) round((microtime(true) - $startedAt) * 1000));
    }
}
