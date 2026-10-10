<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Constants\UserCouponStatus;
use App\Models\UserCoupon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class BackfillUserCouponCodes extends Command
{
    protected $signature = 'coupons:backfill-codes';

    protected $description = 'Backfill user coupon codes and historical used status.';

    public function handle(): int
    {
        $backfilledCount = 0;

        UserCoupon::query()
            ->whereNull('coupon_code')
            ->lazyById(500)
            ->each(function (UserCoupon $userCoupon) use (&$backfilledCount): void {
                UserCoupon::withoutTimestamps(function () use ($userCoupon): void {
                    $userCoupon->forceFill([
                        'coupon_code' => $this->generateCouponCode(),
                    ])->save();
                });

                $backfilledCount++;
            });

        $usedCount = UserCoupon::query()
            ->where('status', UserCouponStatus::OWNED)
            ->whereNotNull('last_used_at')
            ->update([
                'status' => UserCouponStatus::USED,
                'used_at' => DB::raw('last_used_at'),
            ]);

        $this->info("coupon codes backfilled: {$backfilledCount}");
        $this->info("used status backfilled: {$usedCount}");

        return self::SUCCESS;
    }

    private function generateCouponCode(): string
    {
        do {
            $code = 'uc_'.bin2hex(random_bytes(6));
        } while (UserCoupon::query()->where('coupon_code', $code)->exists());

        return $code;
    }
}
