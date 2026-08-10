<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Constants\UserCouponStatus;
use App\Models\UserCoupon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class BackfillUserCouponUid extends Command
{
    protected $signature = 'coupons:backfill-uid';

    protected $description = 'Backfill user coupon uid and historical used status.';

    public function handle(): int
    {
        $uidCount = 0;

        UserCoupon::query()
            ->whereNull('uid')
            ->lazyById(500)
            ->each(function (UserCoupon $userCoupon) use (&$uidCount): void {
                UserCoupon::withoutTimestamps(function () use ($userCoupon): void {
                    $userCoupon->forceFill([
                        'uid' => $this->generateUid(),
                    ])->save();
                });

                $uidCount++;
            });

        $usedCount = UserCoupon::query()
            ->where('status', UserCouponStatus::OWNED)
            ->whereNotNull('last_used_at')
            ->update([
                'status' => UserCouponStatus::USED,
                'used_at' => DB::raw('last_used_at'),
            ]);

        $this->info("uid backfilled: {$uidCount}");
        $this->info("used status backfilled: {$usedCount}");

        return self::SUCCESS;
    }

    private function generateUid(): string
    {
        do {
            $uid = 'uc_'.bin2hex(random_bytes(6));
        } while (UserCoupon::query()->where('uid', $uid)->exists());

        return $uid;
    }
}
