<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\IntegrationPlugin;
use App\Models\Setting;
use App\Services\Integrations\Plugins\PluginConfigRepository;
use App\Services\Integrations\Plugins\PluginInstaller;
use App\Services\Integrations\Plugins\PluginScanner;
use App\Services\System\NotificationService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;
use TuraIDC\Plugins\Mail\MultiSmtpRoundRobin\Lib\MultiSmtpRoundRobinService;

class MultiSmtpRoundRobinPluginTest extends TestCase
{
    public function test_smtp_account_edit_preserves_existing_password_and_enabled_state(): void
    {
        $this->ensurePluginTables();

        $installer = app(PluginInstaller::class);
        $scanner = app(PluginScanner::class);
        $configRepository = app(PluginConfigRepository::class);
        $manifest = $scanner->requireManifest('mail', 'multi_smtp_round_robin');
        $plugin = $installer->install('mail', 'multi_smtp_round_robin');

        try {
            $configRepository->save($plugin, $manifest, [
                'accounts' => [
                    [
                        'host' => 'smtp.example.com',
                        'port' => 465,
                        'username' => 'mail@example.com',
                        'password' => 'original-secret',
                        'from_name' => 'Old Name',
                        'enabled' => true,
                    ],
                ],
                'cooldown_seconds' => 30,
            ]);

            $configRepository->save($plugin, $manifest, [
                'accounts' => [
                    [
                        '__index' => 0,
                        'host' => 'smtp-new.example.com',
                        'port' => 587,
                        'username' => 'mail-new@example.com',
                        'password' => '',
                        'from_name' => 'New Name',
                        'encryption' => 'tls',
                        'enabled' => false,
                    ],
                ],
                'cooldown_seconds' => 30,
            ]);

            $resolved = $configRepository->resolvedConfig($plugin->fresh('config') ?? $plugin);
            $this->assertSame('original-secret', $resolved['accounts'][0]['password'] ?? null);
            $this->assertSame('smtp-new.example.com', $resolved['accounts'][0]['host'] ?? null);
            $this->assertFalse((bool) ($resolved['accounts'][0]['enabled'] ?? true));

            $preview = $configRepository->secretPreviews($plugin->fresh('config') ?? $plugin, $manifest);
            $this->assertFalse((bool) ($preview['accounts']['items'][0]['enabled'] ?? true));
            $this->assertTrue((bool) ($preview['accounts']['items'][0]['password_configured'] ?? false));
        } finally {
            $this->deleteMultiSmtpPluginForTest();
        }
    }

    public function test_notification_service_can_fall_through_to_second_smtp_account(): void
    {
        $this->ensurePluginTables();
        $settings = [
            'email_enabled' => Setting::getValue('notification', 'email_enabled', '0'),
        ];

        $fakeMailManager = $this->makeFakeMailManager();
        $originalMailManager = app('mail.manager');
        $installer = app(PluginInstaller::class);
        $scanner = app(PluginScanner::class);
        $configRepository = app(PluginConfigRepository::class);
        $manifest = $scanner->requireManifest('mail', 'multi_smtp_round_robin');
        $this->disableEnabledMailPluginsForTest();
        $plugin = $installer->install('mail', 'multi_smtp_round_robin');

        try {
            $configRepository->save($plugin, $manifest, [
                'accounts' => [
                    [
                        'host' => 'smtp.invalid.test',
                        'port' => 465,
                        'username' => 'broken@example.com',
                        'password' => '',
                        'from_name' => 'Broken',
                    ],
                    [
                        'host' => 'smtp.example.com',
                        'port' => 465,
                        'username' => 'ok@example.com',
                        'password' => 'secret',
                        'from_name' => 'Round Robin',
                    ],
                ],
                'cooldown_seconds' => 30,
            ]);
            $installer->enable($plugin);

            $this->assertTrue(class_exists(MultiSmtpRoundRobinService::class));

            Setting::setValue('notification', 'email_enabled', '1');
            app()->instance('mail.manager', $fakeMailManager);
            Mail::swap($fakeMailManager);

            $code = (string) random_int(100000, 999999);

            app(NotificationService::class)->sendEmail(
                'plugin-mail@example.com',
                '邮箱验证码',
                "<p>您的邮箱验证码为：{$code}，10分钟内有效。如非本人操作，请忽略此邮件。</p>",
                NotificationService::TEMPLATE_EMAIL_CODE
            );

            $this->assertCount(1, $fakeMailManager->messages);
            $this->assertSame(['ok@example.com', 'Round Robin'], $fakeMailManager->messages[0]['payload']['from'] ?? null);
        } finally {
            foreach ($settings as $key => $value) {
                Setting::setValue('notification', $key, $value);
            }

            app()->instance('mail.manager', $originalMailManager);
            Mail::swap($originalMailManager);

            if (Schema::hasTable('integration_plugin_bindings')) {
                DB::table('integration_plugin_bindings')
                    ->where('domain', 'mail')
                    ->where('binding_key', 'mail_driver')
                    ->where('provider_key', 'multi_smtp_round_robin')
                    ->delete();
            }

            $this->deleteMultiSmtpPluginForTest();
        }
    }

    private function deleteMultiSmtpPluginForTest(): void
    {
        DB::statement('SET FOREIGN_KEY_CHECKS=0');

        try {
            IntegrationPlugin::query()
                ->where('domain', 'mail')
                ->where('slug', 'multi_smtp_round_robin')
                ->delete();
        } finally {
            DB::statement('SET FOREIGN_KEY_CHECKS=1');
        }
    }

    private function disableEnabledMailPluginsForTest(): void
    {
        IntegrationPlugin::query()
            ->where('domain', 'mail')
            ->where('status', IntegrationPlugin::STATUS_ENABLED)
            ->update(['status' => IntegrationPlugin::STATUS_DISABLED]);
    }

    private function ensurePluginTables(): void
    {
        if (! Schema::hasTable('integration_plugins')) {
            Schema::create('integration_plugins', function (Blueprint $table): void {
                $table->id();
                $table->string('domain', 32);
                $table->string('slug', 120);
                $table->string('plugin_key', 120);
                $table->string('name', 120);
                $table->string('version', 32)->default('1.0.0');
                $table->string('provider_class', 255)->nullable();
                $table->string('entry_class', 255);
                $table->json('capabilities_json')->nullable();
                $table->json('config_schema_json')->nullable();
                $table->unsignedTinyInteger('status')->default(0);
                $table->timestamp('installed_at')->nullable();
                $table->timestamps();
                $table->unique(['domain', 'slug']);
                $table->unique(['domain', 'plugin_key']);
            });
        }

        if (! Schema::hasTable('integration_plugin_configs')) {
            Schema::create('integration_plugin_configs', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('plugin_id');
                $table->json('config_json')->nullable();
                $table->longText('secret_json')->nullable();
                $table->json('has_secret_json')->nullable();
                $table->unsignedBigInteger('updated_by')->nullable();
                $table->timestamps();
                $table->unique('plugin_id');
            });
        }

        if (! Schema::hasTable('integration_plugin_bindings')) {
            Schema::create('integration_plugin_bindings', function (Blueprint $table): void {
                $table->id();
                $table->string('domain', 32);
                $table->unsignedBigInteger('plugin_id');
                $table->string('binding_type', 50);
                $table->string('bindable_type', 120)->default('global');
                $table->unsignedBigInteger('bindable_id')->default(0);
                $table->string('binding_key', 120);
                $table->string('provider_key', 120)->nullable();
                $table->integer('priority')->default(0);
                $table->unsignedTinyInteger('status')->default(1);
                $table->json('config_json')->nullable();
                $table->longText('secret_json')->nullable();
                $table->json('has_secret_json')->nullable();
                $table->json('runtime_policy_json')->nullable();
                $table->unsignedBigInteger('created_by')->nullable();
                $table->unsignedBigInteger('updated_by')->nullable();
                $table->string('backfill_batch_id', 64)->nullable();
                $table->timestamps();
                $table->unique(['domain', 'binding_type', 'bindable_type', 'bindable_id', 'binding_key'], 'plugin_bindings_unique');
            });
        }
    }

    private function makeFakeMailManager(): object
    {
        return new class
        {
            public array $messages = [];

            public function forgetMailers(): void {}

            public function html(string $html, callable $callback): void
            {
                $message = new class
                {
                    public array $payload = [];

                    public function to(string $value): self
                    {
                        $this->payload['to'] = $value;

                        return $this;
                    }

                    public function subject(string $value): self
                    {
                        $this->payload['subject'] = $value;

                        return $this;
                    }

                    public function from(string $address, ?string $name = null): self
                    {
                        $this->payload['from'] = [$address, $name];

                        return $this;
                    }
                };

                $callback($message);
                $this->messages[] = [
                    'html' => $html,
                    'payload' => $message->payload,
                ];
            }
        };
    }
}
