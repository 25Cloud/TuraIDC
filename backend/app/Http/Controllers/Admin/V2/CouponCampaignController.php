<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin\V2;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\V2\CouponCampaign\DeleteCouponCampaignRequest;
use App\Http\Requests\Admin\V2\CouponCampaign\ListCouponCampaignsRequest;
use App\Http\Requests\Admin\V2\CouponCampaign\RunCouponCampaignTaskRequest;
use App\Http\Requests\Admin\V2\CouponCampaign\ShowCouponCampaignSummaryRequest;
use App\Http\Requests\Admin\V2\CouponCampaign\StoreCouponCampaignRequest;
use App\Http\Requests\Admin\V2\CouponCampaign\UpdateCouponCampaignRequest;
use App\Http\Requests\Admin\V2\CouponCampaign\UpdateCouponCampaignStatusRequest;
use App\Http\Resources\Admin\V2\AdminActionResultResource;
use App\Http\Resources\Admin\V2\AdminCouponCampaignDetailResource;
use App\Http\Resources\Admin\V2\AdminCouponCampaignListResource;
use App\Http\Resources\Admin\V2\AdminCouponCampaignSummaryResource;
use App\Models\CouponCampaign;
use App\Services\Admin\V2\AdminCatalogActionV2Service;
use App\Services\Finance\CouponCampaignService;
use Illuminate\Http\Request;

class CouponCampaignController extends Controller
{
    public function __construct(
        private readonly AdminCatalogActionV2Service $actions,
        private readonly CouponCampaignService $campaigns,
    ) {}

    public function summary(ShowCouponCampaignSummaryRequest $request)
    {
        return $this->success(
            AdminCouponCampaignSummaryResource::make($this->campaigns->adminSummary($request->validated()))->resolve()
        );
    }

    public function index(ListCouponCampaignsRequest $request)
    {
        return $this->paginate(
            $this->campaigns->adminList($request->validated(), $request->perPage()),
            AdminCouponCampaignListResource::class
        );
    }

    public function store(StoreCouponCampaignRequest $request)
    {
        return $this->success([
            'campaign' => AdminCouponCampaignDetailResource::make(
                $this->campaigns->createCampaign($request->validated(), $this->context($request))
            )->resolve(),
        ], '活动已创建');
    }

    public function update(UpdateCouponCampaignRequest $request, CouponCampaign $couponCampaign)
    {
        return $this->success([
            'campaign' => AdminCouponCampaignDetailResource::make(
                $this->campaigns->updateCampaign($couponCampaign, $request->validated(), $this->context($request))
            )->resolve(),
        ], '活动已更新');
    }

    public function updateStatus(UpdateCouponCampaignStatusRequest $request, CouponCampaign $couponCampaign)
    {
        $result = $this->actions->updateCouponCampaignStatus(
            $couponCampaign,
            $request->enabled(),
            $this->context($request)
        );

        return $this->success(AdminActionResultResource::make($result)->resolve(), (string) $result['message']);
    }

    public function runTask(RunCouponCampaignTaskRequest $request, CouponCampaign $couponCampaign)
    {
        $result = $this->actions->runCouponCampaignTask(
            $couponCampaign,
            $request->taskType(),
            $request->payload(),
            $this->context($request)
        );

        return $this->success(AdminActionResultResource::make($result)->resolve(), (string) $result['message']);
    }

    public function destroy(DeleteCouponCampaignRequest $request, CouponCampaign $couponCampaign)
    {
        $this->campaigns->deleteCampaign($couponCampaign);

        return $this->success(null, '活动已删除');
    }

    /**
     * @return array<string, mixed>
     */
    private function context(Request $request): array
    {
        return [
            'operator' => (string) ($request->user()?->username ?? $request->user()?->name ?? $request->user()?->email ?? 'admin'),
            'trace_id' => (string) $request->header('X-Request-Id', ''),
        ];
    }
}
