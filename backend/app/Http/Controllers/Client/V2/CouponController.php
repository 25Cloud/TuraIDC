<?php

namespace App\Http\Controllers\Client\V2;

use App\Http\Controllers\Controller;
use App\Http\Requests\Client\V2\Coupon\ListCouponsRequest;
use App\Models\Coupon;
use App\Services\Finance\CouponService;
use Illuminate\Support\Facades\Schema;

class CouponController extends Controller
{
    public function __construct(
        private CouponService $couponService,
    ) {}

    public function index(ListCouponsRequest $request)
    {
        $result = $this->couponService->paginateForUser(
            $request->user(),
            $request->filters(),
            max((int) $request->input('page', 1), 1),
            $request->perPage()
        );

        return $this->success($result);
    }

    public function summary(ListCouponsRequest $request)
    {
        $result = $this->couponService->summaryForUser(
            $request->user(),
            $request->filters()
        );

        return $this->success($result);
    }

    public function publicIndex(ListCouponsRequest $request)
    {
        $result = $this->couponService->paginatePublicForUser(
            $request->user(),
            $request->filters(),
            max((int) $request->input('page', 1), 1),
            $request->perPage()
        );

        return $this->success($result);
    }

    public function publicSummary(ListCouponsRequest $request)
    {
        return $this->success(
            $this->couponService->summaryPublicForUser($request->user(), $request->filters())
        );
    }

    public function claim(ListCouponsRequest $request, int $couponId)
    {
        if (! Schema::hasTable('coupons')) {
            return $this->error(42200, '当前系统未启用优惠券功能');
        }

        $coupon = Coupon::query()->findOrFail($couponId);

        return $this->success(
            $this->couponService->claimPublicCoupon($request->user(), $coupon),
            '优惠券领取成功'
        );
    }
}
