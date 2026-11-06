<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Jobs\SendClientLoginEmailAlertJob;
use App\Jobs\SendClientLoginFailureEmailAlertJob;
use App\Jobs\SendTicketNotificationEmailJob;
use App\Services\System\NotificationService;
use Illuminate\Contracts\Queue\Job as QueueJob;
use Mockery;
use RuntimeException;
use Tests\TestCase;

/**
 * 邮件类队列任务的失败分类与空收件人跳过。
 *
 * 覆盖三件事：
 * 1. 永久失败（模板缺失）→ 任务当场判死，不抛进重试链；
 * 2. 可重试失败（SMTP 超时）→ 原样抛出，交给队列按 backoff 重试；
 * 3. 空/非法收件人 → 跳过，不调用发信、不判失败，也不影响同批其它收件人。
 */
class NotificationMailJobDeliveryTest extends TestCase
{
    public function test_invalid_recipient_is_skipped_without_touching_the_mailer(): void
    {
        $queueJob = Mockery::mock(QueueJob::class);
        $queueJob->shouldNotReceive('fail');

        $service = Mockery::mock(NotificationService::class);
        $service->shouldNotReceive('sendTemplateEmail');

        $job = new SendTicketNotificationEmailJob('not-an-email', NotificationService::TEMPLATE_TICKET_CREATED, ['ticket_id' => 1]);
        $job->job = $queueJob;

        $job->handle($service);
    }

    public function test_permanent_failure_fails_the_job_once_instead_of_retrying(): void
    {
        $queueJob = Mockery::mock(QueueJob::class);
        $queueJob->shouldReceive('fail')
            ->once()
            ->with(Mockery::on(static fn (mixed $exception): bool => $exception instanceof RuntimeException
                && $exception->getMessage() === '邮件模板不存在'));

        $service = Mockery::mock(NotificationService::class);
        $service->shouldReceive('sendTemplateEmail')->once()->andThrow(new RuntimeException('邮件模板不存在'));

        $job = new SendTicketNotificationEmailJob('someone@example.com', '999999', []);
        $job->job = $queueJob;

        // 不抛异常：永久失败不进重试链，已由 fail() 落到 failed_jobs
        $job->handle($service);
    }

    public function test_transient_failure_is_rethrown_for_queue_backoff(): void
    {
        $queueJob = Mockery::mock(QueueJob::class);
        $queueJob->shouldNotReceive('fail');

        $service = Mockery::mock(NotificationService::class);
        $service->shouldReceive('sendTemplateEmail')->once()->andThrow(new RuntimeException('SMTP Connection timed out'));

        $job = new SendTicketNotificationEmailJob('someone@example.com', '999999', []);
        $job->job = $queueJob;

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('SMTP Connection timed out');

        $job->handle($service);
    }

    public function test_empty_recipient_does_not_drag_down_other_recipients(): void
    {
        $sent = [];
        $service = Mockery::mock(NotificationService::class);
        $service->shouldReceive('sendTemplateEmail')
            ->andReturnUsing(function (string $to) use (&$sent): void {
                $sent[] = $to;
            });

        $queueJob = Mockery::mock(QueueJob::class);
        $queueJob->shouldNotReceive('fail');

        foreach (['a@example.com', '', 'b@example.com'] as $to) {
            $job = new SendTicketNotificationEmailJob($to, NotificationService::TEMPLATE_TICKET_CREATED, []);
            $job->job = $queueJob;
            $job->handle($service);
        }

        $this->assertSame(['a@example.com', 'b@example.com'], $sent);
    }

    public function test_login_alert_jobs_skip_invalid_recipients(): void
    {
        $queueJob = Mockery::mock(QueueJob::class);
        $queueJob->shouldNotReceive('fail');

        $service = Mockery::mock(NotificationService::class);
        $service->shouldNotReceive('sendLoginEmailAlertToAddress');
        $service->shouldNotReceive('sendLoginFailureEmailAlertToAddress');

        $login = new SendClientLoginEmailAlertJob(7, '0', '客户', '2026-11-01 10:00:00', '203.0.113.9');
        $login->job = $queueJob;
        $login->handle($service);

        $failure = new SendClientLoginFailureEmailAlertJob(7, '', '客户', 'demo@example.com', '2026-11-01 10:00:00', '203.0.113.9');
        $failure->job = $queueJob;
        $failure->handle($service);
    }

    public function test_job_passes_its_uuid_as_delivery_key(): void
    {
        // 任务 uuid 在重试期间不变 → 交给服务后同一次投递只留一条 message_logs
        $queueJob = Mockery::mock(QueueJob::class);
        $queueJob->shouldReceive('uuid')->andReturn('uuid-1234');
        $queueJob->shouldNotReceive('fail');

        $service = Mockery::mock(NotificationService::class);
        $service->shouldReceive('sendTemplateEmail')
            ->once()
            ->with('someone@example.com', NotificationService::TEMPLATE_TICKET_CREATED, [], 'uuid-1234');

        $job = new SendTicketNotificationEmailJob('someone@example.com', NotificationService::TEMPLATE_TICKET_CREATED, []);
        $job->job = $queueJob;

        $job->handle($service);
    }

    public function test_permanent_failure_without_queue_context_is_not_swallowed(): void
    {
        // 脱离队列直接调用 handle() 时 fail() 是空操作，异常必须抛出，否则失败会被静默吞掉
        $service = Mockery::mock(NotificationService::class);
        $service->shouldReceive('sendLoginEmailAlertToAddress')->once()->andThrow(new \InvalidArgumentException('登录提醒邮箱不能为空'));

        $job = new SendClientLoginEmailAlertJob(7, 'someone@example.com', '客户', '2026-11-01 10:00:00', '203.0.113.9');

        $this->expectException(\InvalidArgumentException::class);

        $job->handle($service);
    }
}
