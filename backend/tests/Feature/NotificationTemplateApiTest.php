<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\AdminUser;
use App\Models\NotificationTemplate;
use App\Models\Role;
use App\Models\Setting;
use App\Services\Integrations\Plugins\IntegrationDriverBindingResolver;
use App\Services\Mail\Contracts\MailDriver;
use App\Services\Mail\MailDriverManager;
use App\Services\Sms\Contracts\SmsDriver;
use App\Services\Sms\Data\SmsMessageRequest;
use App\Services\Sms\Data\SmsSendRequest;
use App\Services\Sms\Data\SmsSendResult;
use App\Services\Sms\SmsDriverManager;
use App\Services\System\NotificationService;
use App\Services\System\SmsService;
use App\Support\AdminPermissions;
use App\Support\EmailNotificationTemplateDefaults;
use App\Support\EmailTemplateCatalog;
use App\Support\SmsTemplateCatalog;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Laravel\Sanctum\Sanctum;
use Tests\Support\InstallsNotificationTemplateDefaults;
use Tests\TestCase;

class NotificationTemplateApiTest extends TestCase
{
    use InstallsNotificationTemplateDefaults;

    protected function setUp(): void
    {
        parent::setUp();

        $this->installNotificationTemplateDefaults();
    }

    public function test_admin_notification_template_catalog_requires_settings_view_and_returns_email_sms_templates(): void
    {
        $this->getJson('/api/v2/admin/notification-templates')
            ->assertUnauthorized()
            ->assertJsonPath('code', 40100);

        Sanctum::actingAs($this->createAdmin([]));

        $this->getJson('/api/v2/admin/notification-templates')
            ->assertForbidden()
            ->assertJsonPath('code', 40300);

        Sanctum::actingAs($this->createAdmin([AdminPermissions::SETTINGS_VIEW]));

        $this->getJson('/api/v2/admin/notification-templates?per_page=20')
            ->assertUnprocessable()
            ->assertJsonPath('code', 42200)
            ->assertJsonStructure(['data' => ['errors' => ['per_page']]]);

        $response = $this->getJson('/api/v2/admin/notification-templates?channel=sms')
            ->assertOk()
            ->assertJsonPath('code', 0)
            ->assertJsonPath('data.list.0.channel', 'sms');

        $templates = collect($response->json('data.list'));
        $this->assertSame($templates->count(), $response->json('data.total'));
        $this->assertSame(27, $templates->count());
        $this->assertTrue($templates->every(
            fn (array $template): bool => preg_match('/^\d+$/', (string) $template['code']) === 1
        ));
        $this->assertFalse($templates->pluck('code')->contains('send_code'));
        $this->assertFalse($templates->pluck('code')->contains('invoice_pay'));
        $this->assertTrue($templates->contains(
            fn (array $template): bool => $template['code'] === SmsTemplateCatalog::TEMPLATE_VERIFY_CODE
                && $template['name'] === '发送验证码'
                && $template['setting_keys']['content'] === SmsTemplateCatalog::contentSettingKey(SmsTemplateCatalog::TEMPLATE_VERIFY_CODE)
                && str_contains((string) $template['content'], '{code}')
        ));
        $this->assertTrue($templates->contains(
            fn (array $template): bool => $template['code'] === SmsTemplateCatalog::TEMPLATE_AUTO_RENEW_UPCOMING
                && $template['setting_keys']['content'] === SmsTemplateCatalog::contentSettingKey(SmsTemplateCatalog::TEMPLATE_AUTO_RENEW_UPCOMING)
        ));
        $this->assertTrue($templates->contains(
            fn (array $template): bool => $template['code'] === SmsTemplateCatalog::TEMPLATE_SERVICE_ACTIVATED
                && str_contains((string) $template['content'], '已开通')
        ));
        $this->assertTrue($templates->contains(
            fn (array $template): bool => $template['code'] === SmsTemplateCatalog::TEMPLATE_ADMIN_ORDER_PAID
                && $template['audience'] === 'user'
        ));

        $this->assertSame([
            'channel',
            'code',
            'name',
            'description',
            'audience',
            'subject',
            'content',
            'provider_template_id',
            'is_enabled',
            'variables',
            'provider_variables',
            'setting_keys',
        ], array_keys($response->json('data.list.0')));
        $this->assertIsBool($response->json('data.list.0.is_enabled'));
        $this->assertSame(
            SmsTemplateCatalog::enabledSettingKey(SmsTemplateCatalog::TEMPLATE_VERIFY_CODE),
            $templates->firstWhere('code', SmsTemplateCatalog::TEMPLATE_VERIFY_CODE)['setting_keys']['enabled'] ?? null
        );

        $emailResponse = $this->getJson('/api/v2/admin/notification-templates?channel=email')
            ->assertOk()
            ->assertJsonPath('code', 0);

        $emailTemplates = collect($emailResponse->json('data.list'));
        $expectedEmailNames = [
            '验证码邮件',
            '注册成功',
            '登录IP提醒',
            '绑定通知',
            '产品开通通知',
            '产品停用通知',
            '产品恢复通知',
            '产品删除通知',
            '重装系统成功通知',
            '新订单待支付',
            '第1次支付提醒',
            '第2次支付提醒',
            '第3次支付提醒',
            '自动续费预告通知',
            '自动续费通知',
            '付款成功通知',
            '账单退款通知',
            '第1次续费提醒',
            '第2次续费提醒',
            '信用额账单已生成',
            '管理员新订单通知',
            '管理员订单支付通知',
            '工单开通通知',
            '工单新回复通知',
            '工单自动关闭通知',
            '管理员新工单提示',
            '管理员工单回复通知',
            '管理员登录提示',
            '产品解除停用失败通知',
        ];
        $this->assertSame(29, $emailTemplates->count());
        $this->assertSame($expectedEmailNames, $emailTemplates->pluck('name')->all());
        $this->assertSame('100001', $emailTemplates->first()['code']);
        $this->assertSame('100029', $emailTemplates->last()['code']);
        $this->assertSame('user', $emailTemplates->firstWhere('name', '验证码邮件')['audience'] ?? null);
        $this->assertSame('admin', $emailTemplates->firstWhere('name', '管理员新订单通知')['audience'] ?? null);
        $this->assertFalse($emailTemplates->pluck('name')->contains('邮箱验证码'));
        $this->assertFalse($emailTemplates->pluck('name')->contains('新工单提醒'));
        $this->assertTrue($emailTemplates->every(
            fn (array $template): bool => ! str_contains((string) $template['content'], 'hero-visual')
                && str_contains((string) $template['content'], 'meta name="color-scheme"')
                && str_contains((string) $template['content'], '@media (prefers-color-scheme: dark)')
                && str_contains((string) $template['content'], 'background: #1e2130;')
                && str_contains((string) $template['content'], 'color: #667085;')
                && str_contains((string) $template['content'], 'color: #9ca3af;')
        ));
        $suspendedEmailTemplate = (array) $emailTemplates->firstWhere('code', '100006');
        $this->assertStringContainsString('#b91c1c', (string) ($suspendedEmailTemplate['content'] ?? ''));
        $this->assertStringContainsString('#fca5a5', (string) ($suspendedEmailTemplate['content'] ?? ''));
        $this->assertTrue($emailTemplates->contains(
            fn (array $template): bool => $template['code'] === '100001'
                && ! array_key_exists('css', $template)
                && ! array_key_exists('css', $template['setting_keys'])
                && in_array('site_logo', $template['variables'] ?? [], true)
                && str_contains((string) $template['content'], 'class="email-logo"')
                && ($template['setting_keys']['enabled'] ?? null) === EmailTemplateCatalog::enabledSettingKey('100001')
        ));
    }

    public function test_default_email_templates_include_dark_mode_styles_and_semantic_accents(): void
    {
        $templates = collect(EmailNotificationTemplateDefaults::templates());

        $this->assertSame(29, $templates->count());
        $this->assertTrue($templates->every(
            fn (array $template): bool => str_contains((string) $template['content'], 'meta name="color-scheme"')
                && str_contains((string) $template['content'], 'supported-color-schemes')
                && str_contains((string) $template['content'], '@media (prefers-color-scheme: dark)')
                && str_contains((string) $template['content'], '.detail-table { border-color: #374151; background: #1e2130; }')
                && str_contains((string) $template['content'], '.detail-value { background: #1e2130; color: #f3f4f6; }')
                && str_contains((string) $template['content'], '.email-footer { color: #9ca3af; }')
        ));

        $suspendedTemplate = (array) $templates->firstWhere('code', '100006');
        $this->assertSame('#b91c1c', $suspendedTemplate['accent'] ?? null);
        $this->assertStringContainsString('border-top: 4px solid #b91c1c;', (string) ($suspendedTemplate['content'] ?? ''));
        $this->assertStringContainsString('border-top-color: #fca5a5;', (string) ($suspendedTemplate['content'] ?? ''));
        $this->assertContrastAtLeast('#667085', '#ffffff', 4.5, '浅色页脚文本对比度不足');
        $this->assertContrastAtLeast('#9ca3af', '#1e2130', 4.5, '暗色页脚文本对比度不足');
        $this->assertContrastAtLeast('#f3f4f6', '#1e2130', 4.5, '暗色明细值文本对比度不足');
    }

    public function test_seed_email_templates_command_refreshes_unmodified_templates_and_preserves_custom_templates(): void
    {
        $template = $this->notificationTemplate('email', NotificationService::TEMPLATE_EMAIL_CODE);
        $templateSnapshot = [
            'name' => $template->name,
            'description' => $template->description,
            'audience' => $template->audience,
            'subject' => $template->subject,
            'content' => $template->content,
            'variables_json' => $template->variables_json,
            'provider_variables_json' => $template->provider_variables_json,
            'provider_template_id' => $template->provider_template_id,
            'is_enabled' => $template->is_enabled,
            'is_custom' => $template->is_custom,
            'sort_order' => $template->sort_order,
        ];

        try {
            $template->forceFill([
                'content' => '<p>旧默认邮件模板</p>',
                'is_custom' => false,
            ])->save();

            $this->artisan('app:seed-email-templates')->assertExitCode(0);

            $template->refresh();
            $this->assertFalse((bool) $template->is_custom);
            $this->assertStringContainsString('@media (prefers-color-scheme: dark)', (string) $template->content);
            $this->assertStringContainsString('.detail-table { border-color: #374151; background: #1e2130; }', (string) $template->content);

            $template->forceFill([
                'content' => '<p>后台自定义邮件模板</p>',
                'is_custom' => true,
            ])->save();

            $this->artisan('app:seed-email-templates')->assertExitCode(0);

            $template->refresh();
            $this->assertTrue((bool) $template->is_custom);
            $this->assertSame('<p>后台自定义邮件模板</p>', (string) $template->content);

            $this->artisan('app:seed-email-templates', ['--force' => true])->assertExitCode(0);

            $template->refresh();
            $this->assertFalse((bool) $template->is_custom);
            $this->assertNotSame('<p>后台自定义邮件模板</p>', (string) $template->content);
            $this->assertStringContainsString('@media (prefers-color-scheme: dark)', (string) $template->content);
        } finally {
            $template->forceFill($templateSnapshot)->save();
        }
    }

    public function test_admin_notification_template_test_send_requires_manage_permission_and_valid_recipient(): void
    {
        $payload = [
            'channel' => 'email',
            'code' => NotificationService::TEMPLATE_EMAIL_CODE,
            'recipient' => 'tester@example.com',
        ];

        $this->postJson('/api/v2/admin/notification-templates/test-send', $payload)
            ->assertUnauthorized()
            ->assertJsonPath('code', 40100);

        Sanctum::actingAs($this->createAdmin([AdminPermissions::SETTINGS_VIEW]));

        $this->postJson('/api/v2/admin/notification-templates/test-send', $payload)
            ->assertForbidden()
            ->assertJsonPath('code', 40300);

        Sanctum::actingAs($this->createAdmin([AdminPermissions::SETTINGS_MANAGE]));

        $this->postJson('/api/v2/admin/notification-templates/test-send', [
            'channel' => 'email',
            'code' => NotificationService::TEMPLATE_EMAIL_CODE,
            'recipient' => 'not-an-email',
        ])
            ->assertUnprocessable()
            ->assertJsonPath('code', 42200)
            ->assertJsonStructure(['data' => ['errors' => ['recipients.0']]]);

        $this->postJson('/api/v2/admin/notification-templates/test-send', [
            'channel' => 'email',
            'code' => NotificationService::TEMPLATE_EMAIL_CODE,
            'recipients' => ['first@example.com', 'second@example.com'],
        ])
            ->assertUnprocessable()
            ->assertJsonPath('code', 42200)
            ->assertJsonStructure(['data' => ['errors' => ['recipients']]]);
    }

    public function test_admin_notification_template_test_send_sends_email_with_sample_variables(): void
    {
        $to = 'template-email-'.bin2hex(random_bytes(4)).'@example.com';
        $emailEnabled = Setting::getValue('notification', 'email_enabled', '0');
        $driver = new NotificationTemplateFakeMailDriver('smtp');
        $resolver = new NotificationTemplateFakeBindingResolver('demo_sms', 'smtp');

        $this->app->instance(IntegrationDriverBindingResolver::class, $resolver);
        $this->app->instance(MailDriverManager::class, new MailDriverManager([$driver], $resolver));

        Sanctum::actingAs($this->createAdmin([AdminPermissions::SETTINGS_MANAGE]));

        try {
            Setting::setValue('notification', 'email_enabled', '1');

            $this->postJson('/api/v2/admin/notification-templates/test-send', [
                'channel' => 'email',
                'code' => NotificationService::TEMPLATE_EMAIL_CODE,
                'recipient' => $to,
            ])
                ->assertOk()
                ->assertJsonPath('code', 0)
                ->assertJsonPath('data.status', 'success')
                ->assertJsonPath('data.total', 1)
                ->assertJsonPath('data.success_count', 1)
                ->assertJsonPath('data.failed_count', 0)
                ->assertJsonPath('data.results.0.recipient', $to)
                ->assertJsonPath('data.results.0.status', 'success');

            $this->assertSame(1, $driver->sendCount);
            $this->assertSame($to, $driver->messages[0]['to'] ?? null);
            $this->assertSame(
                NotificationService::TEMPLATE_EMAIL_CODE,
                $driver->messages[0]['context']['template_code'] ?? null
            );
            $this->assertStringContainsString('482915', (string) ($driver->messages[0]['html'] ?? ''));
        } finally {
            Setting::setValue('notification', 'email_enabled', $emailEnabled);
            $this->deleteEmailLogsByRecipient($to);
        }
    }

    public function test_admin_notification_template_test_send_reports_sms_failure(): void
    {
        if (! Schema::hasTable('message_logs')) {
            $this->markTestSkipped('通知日志表不存在，无法验证短信模板测试发送。');
        }

        $failedPhone = '138'.random_int(10000000, 89999999);
        $driver = new NotificationTemplateFakeSmsDriver('demo_sms');
        $driver->failMessagePhones = [$failedPhone];
        $resolver = new NotificationTemplateFakeBindingResolver('demo_sms');
        $settings = [
            'sms_enabled' => Setting::getValue('notification', 'sms_enabled', '0'),
            'sms_driver' => Setting::getValue('notification', 'sms_driver', ''),
            'sms_provider' => Setting::getValue('notification', 'sms_provider', ''),
        ];

        $this->app->instance(IntegrationDriverBindingResolver::class, $resolver);
        $this->app->instance(SmsDriverManager::class, new SmsDriverManager([$driver], $resolver));

        Sanctum::actingAs($this->createAdmin([AdminPermissions::SETTINGS_MANAGE]));

        try {
            Setting::setValue('notification', 'sms_enabled', '1');
            Setting::setValue('notification', 'sms_driver', 'demo_sms');
            Setting::setValue('notification', 'sms_provider', 'demo_sms');

            $this->postJson('/api/v2/admin/notification-templates/test-send', [
                'channel' => 'sms',
                'code' => SmsTemplateCatalog::TEMPLATE_VERIFY_CODE,
                'recipient' => $failedPhone,
            ])
                ->assertOk()
                ->assertJsonPath('code', 0)
                ->assertJsonPath('data.status', 'failed')
                ->assertJsonPath('data.total', 1)
                ->assertJsonPath('data.success_count', 0)
                ->assertJsonPath('data.failed_count', 1)
                ->assertJsonPath('data.results.0.recipient', $failedPhone)
                ->assertJsonPath('data.results.0.status', 'failed')
                ->assertJsonPath('data.results.0.error', '短信供应商拒绝发送');

            $this->assertCount(1, $driver->messageRequests);
            $this->assertSame($failedPhone, $driver->messageRequests[0]->phone);
            $this->assertStringContainsString('482915', $driver->messageRequests[0]->content);
        } finally {
            foreach ($settings as $key => $value) {
                Setting::setValue('notification', (string) $key, $value);
            }

            $this->deleteSmsLogsByRecipient($failedPhone);
        }
    }

    public function test_sms_verification_log_uses_configurable_template_content_and_keeps_raw_code(): void
    {
        if (! Schema::hasTable('message_logs')) {
            $this->markTestSkipped('通知日志表不存在，无法验证短信模板日志。');
        }

        $phone = '13900001234';
        $code = '482915';
        $driver = new NotificationTemplateFakeSmsDriver('demo_sms');
        $template = $this->notificationTemplate('sms', SmsTemplateCatalog::TEMPLATE_VERIFY_CODE);
        $settings = [
            'sms_enabled' => Setting::getValue('notification', 'sms_enabled', '0'),
            'sms_driver' => Setting::getValue('notification', 'sms_driver', ''),
            'sms_provider' => Setting::getValue('notification', 'sms_provider', ''),
        ];
        $templateSnapshot = [
            'content' => $template->content,
            'provider_template_id' => $template->provider_template_id,
            'is_custom' => $template->is_custom,
        ];

        try {
            Setting::setValue('notification', 'sms_enabled', '1');
            Setting::setValue('notification', 'sms_driver', 'demo_sms');
            Setting::setValue('notification', 'sms_provider', 'demo_sms');
            $template->forceFill([
                'content' => '验证码 {code}，{expire_minutes} 分钟内有效。',
                'provider_template_id' => 'SMS_CUSTOM_VERIFY',
            ])->save();

            $resolver = new NotificationTemplateFakeBindingResolver('demo_sms');
            (new SmsService(new SmsDriverManager([$driver], $resolver), $resolver))->sendVerifyCode($phone, $code);

            $this->assertSame('SMS_CUSTOM_VERIFY', $driver->lastMessageRequest?->templateCode);
            $this->assertSame('验证码 482915，5 分钟内有效。', $driver->lastMessageRequest?->content);

            $log = $this->latestSmsLog($phone);
            $this->assertNotNull($log);
            $this->assertSame(SmsTemplateCatalog::TEMPLATE_VERIFY_CODE, (string) ($log->template_code ?? ''));
            $this->assertStringContainsString('验证码 482915，5 分钟内有效。', (string) ($log->content ?? ''));
            $this->assertStringContainsString($code, (string) ($log->content ?? ''));
        } finally {
            foreach ($settings as $key => $value) {
                Setting::setValue('notification', (string) $key, $value);
            }

            NotificationTemplate::query()
                ->whereKey($template->getKey())
                ->update($templateSnapshot);

            $this->deleteSmsLogsByRecipient($phone);
        }
    }

    public function test_aliyun_sms_verification_uses_builtin_plugin_template_instead_of_system_template(): void
    {
        if (! Schema::hasTable('message_logs')) {
            $this->markTestSkipped('通知日志表不存在，无法验证短信模板日志。');
        }

        $phone = '13900004567';
        $code = '392817';
        $driver = new NotificationTemplateFakeSmsDriver('aliyun');
        $template = $this->notificationTemplate('sms', SmsTemplateCatalog::TEMPLATE_VERIFY_CODE);
        $settings = [
            'sms_enabled' => Setting::getValue('notification', 'sms_enabled', '0'),
            'sms_driver' => Setting::getValue('notification', 'sms_driver', ''),
            'sms_provider' => Setting::getValue('notification', 'sms_provider', ''),
        ];
        $templateSnapshot = [
            'content' => $template->content,
            'provider_template_id' => $template->provider_template_id,
            'is_custom' => $template->is_custom,
        ];

        try {
            Setting::setValue('notification', 'sms_enabled', '1');
            Setting::setValue('notification', 'sms_driver', 'aliyun');
            Setting::setValue('notification', 'sms_provider', 'aliyun');
            $template->forceFill([
                'content' => '这段系统模板不应被阿里云使用 {code}',
                'provider_template_id' => 'SMS_SHOULD_NOT_BE_USED',
            ])->save();

            $resolver = new NotificationTemplateFakeBindingResolver('aliyun');
            (new SmsService(new SmsDriverManager([$driver], $resolver), $resolver))->sendVerifyCode($phone, $code, [
                'purpose' => 'reset_password',
            ]);

            $this->assertNull($driver->lastMessageRequest);
            $this->assertNotNull($driver->lastRequest);
            $this->assertSame('reset_password', $driver->lastRequest?->option('purpose'));
            $this->assertSame($code, $driver->lastRequest?->code);

            $log = $this->latestSmsLog($phone);
            $this->assertNotNull($log);
            $this->assertSame('100003', (string) ($log->template_code ?? ''));
            $this->assertStringContainsString($code, (string) ($log->content ?? ''));
            $this->assertStringNotContainsString('这段系统模板不应被阿里云使用', (string) ($log->content ?? ''));
        } finally {
            foreach ($settings as $key => $value) {
                Setting::setValue('notification', (string) $key, $value);
            }

            NotificationTemplate::query()
                ->whereKey($template->getKey())
                ->update($templateSnapshot);

            $this->deleteSmsLogsByRecipient($phone);
        }
    }

    public function test_admin_notification_template_save_writes_database_template_instead_of_settings(): void
    {
        $template = $this->notificationTemplate('sms', SmsTemplateCatalog::TEMPLATE_VERIFY_CODE);
        $templateSnapshot = [
            'content' => $template->content,
            'provider_template_id' => $template->provider_template_id,
            'is_custom' => $template->is_custom,
        ];
        $contentKey = SmsTemplateCatalog::contentSettingKey(SmsTemplateCatalog::TEMPLATE_VERIFY_CODE);
        $providerTemplateIdKey = SmsTemplateCatalog::providerTemplateIdSettingKey(SmsTemplateCatalog::TEMPLATE_VERIFY_CODE);
        $newContent = '后台保存验证码 {code}，{expire_minutes} 分钟内有效。';
        $newProviderTemplateId = 'SMS_DB_VERIFY';

        Sanctum::actingAs($this->createAdmin([AdminPermissions::SETTINGS_MANAGE]));

        try {
            $this->postJson('/api/v2/admin/settings', [
                'group' => 'notification',
                'settings' => [
                    $contentKey => $newContent,
                    $providerTemplateIdKey => $newProviderTemplateId,
                ],
            ])
                ->assertOk()
                ->assertJsonPath('code', 0);

            $template->refresh();

            $this->assertSame($newContent, (string) $template->content);
            $this->assertSame($newProviderTemplateId, (string) $template->provider_template_id);
            $this->assertTrue((bool) $template->is_custom);
            $this->assertNotSame($newContent, (string) DB::table('settings')
                ->where('group_key', 'notification')
                ->where('item_key', $contentKey)
                ->value('item_value'));
            $this->assertNotSame($newProviderTemplateId, (string) DB::table('settings')
                ->where('group_key', 'notification')
                ->where('item_key', $providerTemplateIdKey)
                ->value('item_value'));
        } finally {
            NotificationTemplate::query()
                ->whereKey($template->getKey())
                ->update($templateSnapshot);
        }
    }

    public function test_admin_notification_email_template_save_writes_database_template_instead_of_settings(): void
    {
        $template = $this->notificationTemplate('email', '100001');
        $templateSnapshot = [
            'subject' => $template->subject,
            'content' => $template->content,
            'is_custom' => $template->is_custom,
        ];
        $subjectKey = EmailTemplateCatalog::subjectSettingKey('100001');
        $contentKey = EmailTemplateCatalog::contentSettingKey('100001');
        $deprecatedCssKey = 'email_template_css_100001';
        $newSubject = '后台保存验证码邮件';
        $newContent = '<style>.email-test-code { color: #1f5eff; font-weight: 700; }</style><div class="email-test-code">{{code}}</div>';

        Sanctum::actingAs($this->createAdmin([AdminPermissions::SETTINGS_MANAGE]));

        try {
            $this->postJson('/api/v2/admin/settings', [
                'group' => 'notification',
                'settings' => [
                    $subjectKey => $newSubject,
                    $contentKey => $newContent,
                    $deprecatedCssKey => '.deprecated-css { color: red; }',
                ],
            ])
                ->assertOk()
                ->assertJsonPath('code', 0);

            $template->refresh();

            $this->assertSame($newSubject, (string) $template->subject);
            $this->assertSame($newContent, (string) $template->content);
            $this->assertTrue((bool) $template->is_custom);
            $this->assertNotSame($newSubject, (string) DB::table('settings')
                ->where('group_key', 'notification')
                ->where('item_key', $subjectKey)
                ->value('item_value'));
            $this->assertNotSame($newContent, (string) DB::table('settings')
                ->where('group_key', 'notification')
                ->where('item_key', $contentKey)
                ->value('item_value'));
            $this->assertFalse(DB::table('settings')
                ->where('group_key', 'notification')
                ->where('item_key', $deprecatedCssKey)
                ->exists());
        } finally {
            NotificationTemplate::query()
                ->whereKey($template->getKey())
                ->update($templateSnapshot);
        }
    }

    public function test_admin_notification_template_status_save_writes_database_template_instead_of_settings(): void
    {
        $smsTemplate = $this->notificationTemplate('sms', SmsTemplateCatalog::TEMPLATE_VERIFY_CODE);
        $emailTemplate = $this->notificationTemplate('email', NotificationService::TEMPLATE_EMAIL_CODE);
        $smsSnapshot = ['is_enabled' => $smsTemplate->is_enabled, 'is_custom' => $smsTemplate->is_custom];
        $emailSnapshot = ['is_enabled' => $emailTemplate->is_enabled, 'is_custom' => $emailTemplate->is_custom];
        $smsEnabledKey = SmsTemplateCatalog::enabledSettingKey(SmsTemplateCatalog::TEMPLATE_VERIFY_CODE);
        $emailEnabledKey = EmailTemplateCatalog::enabledSettingKey(NotificationService::TEMPLATE_EMAIL_CODE);

        Sanctum::actingAs($this->createAdmin([AdminPermissions::SETTINGS_MANAGE]));

        try {
            $this->postJson('/api/v2/admin/settings', [
                'group' => 'notification',
                'settings' => [
                    $smsEnabledKey => false,
                    $emailEnabledKey => '0',
                ],
            ])
                ->assertOk()
                ->assertJsonPath('code', 0);

            $smsTemplate->refresh();
            $emailTemplate->refresh();

            $this->assertFalse((bool) $smsTemplate->is_enabled);
            $this->assertFalse((bool) $emailTemplate->is_enabled);
            $this->assertSame((bool) $smsSnapshot['is_custom'], (bool) $smsTemplate->is_custom);
            $this->assertFalse(DB::table('settings')
                ->where('group_key', 'notification')
                ->whereIn('item_key', [$smsEnabledKey, $emailEnabledKey])
                ->exists());
        } finally {
            NotificationTemplate::query()->whereKey($smsTemplate->getKey())->update($smsSnapshot);
            NotificationTemplate::query()->whereKey($emailTemplate->getKey())->update($emailSnapshot);
        }
    }

    public function test_disabled_email_template_is_skipped_without_log_or_error(): void
    {
        $to = 'disabled-email-template-'.bin2hex(random_bytes(4)).'@example.com';
        $template = $this->notificationTemplate('email', NotificationService::TEMPLATE_EMAIL_CODE);
        $templateSnapshot = ['is_enabled' => $template->is_enabled];
        $emailEnabled = Setting::getValue('notification', 'email_enabled', '0');
        $driver = new NotificationTemplateFakeMailDriver('smtp');
        $resolver = new NotificationTemplateFakeBindingResolver('demo_sms', 'smtp');
        $beforeLogCount = $this->countEmailLogsByRecipient($to);

        try {
            Setting::setValue('notification', 'email_enabled', '1');
            $template->forceFill(['is_enabled' => false])->save();

            (new NotificationService(new MailDriverManager([$driver], $resolver), $resolver))->sendTemplateEmail(
                $to,
                NotificationService::TEMPLATE_EMAIL_CODE,
                ['code' => '123456', 'expire_minutes' => '10']
            );

            $this->assertSame(0, $driver->sendCount);
            $this->assertSame($beforeLogCount, $this->countEmailLogsByRecipient($to));
        } finally {
            Setting::setValue('notification', 'email_enabled', $emailEnabled);
            NotificationTemplate::query()->whereKey($template->getKey())->update($templateSnapshot);
            $this->deleteEmailLogsByRecipient($to);
        }
    }

    public function test_disabled_sms_template_is_skipped_without_log_or_error(): void
    {
        $phone = '139'.random_int(10000000, 99999999);
        $driver = new NotificationTemplateFakeSmsDriver('aliyun');
        $template = $this->notificationTemplate('sms', SmsTemplateCatalog::TEMPLATE_VERIFY_CODE);
        $settings = [
            'sms_enabled' => Setting::getValue('notification', 'sms_enabled', '0'),
            'sms_driver' => Setting::getValue('notification', 'sms_driver', ''),
            'sms_provider' => Setting::getValue('notification', 'sms_provider', ''),
        ];
        $templateSnapshot = ['is_enabled' => $template->is_enabled];
        $beforeLogCount = $this->countSmsLogsByRecipient($phone);

        try {
            Setting::setValue('notification', 'sms_enabled', '1');
            Setting::setValue('notification', 'sms_driver', 'aliyun');
            Setting::setValue('notification', 'sms_provider', 'aliyun');
            $template->forceFill(['is_enabled' => false])->save();

            $resolver = new NotificationTemplateFakeBindingResolver('aliyun');
            (new SmsService(new SmsDriverManager([$driver], $resolver), $resolver))->sendVerifyCode($phone, '482915');

            $this->assertNull($driver->lastRequest);
            $this->assertNull($driver->lastMessageRequest);
            $this->assertSame($beforeLogCount, $this->countSmsLogsByRecipient($phone));
        } finally {
            foreach ($settings as $key => $value) {
                Setting::setValue('notification', (string) $key, $value);
            }

            NotificationTemplate::query()->whereKey($template->getKey())->update($templateSnapshot);
            $this->deleteSmsLogsByRecipient($phone);
        }
    }

    private function assertContrastAtLeast(string $foreground, string $background, float $minimum, string $message): void
    {
        $this->assertGreaterThanOrEqual($minimum, self::contrastRatio($foreground, $background), $message);
    }

    private static function contrastRatio(string $foreground, string $background): float
    {
        $foregroundLuminance = self::relativeLuminance($foreground);
        $backgroundLuminance = self::relativeLuminance($background);
        $lighter = max($foregroundLuminance, $backgroundLuminance);
        $darker = min($foregroundLuminance, $backgroundLuminance);

        return ($lighter + 0.05) / ($darker + 0.05);
    }

    private static function relativeLuminance(string $hex): float
    {
        $hex = ltrim($hex, '#');
        $red = hexdec(substr($hex, 0, 2)) / 255;
        $green = hexdec(substr($hex, 2, 2)) / 255;
        $blue = hexdec(substr($hex, 4, 2)) / 255;
        $linear = static fn (float $channel): float => $channel <= 0.03928
            ? $channel / 12.92
            : (($channel + 0.055) / 1.055) ** 2.4;

        return 0.2126 * $linear($red) + 0.7152 * $linear($green) + 0.0722 * $linear($blue);
    }

    private function latestSmsLog(string $phone): ?object
    {
        if (! Schema::hasTable('message_logs')) {
            return null;
        }

        return DB::table('message_logs')
            ->where('channel', 'sms')
            ->where('recipient', $phone)
            ->orderByDesc('id')
            ->first();
    }

    private function countSmsLogsByRecipient(string $phone): int
    {
        if (! Schema::hasTable('message_logs')) {
            return 0;
        }

        return DB::table('message_logs')
            ->where('channel', 'sms')
            ->where('recipient', $phone)
            ->count();
    }

    private function countEmailLogsByRecipient(string $email): int
    {
        if (! Schema::hasTable('message_logs')) {
            return 0;
        }

        return DB::table('message_logs')
            ->where('channel', 'email')
            ->where('recipient', $email)
            ->count();
    }

    private function deleteSmsLogsByRecipient(string $phone): void
    {
        if (Schema::hasTable('message_logs')) {
            DB::table('message_logs')
                ->where('channel', 'sms')
                ->where('recipient', $phone)
                ->delete();
        }
    }

    private function deleteEmailLogsByRecipient(string $email): void
    {
        if (Schema::hasTable('message_logs')) {
            DB::table('message_logs')
                ->where('channel', 'email')
                ->where('recipient', $email)
                ->delete();
        }
    }

    private function notificationTemplate(string $channel, string $code): NotificationTemplate
    {
        return NotificationTemplate::query()
            ->where('channel', $channel)
            ->where('code', $code)
            ->firstOrFail();
    }

    /**
     * @param  list<string>  $permissions
     */
    private function createAdmin(array $permissions): AdminUser
    {
        $suffix = bin2hex(random_bytes(4));
        $role = Role::query()->create([
            'name' => 'notification-template-'.$suffix,
            'label' => 'Notification Template',
            'permissions' => $permissions,
        ]);

        return AdminUser::query()->create([
            'username' => 'notification-template-'.$suffix,
            'password' => 'Temp@123456',
            'role_id' => (int) $role->id,
            'nickname' => 'Notification Template',
            'email' => 'notification-template-'.$suffix.'@example.com',
            'status' => 1,
        ]);
    }
}

final class NotificationTemplateFakeBindingResolver extends IntegrationDriverBindingResolver
{
    public function __construct(
        private readonly string $driverKey,
        private readonly string $mailDriverKey = 'smtp',
    ) {}

    public function mailDriverKey(): string
    {
        return $this->mailDriverKey;
    }

    public function mailDriverCandidates(): array
    {
        return [$this->mailDriverKey];
    }

    public function mailContext(?string $driverKey = null): array
    {
        return [
            'plugin_id' => null,
            'driver_key' => $driverKey ?: $this->mailDriverKey,
        ];
    }

    public function smsDriverKey(): string
    {
        return $this->driverKey;
    }

    public function smsDriverCandidates(): array
    {
        return [$this->driverKey];
    }

    public function smsContext(?string $driverKey = null): array
    {
        return [
            'plugin_id' => null,
            'driver_key' => $driverKey ?: $this->driverKey,
        ];
    }
}

final class NotificationTemplateFakeMailDriver implements MailDriver
{
    public int $sendCount = 0;

    /** @var list<array{to: string, subject: string, html: string, context: array<string, mixed>}> */
    public array $messages = [];

    public function __construct(
        private readonly string $key,
    ) {}

    public function key(): string
    {
        return $this->key;
    }

    public function label(): string
    {
        return '测试邮件';
    }

    public function sendHtml(string $to, string $subject, string $html, array $context = []): void
    {
        $this->sendCount++;
        $this->messages[] = compact('to', 'subject', 'html', 'context');
    }
}

final class NotificationTemplateFakeSmsDriver implements SmsDriver
{
    public ?SmsSendRequest $lastRequest = null;

    public ?SmsMessageRequest $lastMessageRequest = null;

    /** @var list<SmsMessageRequest> */
    public array $messageRequests = [];

    /** @var list<string> */
    public array $failMessagePhones = [];

    public function __construct(
        private readonly string $key,
    ) {}

    public function key(): string
    {
        return $this->key;
    }

    public function label(): string
    {
        return '测试短信';
    }

    public function sendMessage(SmsMessageRequest $request): SmsSendResult
    {
        $this->lastMessageRequest = $request;
        $this->messageRequests[] = $request;

        if (in_array($request->phone, $this->failMessagePhones, true)) {
            throw new \RuntimeException('短信供应商拒绝发送');
        }

        return new SmsSendResult(
            status: 'success',
            requestId: 'notification-template-message-test',
            templateCode: $request->templateCode,
            templateParams: ['content' => $request->content],
        );
    }

    public function sendVerifyCode(SmsSendRequest $request): SmsSendResult
    {
        $this->lastRequest = $request;
        $templateCode = match ((string) $request->option('purpose', 'generic')) {
            'change_phone', 'phone_change', 'update_phone' => '100002',
            'reset', 'reset_password', 'password_reset' => '100003',
            'bind_phone', 'new_phone' => '100004',
            'verify_bound_phone', 'verify_phone' => '100005',
            default => '100001',
        };

        return new SmsSendResult(
            status: 'success',
            requestId: 'notification-template-test',
            templateCode: $templateCode,
            templateParams: ['code' => $request->code],
        );
    }
}
