<?php

namespace App\Jobs;

use App\Services\Referral\MemberLevelService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Support\Facades\Log;

/**
 * 会员等级规则变更后，异步重算全部存量用户的等级归属。
 *
 * 为什么必须异步：重算量与用户数成正比（线上实测 2000 用户约 15.5 秒，
 * 外推 10 万用户约 773 秒），同步跑在 HTTP 请求里必然先撞上
 * max_execution_time / 网关超时——等级已落库、重算只完成一半，管理员却看到 5xx。
 *
 * 为什么 tries=1：重算完全幂等（结果只由销售额与当前等级规则决定），且任何一次
 * 等级增删改都会重新派发本任务；失败时靠下一次变更或人工重跑收敛即可，
 * 不设队列重试也就不存在「重试跨度越过 uniqueFor 导致重复并发」的预算问题。
 */
class ResyncMemberLevelsJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    public int $tries = 1;

    /** 10 万用户外推约 773 秒，按翻倍余量取整 */
    public int $timeout = 1800;

    public int $uniqueFor = 1800;

    public function __construct()
    {
        $this->onQueue('referral');
        $this->afterCommit();
    }

    public function middleware(): array
    {
        return [
            (new WithoutOverlapping('job:member-level-resync'))
                ->releaseAfter(60)
                ->expireAfter($this->timeout),
        ];
    }

    public function uniqueId(): string
    {
        // 全局单例：连续改多个等级只需要一轮重算，重算读的是最新规则
        return 'member-level-resync';
    }

    public function handle(MemberLevelService $memberLevelService): void
    {
        $processed = $memberLevelService->resyncAllUserLevels();

        Log::info('[会员等级] 存量用户等级重算完成', [
            'processed_users' => $processed,
        ]);
    }

    public function failed(\Throwable $exception): void
    {
        Log::error('[会员等级] 存量用户等级重算失败（重算幂等，下次等级变更会自动重跑，也可手动触发）', [
            'message' => $exception->getMessage(),
            'exception' => $exception::class,
        ]);
    }
}
