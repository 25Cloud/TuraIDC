<?php

namespace App\Console\Commands;

use App\Services\Referral\ReferralService;
use Illuminate\Console\Command;

class ReleaseReferralRewardsCommand extends Command
{
    protected $signature = 'referral:release-rewards';

    protected $description = '释放已到冻结期的推荐奖励';

    public function handle(ReferralService $referralService): int
    {
        $referralService->releaseMaturedRewards();
        $this->info('推荐奖励释放任务执行完成');

        return self::SUCCESS;
    }
}
