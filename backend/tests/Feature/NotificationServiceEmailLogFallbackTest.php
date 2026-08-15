<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\NotificationTemplate;
use App\Models\Setting;
use App\Services\Mail\Contracts\MailDriver;
use App\Services\Mail\MailDriverManager;
use App\Services\System\AdminLogService;
use App\Services\System\NotificationService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Support\InstallsNotificationTemplateDefaults;
use Tests\TestCase;

class NotificationServiceEmailLogFallbackTest extends TestCase
{
    use InstallsNotificationTemplateDefaults;

    protected function setUp(): void
    {
        parent::setUp();

        $this->installNotificationTemplateDefaults();
    }

    public function test_notification_service_no_longer_uses_plugin_placeholder_smtp_values(): void
    {
        $content = file_get_contents(base_path('app/Services/System/NotificationService.php'));

        $this->assertIsString($content);
        $this->assertStringNotContainsString("\$host = 'plugin'", $content);
        $this->assertStringNotContainsString("\$username = 'plugin'", $content);
        $this->assertStringNotContainsString('邮件接口配置不完整', $content);
    }

    public function test_send_email_writes_message_logs(): void
    {
        if (! Schema::hasTable('message_logs')) {
            $this->markTestSkipped('message_logs 表不存在，无法验证统一消息日志。');
        }

        $suffix = bin2hex(random_bytes(4));
        $to = "codex-mail-{$suffix}@example.com";
        $subject = "Codex 邮件日志测试 {$suffix}";
        $content = "<p>mail message log {$suffix}</p>";

        $settings = [
            'email_enabled' => Setting::getValue('notification', 'email_enabled', '0'),
        ];

        $fakeMailDriver = $this->makeFakeMailDriver();

        try {
            Setting::setValue('notification', 'email_enabled', '1');

            $this->useFakeMailDriver($fakeMailDriver);

            app(NotificationService::class)->sendEmail($to, $subject, $content, NotificationService::TEMPLATE_INVOICE_NOTICE);

            $this->assertCount(1, $fakeMailDriver->messages);
            $this->assertSame($to, $fakeMailDriver->messages[0]['payload']['to'] ?? null);
            $this->assertSame($subject, $fakeMailDriver->messages[0]['payload']['subject'] ?? null);
            $this->assertSame(['no-reply@example.com', 'Codex Test'], $fakeMailDriver->messages[0]['payload']['from'] ?? null);

            $log = DB::table('message_logs')
                ->where('channel', 'email')
                ->where('recipient', $to)
                ->where('subject', $subject)
                ->orderByDesc('id')
                ->first();

            $this->assertNotNull($log);
            $this->assertSame('success', $log->status ?? null);
            $this->assertNull($log->error_msg ?? null);
            $this->assertNotNull($log->sent_at ?? null);
        } finally {
            DB::table('message_logs')
                ->where('channel', 'email')
                ->where('recipient', $to)
                ->where('subject', $subject)
                ->delete();

            foreach ($settings as $key => $value) {
                Setting::setValue('notification', $key, $value);
            }

            $this->forgetFakeMailDriver();
        }
    }

    public function test_send_template_email_uses_template_html_without_public_shell(): void
    {
        $suffix = bin2hex(random_bytes(4));
        $to = "codex-template-{$suffix}@example.com";
        $template = NotificationTemplate::query()
            ->where('channel', 'email')
            ->where('code', NotificationService::TEMPLATE_EMAIL_CODE)
            ->firstOrFail();
        $templateSnapshot = [
            'subject' => $template->subject,
            'content' => $template->content,
            'is_custom' => $template->is_custom,
        ];
        $notificationSettings = [
            'email_enabled' => Setting::getValue('notification', 'email_enabled', '0'),
        ];
        $basicSettings = [
            'site_name' => Setting::getValue('basic', 'site_name', ''),
            'site_logo' => Setting::getValue('basic', 'site_logo', ''),
        ];

        $fakeMailDriver = $this->makeFakeMailDriver();

        try {
            config([
                'app.url' => 'https://api.example.test',
                'app.frontend_url' => 'https://www.example.test',
            ]);
            Setting::setValue('notification', 'email_enabled', '1');
            Setting::setValue('basic', 'site_name', 'Codex Billing');
            Setting::setValue('basic', 'site_logo', '/uploads/site/logo-test.png');
            $template->forceFill([
                'subject' => '测试验证码',
                'content' => '<style>.email-test-code { color: #1f5eff; font-weight: 700; }</style><div class="email-test-code">{{#site_logo}}<img class="email-logo" src="{{site_logo}}" alt="{{site_name}}">{{/site_logo}}<span>{{site_name}}</span><strong>{{code}}</strong></div>',
            ])->save();

            $this->useFakeMailDriver($fakeMailDriver);

            app(NotificationService::class)->sendTemplateEmail($to, NotificationService::TEMPLATE_EMAIL_CODE, [
                'code' => '482915',
                'expire_minutes' => 10,
            ]);

            $this->assertCount(1, $fakeMailDriver->messages);

            $html = (string) ($fakeMailDriver->messages[0]['html'] ?? '');

            $this->assertStringContainsString('class="email-logo"', $html);
            $this->assertStringContainsString(
                'src="https://api.example.test/uploads/site/logo-test.png"',
                $html
            );
            $this->assertStringContainsString('<span>Codex Billing</span>', $html);
            $this->assertStringContainsString('<strong>482915</strong>', $html);
            $this->assertStringContainsString('.email-test-code { color: #1f5eff; font-weight: 700; }', $html);
            $this->assertStringNotContainsString('mail-shell', $html);
            $this->assertStringNotContainsString('mail-card', $html);
            $this->assertStringNotContainsString('自动通知邮件', $html);

            Setting::setValue('basic', 'site_logo', '/branding/logo.svg');

            app(NotificationService::class)->sendTemplateEmail($to, NotificationService::TEMPLATE_EMAIL_CODE, [
                'code' => '482915',
                'expire_minutes' => 10,
            ]);

            $this->assertCount(2, $fakeMailDriver->messages);
            $brandingHtml = (string) ($fakeMailDriver->messages[1]['html'] ?? '');
            $this->assertStringContainsString(
                'src="https://www.example.test/branding/logo.svg"',
                $brandingHtml
            );
        } finally {
            $this->deleteEmailLogsByRecipient($to);

            NotificationTemplate::query()
                ->whereKey($template->getKey())
                ->update($templateSnapshot);

            foreach ($notificationSettings as $key => $value) {
                Setting::setValue('notification', $key, $value);
            }

            foreach ($basicSettings as $key => $value) {
                Setting::setValue('basic', $key, $value);
            }

            $this->forgetFakeMailDriver();
        }
    }

    public function test_email_verification_code_is_kept_raw_in_logs(): void
    {
        if (! Schema::hasTable('message_logs')) {
            $this->markTestSkipped('message_logs 表不存在，无法验证统一消息日志原文。');
        }

        $suffix = bin2hex(random_bytes(4));
        $to = "codex-email-code-{$suffix}@example.com";
        $code = '482915';
        $settings = [
            'email_enabled' => Setting::getValue('notification', 'email_enabled', '0'),
        ];

        $fakeMailDriver = $this->makeFakeMailDriver();

        try {
            Setting::setValue('notification', 'email_enabled', '1');

            $this->useFakeMailDriver($fakeMailDriver);

            app(NotificationService::class)->sendEmailCode($to, $code);

            $log = DB::table('message_logs')
                ->where('channel', 'email')
                ->where('recipient', $to)
                ->where('template_code', NotificationService::TEMPLATE_EMAIL_CODE)
                ->orderByDesc('id')
                ->first();

            $this->assertNotNull($log);
            $this->assertStringContainsString($code, (string) $log->content);

            DB::table('message_logs')
                ->where('id', $log->id)
                ->update(['content' => "验证码：{$code}"]);

            $payload = app(AdminLogService::class)->getEmailLogs(['email' => $to], 15);
            $adminLog = collect($payload['data'] ?? [])
                ->first(fn (array $item): bool => ($item['to_email'] ?? null) === $to);

            $this->assertNotNull($adminLog);
            $this->assertStringContainsString($code, (string) ($adminLog['content'] ?? ''));
        } finally {
            $this->deleteEmailLogsByRecipient($to);

            foreach ($settings as $key => $value) {
                Setting::setValue('notification', $key, $value);
            }

            $this->forgetFakeMailDriver();
        }
    }

    private function deleteEmailLogsByRecipient(string $to): void
    {
        if (Schema::hasTable('message_logs')) {
            DB::table('message_logs')
                ->where('channel', 'email')
                ->where('recipient', $to)
                ->delete();
        }
    }

    private function useFakeMailDriver(MailDriver $driver): void
    {
        $this->app->forgetInstance(MailDriverManager::class);
        $this->app->instance(MailDriverManager::class, new MailDriverManager([$driver]));
        $this->app->forgetInstance(NotificationService::class);
    }

    private function forgetFakeMailDriver(): void
    {
        $this->app->forgetInstance(MailDriverManager::class);
        $this->app->forgetInstance(NotificationService::class);
    }

    private function makeFakeMailDriver(): object
    {
        return new class implements MailDriver
        {
            public array $messages = [];

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
                $this->messages[] = [
                    'html' => $html,
                    'payload' => [
                        'to' => $to,
                        'subject' => $subject,
                        'from' => ['no-reply@example.com', 'Codex Test'],
                        'context' => $context,
                    ],
                ];
            }
        };
    }
}
