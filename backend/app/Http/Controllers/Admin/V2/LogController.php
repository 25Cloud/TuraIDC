<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin\V2;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\V2\Log\CleanupLogActionRequest;
use App\Http\Requests\Admin\V2\Log\ListLogsRequest;
use App\Http\Requests\Admin\V2\Log\ShowLogCleanupOverviewRequest;
use App\Http\Requests\Admin\V2\Log\ShowLogRequest;
use App\Http\Requests\Admin\V2\Log\SummarizeLogsRequest;
use App\Http\Resources\Admin\V2\AdminActionResultResource;
use App\Http\Resources\Admin\V2\AdminLogDetailResource;
use App\Services\Admin\V2\AdminOperationalActionV2Service;
use App\Services\System\AdminLogV2QueryService;

class LogController extends Controller
{
    public function __construct(
        private readonly AdminLogV2QueryService $logQueryService,
        private readonly AdminOperationalActionV2Service $actions,
    ) {}

    public function index(ListLogsRequest $request, string $channel)
    {
        return $this->success(
            $this->logQueryService->paginate(
                $channel,
                $request->filters(),
                $request->pageNumber(),
                $request->perPage(),
                $request->includeSummary()
            )
        );
    }

    public function show(ShowLogRequest $request, string $channel, string $log)
    {
        return $this->success([
            'log' => AdminLogDetailResource::make($this->logQueryService->detail($channel, $log))->resolve(),
        ]);
    }

    public function summary(SummarizeLogsRequest $request, string $channel)
    {
        return $this->success(
            $this->logQueryService->summary($channel, $request->filters())
        );
    }

    public function cleanupOverview(ShowLogCleanupOverviewRequest $request)
    {
        return $this->success($this->actions->logCleanupOverview());
    }

    public function cleanup(CleanupLogActionRequest $request)
    {
        $result = $this->actions->cleanupLogs($request->payload());

        return $this->success(AdminActionResultResource::make($result)->resolve(), (string) $result['message']);
    }
}
