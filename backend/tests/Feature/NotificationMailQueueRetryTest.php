<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Jobs\Concerns\ClassifiesDeliveryRetries;
use App\Services\Mail\MailDeliveryClassifier;
use App\Services\Mail\MailDeliveryException;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Tests\TestCase;

/**
 * 队列层面的重试语义（真实 database 队列 + 真实 worker）。
 *
 * 断言两件在本次修复前做不到的事：
 * 1. 可重试失败会按 `$tries` 重投，用尽次数才落 `failed_jobs`（此前没有任何分类依据）；
 * 2. 永久失败（毒消息）第一次就判死，不会每分钟/每次重投反复消耗 worker。
 *
 * 探针任务用与本仓库邮件任务相同的 trait，从而验证的是生产路径本身。
 */
class NotificationMailQueueRetryTest extends TestCase
{
    use DatabaseTransactions;

    /** 探针队列名：独立队列避免与其它用例共享 jobs 表 */
    private const PROBE_QUEUE = 'notification-retry-probe';

    public function test_transient_failure_retries_until_max_attempts_then_goes_terminal(): void
    {
        $this->useDatabaseQueue();

        RetryProbeMailJob::dispatch('transient')->onQueue(self::PROBE_QUEUE);

        $this->assertSame(1, DB::table('jobs')->count());

        // 第 1、2 次失败：任务回到队列，尚未判死
        foreach ([1, 2] as $attempt) {
            $this->workOneJob();

            $this->assertSame(0, DB::table('failed_jobs')->count(), "第 {$attempt} 次失败不应判终态");
            $this->assertSame(1, DB::table('jobs')->count(), "第 {$attempt} 次失败后任务应回到队列");
        }

        // 第 3 次（$tries 用尽）：转终态
        $this->workOneJob();

        $this->assertSame(0, DB::table('jobs')->count());
        $this->assertSame(1, DB::table('failed_jobs')->count());
    }

    public function test_permanent_failure_is_never_redelivered(): void
    {
        $this->useDatabaseQueue();

        RetryProbeMailJob::dispatch('permanent')->onQueue(self::PROBE_QUEUE);

        $this->workOneJob();

        // 一次判死：没有剩余重试，也不会再回到队列
        $this->assertSame(0, DB::table('jobs')->count());
        $this->assertSame(1, DB::table('failed_jobs')->count());

        $failed = DB::table('failed_jobs')->first();
        $this->assertNotNull($failed);
        $this->assertStringContainsString('收件人邮箱为空或格式非法', (string) ($failed->exception ?? ''));
    }

    private function useDatabaseQueue(): void
    {
        config(['queue.default' => 'database']);

        // 探针任务走独立队列：共享测试库上不碰别人的任务，断言也不会被别人的残留干扰
        DB::table('jobs')->where('queue', self::PROBE_QUEUE)->delete();
        DB::table('failed_jobs')->where('queue', self::PROBE_QUEUE)->delete();
    }

    private function workOneJob(): void
    {
        $this->artisan('queue:work', [
            'connection' => 'database',
            '--once' => true,
            '--tries' => 3,
            '--sleep' => 0,
            '--timeout' => 30,
            '--queue' => self::PROBE_QUEUE,
        ])->run();
    }
}

/**
 * 探针任务：包一层真实 trait，按模式抛出可重试 / 永久失败。
 */
class RetryProbeMailJob implements ShouldQueue
{
    use ClassifiesDeliveryRetries;
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    public int $tries = 3;

    /** 不设延迟：让「重投—判死」在一次测试里可观测，不必等真实退避 */
    public array $backoff = [0];

    public int $timeout = 30;

    public function __construct(public string $mode = 'transient') {}

    public function handle(): void
    {
        $this->guardDelivery(function (): void {
            if ($this->mode === 'permanent') {
                throw MailDeliveryException::permanent(
                    '收件人邮箱为空或格式非法',
                    MailDeliveryClassifier::REASON_INVALID_RECIPIENT,
                );
            }

            throw new RuntimeException('SMTP Connection timed out');
        });
    }
}
