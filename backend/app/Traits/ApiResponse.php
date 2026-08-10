<?php

declare(strict_types=1);

namespace App\Traits;

use App\Support\ApiResponseBuilder;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Http\JsonResponse;

trait ApiResponse
{
    protected function success(mixed $data = null, string $message = '操作成功', int $status = 200): JsonResponse
    {
        return ApiResponseBuilder::success($data, $message, $status);
    }

    protected function error(int $code = 50000, string $message = '操作失败', mixed $data = null): JsonResponse
    {
        return ApiResponseBuilder::error($code, $message, $data);
    }

    protected function paginate(LengthAwarePaginator $paginator, ?string $resourceClass = null, array $extra = []): JsonResponse
    {
        return $this->success(ApiResponseBuilder::pagination($paginator, $resourceClass, $extra));
    }
}
