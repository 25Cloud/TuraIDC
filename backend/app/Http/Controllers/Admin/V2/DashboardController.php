<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin\V2;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\V2\Dashboard\ListRecentInvoicesRequest;
use App\Http\Requests\Admin\V2\Dashboard\ShowDashboardStatsRequest;
use App\Http\Requests\Admin\V2\Dashboard\ShowMonthlyRevenueRequest;
use App\Http\Resources\Admin\V2\AdminDashboardMonthlyRevenueResource;
use App\Http\Resources\Admin\V2\AdminDashboardRecentInvoicesResource;
use App\Http\Resources\Admin\V2\AdminDashboardStatsResource;
use App\Services\System\DashboardService;
use Illuminate\Http\JsonResponse;

class DashboardController extends Controller
{
    public function __construct(
        private readonly DashboardService $dashboardService,
    ) {}

    public function stats(ShowDashboardStatsRequest $request): JsonResponse
    {
        return $this->success(AdminDashboardStatsResource::make($this->dashboardService->stats())->resolve());
    }

    public function recentInvoices(ListRecentInvoicesRequest $request): JsonResponse
    {
        return $this->success(AdminDashboardRecentInvoicesResource::make([
            'recent_invoices' => $this->dashboardService->recentInvoices(),
        ])->resolve());
    }

    public function monthlyRevenue(ShowMonthlyRevenueRequest $request): JsonResponse
    {
        return $this->success(AdminDashboardMonthlyRevenueResource::make($this->dashboardService->monthlyRevenue())->resolve());
    }
}
