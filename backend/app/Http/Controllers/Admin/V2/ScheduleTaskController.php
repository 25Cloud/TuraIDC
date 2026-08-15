<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin\V2;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\V2\Schedule\ListScheduleTaskRunsRequest;
use App\Http\Requests\Admin\V2\Schedule\RetryScheduleTaskRunRequest;
use App\Http\Requests\Admin\V2\Schedule\ShowScheduleOverviewRequest;
use App\Http\Requests\Admin\V2\Schedule\ShowScheduleTaskRunRequest;
use App\Http\Requests\Admin\V2\Schedule\TriggerScheduleTaskRequest;
use App\Http\Resources\Admin\V2\AdminActionResultResource;
use App\Http\Resources\Admin\V2\AdminScheduleOverviewResource;
use App\Http\Resources\Admin\V2\AdminScheduleTaskRunResource;
use App\Services\Admin\V2\AdminOperationalActionV2Service;
use App\Services\Automation\ScheduleTaskService;
use Illuminate\Http\JsonResponse;
use InvalidArgumentException;
use RuntimeException;

class ScheduleTaskController extends Controller
{
    public function __construct(
        private readonly AdminOperationalActionV2Service $actions,
        private readonly ScheduleTaskService $scheduleTasks,
    ) {}

    public function overview(ShowScheduleOverviewRequest $request): JsonResponse
    {
        return $this->success(AdminScheduleOverviewResource::make(
            $this->scheduleTasks->overview()
        )->resolve());
    }

    public function trigger(TriggerScheduleTaskRequest $request): JsonResponse
    {
        try {
            $result = $this->actions->triggerScheduleTask(
                $request->task(),
                $request->user()?->id ? (int) $request->user()->id : null,
            );
        } catch (InvalidArgumentException $exception) {
            return $this->error(42200, $exception->getMessage());
        } catch (RuntimeException $exception) {
            // 锁、运行记录表或队列不可用属于预期的基础设施异常，保持管理端 API 的统一错误结构，
            // 不把底层连接/驱动异常原文返回给客户端。
            return $this->error(50000, '定时任务暂时不可触发，请稍后重试');
        } catch (\Throwable $exception) {
            // 插件任务注册等扩展点也可能抛出 Error/TypeError；管理端操作接口不能因为
            // 调试环境的默认异常渲染而泄露内部连接、文件路径或插件实现细节。
            return $this->error(50000, '定时任务暂时不可触发，请稍后重试');
        }

        return $this->success(AdminActionResultResource::make($result)->resolve(), (string) $result['message']);
    }

    public function index(ListScheduleTaskRunsRequest $request): JsonResponse
    {
        $filters = $request->validated();
        $filters['status'] = $request->statuses();
        $page = max(1, (int) ($filters['page'] ?? 1));
        $perPage = max(1, min(100, (int) ($filters['page_size'] ?? 20)));

        return $this->paginate(
            $this->scheduleTasks->paginateRuns($filters, $page, $perPage),
            AdminScheduleTaskRunResource::class,
        );
    }

    public function show(ShowScheduleTaskRunRequest $request, int $run): JsonResponse
    {
        $taskRun = $this->scheduleTasks->runDetail($run);
        if ($taskRun === null) {
            return $this->error(40400, '运行记录不存在');
        }

        return $this->success([
            'run' => AdminScheduleTaskRunResource::make($taskRun)->resolve(),
        ]);
    }

    public function retry(RetryScheduleTaskRunRequest $request, int $run): JsonResponse
    {
        if ($this->scheduleTasks->runDetail($run) === null) {
            return $this->error(40400, '运行记录不存在');
        }

        try {
            $result = $this->actions->retryScheduleTaskRun(
                runId: $run,
                adminUserId: $request->user()?->id ? (int) $request->user()->id : null,
                reason: $request->reason(),
                ipAddress: $request->ip(),
            );
        } catch (InvalidArgumentException $exception) {
            return $this->error(42200, $exception->getMessage());
        } catch (RuntimeException $exception) {
            return $this->error(50000, '定时任务人工重跑暂时不可用，请稍后重试');
        } catch (\Throwable $exception) {
            return $this->error(50000, '定时任务人工重跑暂时不可用，请稍后重试');
        }

        return $this->success(AdminActionResultResource::make($result)->resolve(), (string) $result['message']);
    }
}
