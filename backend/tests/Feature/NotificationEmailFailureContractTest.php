<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Setting;
use App\Services\Mail\Contracts\MailDriver;
use App\Services\Mail\MailDeliveryClassifier;
use App\Services\Mail\MailDeliveryException;
use App\Services\Mail\MailDriverManager;
use App\Services\System\NotificationService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Tests\TestCase;

/**
 * `NotificationService::sendEmail()` 的失败行为契约。
 *
 * 修复前的两个问题：
 * 1. 收件人为空/非法也会先写日志再抛异常 —— 同步群发是循环，一个坏地址会中断后面的人；
 * 2. 每次重试都新插一条 `message_logs`，同一次投递在百万行表里留 3 行垃圾。
 */
class NotificationEmailFailureContractTest extends TestCase
{
    /** @var array<string, string> */
    private array $originalSettings = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->originalSettings['email_enabled'] = (string) Setting::getValue('notification', 'email_enabled', '0');
        Setting::setValue('notification', 'email_enabled', '1');
    }

    protected function tearDown(): void
    {
        Setting::setValue('notification', 'email_enabled', $this->originalSettings['email_enabled'] ?? '0');
        Cache::flush();

        parent::tearDown();
    }

    public function test_invalid_recipient_is_skipped_with_a_single_failed_log(): void
    {
        $driver = $this->makeFakeMailDriver();
        $this->useFakeMailDriver($driver);

        // 不抛异常：群发循环必须继续
        app(NotificationService::class)->sendEmail('not-an-email', '主题', '<p>正文</p>', NotificationService::TEMPLATE_INVOICE_NOTICE);

        $rows = DB::table('message_logs')
            ->where('channel', 'email')
            ->where('recipient', 'not-an-email')
            ->get();

        $this->assertCount(1, $rows);
        $this->assertSame('failed', $rows[0]->status);
        $this->assertSame(MailDeliveryClassifier::REASON_INVALID_RECIPIENT, $rows[0]->error_msg);
        $this->assertNull($rows[0]->sent_at);
        $this->assertSame([], $driver->messages);

        DB::table('message_logs')->where('channel', 'email')->where('recipient', 'not-an-email')->delete();
    }

    public function test_retries_with_same_delivery_key_share_one_log_row(): void
    {
        $driver = $this->makeFakeMailDriver(new RuntimeException('SMTP Connection timed out'));
        $this->useFakeMailDriver($driver);

        $to = 'retry-merge@example.com';
        $deliveryKey = '8f14e45f-ceea-467a-9db1-2a48c1f2b3c4';

        foreach ([1, 2, 3] as $attempt) {
            try {
                app(NotificationService::class)->sendEmail($to, '主题', '<p>正文</p>', NotificationService::TEMPLATE_INVOICE_NOTICE, $deliveryKey);
                $this->fail("第 {$attempt} 次应抛出可重试异常");
            } catch (RuntimeException $exception) {
                $this->assertSame('SMTP Connection timed out', $exception->getMessage());
            }
        }

        $rows = DB::table('message_logs')->where('channel', 'email')->where('recipient', $to)->get();

        $this->assertCount(1, $rows, '同一次投递的 3 次尝试必须合并成一条日志');
        $this->assertSame('failed', $rows[0]->status);
        $this->assertStringStartsWith(MailDeliveryClassifier::REASON_TRANSPORT_ERROR, (string) $rows[0]->error_msg);
        $this->assertSame(3, $driver->attempts, '每次尝试都应真的调用驱动');

        DB::table('message_logs')->where('channel', 'email')->where('recipient', $to)->delete();
    }

    public function test_distinct_delivery_keys_keep_distinct_rows(): void
    {
        $driver = $this->makeFakeMailDriver();
        $this->useFakeMailDriver($driver);

        $to = 'retry-distinct@example.com';

        app(NotificationService::class)->sendEmail($to, '主题一', '<p>正文</p>', NotificationService::TEMPLATE_INVOICE_NOTICE, 'key-a');
        app(NotificationService::class)->sendEmail($to, '主题二', '<p>正文</p>', NotificationService::TEMPLATE_INVOICE_NOTICE, 'key-b');

        $this->assertSame(2, DB::table('message_logs')->where('channel', 'email')->where('recipient', $to)->count());

        DB::table('message_logs')->where('channel', 'email')->where('recipient', $to)->delete();
    }

    public function test_disabled_channel_fails_permanently_and_logs_reason(): void
    {
        Setting::setValue('notification', 'email_enabled', '0');

        $driver = $this->makeFakeMailDriver();
        $this->useFakeMailDriver($driver);

        $to = 'disabled-channel@example.com';

        try {
            app(NotificationService::class)->sendEmail($to, '主题', '<p>正文</p>', NotificationService::TEMPLATE_INVOICE_NOTICE);
            $this->fail('邮件总开关关闭时应抛永久失败异常');
        } catch (MailDeliveryException $exception) {
            $this->assertFalse($exception->isRetryable());
            $this->assertSame(MailDeliveryClassifier::REASON_CHANNEL_DISABLED, $exception->failureReason);
        }

        $row = DB::table('message_logs')->where('channel', 'email')->where('recipient', $to)->first();
        $this->assertNotNull($row);
        $this->assertSame('failed', $row->status);
        $this->assertStringStartsWith(MailDeliveryClassifier::REASON_CHANNEL_DISABLED, (string) $row->error_msg);
        $this->assertSame([], $driver->messages);

        DB::table('message_logs')->where('channel', 'email')->where('recipient', $to)->delete();
    }

    public function test_missing_template_fails_permanently_without_writing_a_log(): void
    {
        $driver = $this->makeFakeMailDriver();
        $this->useFakeMailDriver($driver);

        $to = 'missing-template@example.com';

        try {
            app(NotificationService::class)->sendTemplateEmail($to, '999999', []);
            $this->fail('模板不存在时应抛永久失败异常');
        } catch (MailDeliveryException $exception) {
            $this->assertFalse($exception->isRetryable());
            $this->assertSame(MailDeliveryClassifier::REASON_TEMPLATE_MISSING, $exception->failureReason);
        }

        // 模板不存在发生在落日志之前：没有投递目标，就不该给 message_logs 添行
        $this->assertSame(0, DB::table('message_logs')->where('channel', 'email')->where('recipient', $to)->count());
        $this->assertSame([], $driver->messages);
    }

    /**
     * @return object{messages: array<int, array<string, mixed>>, attempts: int}
     */
    private function makeFakeMailDriver(?\Throwable $failure = null): object
    {
        return new class($failure) implements MailDriver
        {
            /** @var array<int, array<string, mixed>> */
            public array $messages = [];

            public int $attempts = 0;

            public function __construct(private ?\Throwable $failure = null) {}

            public function key(): string
            {
                return 'smtp';
            }

            public function label(): string
            {
                return 'Fake SMTP';
            }

            public function sendHtml(string $to, string $subject, string $html, array $context = []): void
            {
                $this->attempts++;

                if ($this->failure !== null) {
                    throw $this->failure;
                }

                $this->messages[] = [
                    'to' => $to,
                    'subject' => $subject,
                    'html' => $html,
                    'context' => $context,
                ];
            }
        };
    }

    private function useFakeMailDriver(object $driver): void
    {
        $this->app->forgetInstance(MailDriverManager::class);
        $this->app->instance(MailDriverManager::class, new MailDriverManager([$driver]));
        $this->app->forgetInstance(NotificationService::class);
    }
}
