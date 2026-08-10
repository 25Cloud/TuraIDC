<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin\V2;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\V2\ReferralWithdrawal\ApproveReferralWithdrawalRequest;
use App\Http\Requests\Admin\V2\ReferralWithdrawal\ListReferralWithdrawalsRequest;
use App\Http\Requests\Admin\V2\ReferralWithdrawal\RejectReferralWithdrawalRequest;
use App\Http\Resources\Admin\V2\AdminActionResultResource;
use App\Http\Resources\Referral\V2\ReferralWithdrawalListItemResource;
use App\Models\ReferralWithdrawal;
use App\Services\Admin\V2\AdminReferralWithdrawalActionV2Service;
use App\Services\Referral\ReferralService;
use App\Support\AdminPrivacy;
use Illuminate\Http\JsonResponse;

class ReferralWithdrawalController extends Controller
{
    public function __construct(
        private readonly AdminReferralWithdrawalActionV2Service $actions,
        private readonly ReferralService $referrals,
    ) {}

    public function index(ListReferralWithdrawalsRequest $request): JsonResponse
    {
        $privacy = AdminPrivacy::fromRequest($request);
        $paginator = $this->referrals->adminWithdrawalList($request->filters(), $request->pageSize());

        $paginator->setCollection(collect($paginator->items())
            ->filter(fn (mixed $item): bool => $item instanceof ReferralWithdrawal)
            ->map(fn (ReferralWithdrawal $item): array => $this->referrals->adminWithdrawalProjection($item, $privacy))
            ->values());

        return $this->paginate($paginator, ReferralWithdrawalListItemResource::class);
    }

    public function approve(ApproveReferralWithdrawalRequest $request, ReferralWithdrawal $withdrawal): JsonResponse
    {
        $result = $this->actions->approve(
            withdrawal: $withdrawal,
            operator: $request->user(),
            remark: $request->remark(),
            traceId: (string) $request->header('X-Request-Id', ''),
        );

        return $this->success(AdminActionResultResource::make($result)->resolve(), (string) $result['message']);
    }

    public function reject(RejectReferralWithdrawalRequest $request, ReferralWithdrawal $withdrawal): JsonResponse
    {
        $result = $this->actions->reject(
            withdrawal: $withdrawal,
            operator: $request->user(),
            remark: $request->remark(),
            traceId: (string) $request->header('X-Request-Id', ''),
        );

        return $this->success(AdminActionResultResource::make($result)->resolve(), (string) $result['message']);
    }
}
