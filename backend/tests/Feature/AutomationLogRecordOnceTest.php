<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\AutomationLog;
use Tests\TestCase;

/**
 * recordOnce 崩溃残留自愈：
 * 进程中断/异常退出后留下的 executed_at IS NULL 记录，允许下一次调度 CAS 重试，
 * 且认领窗口内并发重复执行被拦截，窗口过期后仍可再次重试。
 */
class AutomationLogRecordOnceTest extends TestCase
{
    public function test_record_once_created_then_marked_executed_is_not_retried(): void
    {
        $task = $this->uniqueTask('task-done');

        $this->assertTrue(AutomationLog::recordOnce($task, 'action', 'service', 1001, 'rule'));

        // 已 markExecuted → 不再重试
        AutomationLog::markExecuted($task, 'action', 'service', 1001, 'rule');
        $this->assertFalse(AutomationLog::recordOnce($task, 'action', 'service', 1001, 'rule'));
    }

    public function test_record_once_blocks_duplicate_claim_within_window_without_mark_executed(): void
    {
        $task = $this->uniqueTask('task-crash');

        $this->assertTrue(AutomationLog::recordOnce($task, 'action', 'service', 2001, 'rule'));

        // 未 markExecuted 时，新建窗口（TTL 内）的并发调用被拦截，避免双进程重复执行
        $this->assertFalse(AutomationLog::recordOnce($task, 'action', 'service', 2001, 'rule'));

        $log = AutomationLog::query()
            ->where('task_key', $task)
            ->where('action', 'action')
            ->where('object_type', 'service')
            ->where('object_id', 2001)
            ->firstOrFail();
        $this->assertNull($log->executed_at);
        $this->assertNotSame('', (string) data_get($log->meta, '_retry_claimed_at', ''));
    }

    public function test_record_once_reclaim_is_exclusive_within_claim_window(): void
    {
        $task = $this->uniqueTask('task-concurrent');

        $this->assertTrue(AutomationLog::recordOnce($task, 'action', 'service', 3001, 'rule'));

        // 创建即认领：窗口内后续并发调用全部被拦截，仅首个调用方获得执行权
        $this->assertFalse(AutomationLog::recordOnce($task, 'action', 'service', 3001, 'rule'));
        $this->assertFalse(AutomationLog::recordOnce($task, 'action', 'service', 3001, 'rule'));
    }

    public function test_record_once_reclaim_allows_retry_after_claim_window_expires(): void
    {
        $task = $this->uniqueTask('task-expired');

        $this->assertTrue(AutomationLog::recordOnce($task, 'action', 'service', 4001, 'rule'));
        // 创建即认领：窗口内立即重试被拦截
        $this->assertFalse(AutomationLog::recordOnce($task, 'action', 'service', 4001, 'rule'));

        // 把认领时间改到窗口外，模拟上次认领后又崩溃
        $log = AutomationLog::query()
            ->where('task_key', $task)
            ->where('object_id', 4001)
            ->firstOrFail();
        $meta = $log->meta;
        $meta['_retry_claimed_at'] = now()->subHours(2)->toDateTimeString();
        $log->forceFill(['meta' => $meta])->save();

        $this->assertTrue(AutomationLog::recordOnce($task, 'action', 'service', 4001, 'rule'));
    }

    public function test_record_once_forget_clears_residual_for_next_run(): void
    {
        $task = $this->uniqueTask('task-forget');

        $this->assertTrue(AutomationLog::recordOnce($task, 'action', 'service', 5001, 'rule'));

        AutomationLog::forgetRecord($task, 'action', 'service', 5001, 'rule');

        $this->assertFalse(AutomationLog::hasRecord($task, 'action', 'service', 5001, 'rule'));
        $this->assertTrue(AutomationLog::recordOnce($task, 'action', 'service', 5001, 'rule'));
    }

    private function uniqueTask(string $prefix): string
    {
        return $prefix.'-'.bin2hex(random_bytes(6));
    }
}
