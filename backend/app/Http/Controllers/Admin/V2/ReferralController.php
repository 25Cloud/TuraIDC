<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin\V2;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\V2\Referral\ListReferralRewardsRequest;
use App\Http\Requests\Admin\V2\Referral\ShowReferralOverviewRequest;
use App\Http\Resources\Referral\V2\ReferralOverviewResource;
use App\Http\Resources\Referral\V2\ReferralRewardListItemResource;
use App\Models\ReferralReward;
use App\Services\Referral\AdminReferralOverviewService;
use App\Services\Referral\ReferralService;
use App\Support\AdminPrivacy;
use Illuminate\Http\JsonResponse;

class ReferralController extends Controller
{
    public function __construct(
        private readonly AdminReferralOverviewService $overviewService,
        private readonly ReferralService $referrals,
    ) {}

    public function overview(ShowReferralOverviewRequest $request): JsonResponse
    {
        return $this->success(ReferralOverviewResource::make(
            $this->overviewService->overview()
        )->resolve());
    }

    public function rewards(ListReferralRewardsRequest $request): JsonResponse
    {
        $privacy = AdminPrivacy::fromRequest($request);
        $paginator = $this->referrals->adminRewardLogs($request->filters(), $request->pageSize());

        $paginator->setCollection(collect($paginator->items())
            ->filter(fn (mixed $item): bool => $item instanceof ReferralReward)
            ->map(fn (ReferralReward $item): array => $this->referrals->adminRewardProjection($item, $privacy))
            ->values());

        return $this->paginate($paginator, ReferralRewardListItemResource::class);
    }
}
