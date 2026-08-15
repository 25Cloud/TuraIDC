<?php

declare(strict_types=1);

namespace TuraIDC\Plugins\Addons\ZjmfBridge\Services;

use Illuminate\Http\JsonResponse;

class ZjmfResponseFactory
{
    private const JSON_ENCODING_OPTIONS = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES;

    public function success(mixed $data = [], string $message = 'success', int $httpStatus = 200): JsonResponse
    {
        return response()->json([
            'status' => 200,
            'msg' => $message,
            'data' => $data,
        ], $httpStatus, [], self::JSON_ENCODING_OPTIONS);
    }

    public function error(int $status, string $message, mixed $data = [], ?int $httpStatus = null): JsonResponse
    {
        return response()->json([
            'status' => $status,
            'msg' => $message,
            'data' => $data,
        ], $httpStatus ?? $this->httpStatusFor($status), [], self::JSON_ENCODING_OPTIONS);
    }

    private function httpStatusFor(int $status): int
    {
        return match (true) {
            $status >= 500 => 500,
            $status >= 422 => 422,
            $status >= 409 => 409,
            $status >= 404 => 404,
            $status >= 403 => 403,
            $status >= 401 => 401,
            default => 400,
        };
    }
}
