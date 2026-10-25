<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin\V2;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\V2\Schedule\ClearQueueJobsRequest;
use App\Http\Requests\Admin\V2\Schedule\DeleteQueueJobRequest;
use App\Http\Requests\Admin\V2\Schedule\ListQueueJobsRequest;
use App\Services\Automation\QueueJobService;
use Illuminate\Http\JsonResponse;
use InvalidArgumentException;
use RuntimeException;

class QueueJobController extends Controller
{
    public function __construct(
        private readonly QueueJobService $queueJobs,
    ) {}

    public function index(ListQueueJobsRequest $request): JsonResponse
    {
        return $this->success($this->queueJobs->paginateJobs(
            $request->queueName(),
            (int) ($request->validated('page') ?? 1),
            $request->perPage(),
        ));
    }

    public function destroy(DeleteQueueJobRequest $request, string $queue, int $job): JsonResponse
    {
        try {
            $deleted = $this->queueJobs->deleteJob(
                $queue,
                $job,
                $request->user()?->id ? (int) $request->user()->id : null,
                (string) $request->ip(),
            );
        } catch (InvalidArgumentException $exception) {
            return $this->error(42200, $exception->getMessage());
        } catch (RuntimeException) {
            return $this->error(50000, '队列表暂时不可用，请稍后重试');
        }

        return $this->success($deleted, '队列任务已删除');
    }

    public function clear(ClearQueueJobsRequest $request, string $queue): JsonResponse
    {
        try {
            $deletedCount = $this->queueJobs->clearJobs(
                $queue,
                $request->user()?->id ? (int) $request->user()->id : null,
                (string) $request->ip(),
            );
        } catch (RuntimeException) {
            return $this->error(50000, '队列表暂时不可用，请稍后重试');
        }

        return $this->success(['deleted_count' => $deletedCount], '已清空失败队列');
    }
}
