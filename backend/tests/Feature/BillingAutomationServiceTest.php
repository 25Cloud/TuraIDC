<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Constants\InvoiceStatus;
use App\Constants\ServiceStatus;
use App\Models\AutomationLog;
use App\Models\Service;
use App\Models\User;
use App\Services\Automation\BillingAutomationService;
use App\Services\Notification\UserNotificationService;
use App\Services\Provisioning\ServiceRenewService;
use App\Services\System\NotificationService;
use App\Services\System\SettingService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class BillingAutomationServiceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config()->set('database.default', 'sqlite');
        config()->set('database.connections.sqlite', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
        ]);

        DB::purge('sqlite');
        DB::reconnect('sqlite');

        Schema::connection('sqlite')->create('users', function (Blueprint $table) {
            $table->id();
            $table->string('email')->nullable();
            $table->string('nickname')->nullable();
            $table->softDeletes();
            $table->timestamps();
        });

        Schema::connection('sqlite')->create('services', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id')->nullable();
            $table->string('name')->nullable();
            $table->string('billing_cycle')->nullable();
            $table->unsignedTinyInteger('status')->default(0);
            $table->timestamp('expires_at')->nullable();
            $table->json('provision_data')->nullable();
            $table->string('suspended_reason')->nullable();
            $table->string('trace_id', 64)->nullable();
            $table->unsignedTinyInteger('auto_renew')->default(0);
            $table->timestamps();
        });

        Schema::connection('sqlite')->create('automation_logs', function (Blueprint $table) {
            $table->id();
            $table->string('task_key', 100);
            $table->string('action', 100);
            $table->string('object_type', 50);
            $table->unsignedBigInteger('object_id');
            $table->string('rule_key', 191)->default('');
            $table->json('meta')->nullable();
            $table->timestamp('executed_at')->nullable();
            $table->timestamps();
            $table->unique(['task_key', 'action', 'object_type', 'object_id', 'rule_key'], 'automation_logs_unique_rule');
        });

        Schema::connection('sqlite')->create('invoices', function (Blueprint $table) {
            $table->id();
            $table->unsignedTinyInteger('status')->default(InvoiceStatus::UNPAID);
            $table->date('due_date')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });
    }

    protected function tearDown(): void
    {
        Schema::connection('sqlite')->dropIfExists('invoices');
        Schema::connection('sqlite')->dropIfExists('automation_logs');
        Schema::connection('sqlite')->dropIfExists('services');
        Schema::connection('sqlite')->dropIfExists('users');

        parent::tearDown();
    }

    public function test_it_sends_a_catch_up_expiry_reminder_once_for_services_already_inside_the_first_window(): void
    {
        $user = User::query()->create([
            'email' => 'renew@test.com',
            'nickname' => '测试客户',
        ]);

        $service = Service::query()->create([
            'user_id' => $user->id,
            'name' => '云服务器 A',
            'billing_cycle' => 'monthly',
            'status' => ServiceStatus::ACTIVE,
            'expires_at' => now()->addDays(5)->setTime(23, 59, 59),
            'provision_data' => [],
        ]);

        $notificationService = $this->createMock(NotificationService::class);
        $notificationService->expects($this->once())
            ->method('sendTemplateEmail')
            ->with(
                'renew@test.com',
                NotificationService::TEMPLATE_SERVICE_RENEW_REMINDER,
                $this->callback(function (array $payload): bool {
                    $this->assertSame('测试客户', $payload['display_name'] ?? null);
                    $this->assertSame('云服务器 A', $payload['service_name'] ?? null);
                    $this->assertSame(5, $payload['days_left'] ?? null);

                    return true;
                })
            );

        $renewService = $this->createMock(ServiceRenewService::class);
        $renewService->expects($this->never())->method('createRenewInvoiceForUser');

        $serviceInstance = new BillingAutomationService(
            $this->fakeSettingService([
                'renew_notice_enabled' => true,
                'renew_create_invoice_enabled' => false,
                'invoice_unpaid_reminder_enabled' => false,
            ]),
            $notificationService,
            $renewService,
            $this->createMock(UserNotificationService::class),
        );

        $firstSummary = $serviceInstance->handle();
        $secondSummary = $serviceInstance->handle();

        $this->assertSame(1, $firstSummary['renew_notice_sent']);
        $this->assertSame(0, $secondSummary['renew_notice_sent']);
        $this->assertTrue(AutomationLog::hasRecord(
            'billing-maintenance',
            'renew_notice',
            'service',
            (int) $service->id,
            'expiry:'.$service->expires_at->format('Y-m-d').':days:7'
        ));
    }

    public function test_it_creates_renew_order_once_when_service_enters_the_initial_renew_window_late(): void
    {
        $user = User::query()->create([
            'email' => 'invoice@test.com',
            'nickname' => '建单客户',
        ]);

        $service = Service::query()->create([
            'user_id' => $user->id,
            'name' => '云服务器 B',
            'billing_cycle' => 'monthly',
            'status' => ServiceStatus::ACTIVE,
            'auto_renew' => 1,
            'expires_at' => now()->addDays(5)->setTime(8, 0, 0),
            'provision_data' => [],
        ]);

        $notificationService = $this->createMock(NotificationService::class);
        $notificationService->expects($this->never())->method('sendTemplateEmail');

        $renewService = $this->createMock(ServiceRenewService::class);
        $renewService->expects($this->once())
            ->method('createRenewInvoiceForUser')
            ->with(
                $this->callback(fn ($userModel) => $userModel instanceof User && (int) $userModel->id === (int) $user->id),
                (int) $service->id,
                'monthly'
            );

        $serviceInstance = new BillingAutomationService(
            $this->fakeSettingService([
                'renew_notice_enabled' => false,
                'renew_create_invoice_enabled' => true,
                'invoice_unpaid_reminder_enabled' => false,
            ]),
            $notificationService,
            $renewService,
            $this->createMock(UserNotificationService::class),
        );

        $firstSummary = $serviceInstance->handle();
        $secondSummary = $serviceInstance->handle();

        $this->assertSame(1, $firstSummary['renew_orders_created']);
        $this->assertSame(0, $secondSummary['renew_orders_created']);
        $this->assertTrue(AutomationLog::hasRecord(
            'billing-maintenance',
            'renew_order_create',
            'service',
            (int) $service->id,
            'expiry:'.$service->expires_at->format('Y-m-d').':auto_order:7'
        ));
    }

    public function test_it_skips_renew_order_creation_when_auto_renew_is_disabled(): void
    {
        $user = User::query()->create([
            'email' => 'noautorenew@test.com',
            'nickname' => '未开续费',
        ]);

        Service::query()->create([
            'user_id' => $user->id,
            'name' => '云服务器 C',
            'billing_cycle' => 'monthly',
            'status' => ServiceStatus::ACTIVE,
            'auto_renew' => 0,
            'expires_at' => now()->addDays(3)->setTime(8, 0, 0),
            'provision_data' => [],
        ]);

        $notificationService = $this->createMock(NotificationService::class);
        $notificationService->expects($this->never())->method('sendTemplateEmail');

        $renewService = $this->createMock(ServiceRenewService::class);
        $renewService->expects($this->never())->method('createRenewInvoiceForUser');

        $serviceInstance = new BillingAutomationService(
            $this->fakeSettingService([
                'renew_notice_enabled' => false,
                'renew_create_invoice_enabled' => true,
                'invoice_unpaid_reminder_enabled' => false,
            ]),
            $notificationService,
            $renewService,
            $this->createMock(UserNotificationService::class),
        );

        $summary = $serviceInstance->handle();

        $this->assertSame(0, $summary['renew_orders_created']);
    }

    private function fakeSettingService(array $config): SettingService
    {
        return new class($config) extends SettingService
        {
            public function __construct(private array $config) {}

            public function getAutomationConfig(): array
            {
                return array_merge(self::defaultAutomationConfig(), $this->config);
            }
        };
    }
}
