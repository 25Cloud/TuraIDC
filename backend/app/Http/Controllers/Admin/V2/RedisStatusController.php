<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin\V2;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\V2\Redis\FlushRedisCacheRequest;
use App\Http\Requests\Admin\V2\Redis\ShowRedisStatusRequest;
use App\Http\Resources\Admin\V2\AdminActionResultResource;
use App\Http\Resources\Admin\V2\AdminRedisStatusResource;
use App\Services\System\RedisStatusService;
use Illuminate\Http\JsonResponse;

class RedisStatusController extends Controller
{
    public function __construct(
        private readonly RedisStatusService $redisStatus,
    ) {}

    public function status(ShowRedisStatusRequest $request): JsonResponse
    {
        return $this->success(
            AdminRedisStatusResource::make($this->redisStatus->status())->resolve()
        );
    }

    public function flushCache(FlushRedisCacheRequest $request): JsonResponse
    {
        $result = $this->redisStatus->flushCache(
            $request->user()?->id ? (int) $request->user()->id : null,
            $request->ip(),
        );

        return $this->success(
            AdminActionResultResource::make($result)->resolve(),
            (string) $result['message']
        );
    }
}
