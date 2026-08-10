<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin\V2;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\V2\Coupon\DeleteCouponRequest;
use App\Http\Requests\Admin\V2\Coupon\ListCouponsRequest;
use App\Http\Requests\Admin\V2\Coupon\ShowCouponSummaryRequest;
use App\Http\Requests\Admin\V2\Coupon\StoreCouponRequest;
use App\Http\Requests\Admin\V2\Coupon\UpdateCouponRequest;
use App\Http\Requests\Admin\V2\Coupon\UpdateCouponStatusRequest;
use App\Http\Resources\Admin\V2\AdminActionResultResource;
use App\Http\Resources\Admin\V2\AdminCouponDetailResource;
use App\Http\Resources\Admin\V2\AdminCouponListResource;
use App\Http\Resources\Admin\V2\AdminCouponSummaryResource;
use App\Models\Coupon;
use App\Services\Admin\V2\AdminCatalogActionV2Service;
use App\Services\Finance\CouponService;
use Illuminate\Http\Request;

class CouponController extends Controller
{
    public function __construct(
        private readonly AdminCatalogActionV2Service $actions,
        private readonly CouponService $coupons,
    ) {}

    public function summary(ShowCouponSummaryRequest $request)
    {
        $summary = $this->coupons->adminSummary($request->validated());

        return $this->success(AdminCouponSummaryResource::make([
            ...$summary,
            'enabled' => $this->coupons->isCouponFeatureEnabled(),
        ])->resolve());
    }

    public function index(ListCouponsRequest $request)
    {
        return $this->paginate(
            $this->coupons->adminList($request->validated(), $request->perPage()),
            AdminCouponListResource::class
        );
    }

    public function store(StoreCouponRequest $request)
    {
        return $this->success([
            'coupon' => AdminCouponDetailResource::make(
                $this->coupons->createCoupon($request->validated(), $this->context($request))
            )->resolve(),
        ], '优惠券创建成功');
    }

    public function update(UpdateCouponRequest $request, Coupon $coupon)
    {
        return $this->success([
            'coupon' => AdminCouponDetailResource::make(
                $this->coupons->updateCoupon($coupon, $request->validated(), $this->context($request))
            )->resolve(),
        ], '优惠券更新成功');
    }

    public function updateStatus(UpdateCouponStatusRequest $request, Coupon $coupon)
    {
        $result = $this->actions->updateCouponStatus($coupon, $request->enabled(), $this->context($request));

        return $this->success(AdminActionResultResource::make($result)->resolve(), (string) $result['message']);
    }

    public function destroy(DeleteCouponRequest $request, Coupon $coupon)
    {
        $this->coupons->deleteCoupon($coupon, $this->context($request));

        return $this->success(null, '优惠券删除成功');
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
