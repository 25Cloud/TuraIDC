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
 * 为什么 maxExceptions=1：重算完全幂等（结果只由销售额与当前等级规则决定），且任何一次
 * 等级增删改都会重新派发本任务；真出异常时靠下一次变更或人工重跑收敛即可，不做退避重试。
 */
class ResyncMemberLevelsJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    /**
     * 这个数字必须留给 WithoutOverlapping 的 releaseAfter(60) 用，不能设成 1。
     *
     * 抢不到锁时中间件走的是 `$job->release(60)`（见 WithoutOverlapping::handle），
     * 而 release 不重置 attempts；Worker::markJobAsFailedIfAlreadyExceedsMaxAttempts
     * 判的是 `$job->attempts() <= $maxTries`，所以 tries=1 时任务第二次出队会在
     * **进入 handle() 之前**就被判失败丢弃——重算一次都没跑，等级规则变更直接丢失。
     *
     * 60 次 × releaseAfter(60s) ≈ 最多等锁 60 分钟，是锁自身 expireAfter(1800s) 的两倍余量。
     */
    public int $tries = 60;

    /**
     * 与 tries 分开计数：Worker::markJobAsFailedIfWillExceedMaxExceptions 用独立的
     * `job-exceptions:{uuid}` 缓存键累计真实异常。因此上面放宽 tries 只是允许反复等锁，
     * 真抛异常仍然只失败一次就进 failed()，不会变成 60 次重试。
     */
    public int $maxExceptions = 1;

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
