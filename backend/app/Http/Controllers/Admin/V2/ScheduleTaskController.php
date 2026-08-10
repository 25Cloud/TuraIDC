<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin\V2;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\V2\Schedule\ShowScheduleOverviewRequest;
use App\Http\Requests\Admin\V2\Schedule\TriggerScheduleTaskRequest;
use App\Http\Resources\Admin\V2\AdminActionResultResource;
use App\Http\Resources\Admin\V2\AdminScheduleOverviewResource;
use App\Services\Admin\V2\AdminOperationalActionV2Service;
use App\Services\Automation\ScheduleTaskService;
use Illuminate\Http\JsonResponse;
use InvalidArgumentException;

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
        }

        return $this->success(AdminActionResultResource::make($result)->resolve(), (string) $result['message']);
    }
}
