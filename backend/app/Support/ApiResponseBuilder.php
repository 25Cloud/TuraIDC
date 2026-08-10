<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Http\JsonResponse;

class ApiResponseBuilder
{
    public const JSON_ENCODING_OPTIONS = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES;

    public static function success(mixed $data = null, string $message = '操作成功', int $status = 200): JsonResponse
    {
        return response()->json([
            'code' => 0,
            'message' => $message,
            'data' => $data,
            'timestamp' => time(),
        ], $status, [], self::JSON_ENCODING_OPTIONS);
    }

    public static function error(int $code = 50000, string $message = '操作失败', mixed $data = null, ?int $status = null): JsonResponse
    {
        return response()->json([
            'code' => $code,
            'message' => $message,
            'data' => $data,
            'timestamp' => time(),
        ], $status ?? self::httpStatusForErrorCode($code), [], self::JSON_ENCODING_OPTIONS);
    }

    public static function pagination(LengthAwarePaginator $paginator, ?string $resourceClass = null, array $extra = []): array
    {
        $list = $resourceClass
            ? $resourceClass::collection($paginator->items())->resolve()
            : $paginator->items();

        return array_merge([
            'list' => $list,
            'total' => $paginator->total(),
            'page' => $paginator->currentPage(),
            'page_size' => $paginator->perPage(),
        ], $extra);
    }

    public static function httpStatusForErrorCode(int $code): int
    {
        return match (true) {
            $code >= 50000 => 500,
            $code >= 42200 => 422,
            $code >= 40900 => 409,
            $code >= 40400 => 404,
            $code >= 40300 => 403,
            $code >= 40100 => 401,
            $code >= 40000 => 400,
            default => 400,
        };
    }
}
