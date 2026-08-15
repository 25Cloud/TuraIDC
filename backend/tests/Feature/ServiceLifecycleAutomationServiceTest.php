<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Constants\ServiceStatus;
use App\Models\Service;
use App\Services\Automation\ServiceLifecycleAutomationService;
use App\Services\Notification\UserNotificationService;
use App\Services\System\NotificationService;
use App\Services\System\SettingService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class ServiceLifecycleAutomationServiceTest extends TestCase
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
            $table->timestamps();
        });

        Schema::connection('sqlite')->create('services', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id')->nullable();
            $table->string('name')->nullable();
            $table->unsignedTinyInteger('status')->default(0);
            $table->timestamp('expires_at')->nullable();
            $table->json('provision_data')->nullable();
            $table->string('suspended_reason')->nullable();
            $table->string('trace_id', 64)->nullable();
            $table->unsignedTinyInteger('auto_renew')->default(0);
            $table->timestamps();
        });
    }

    protected function tearDown(): void
    {
        Schema::connection('sqlite')->dropIfExists('services');
        Schema::connection('sqlite')->dropIfExists('users');

        parent::tearDown();
    }

    public function test_it_will_not_terminate_a_service_immediately_when_terminate_days_is_zero(): void
    {
        $service = Service::query()->create([
            'name' => '测试服务A',
            'status' => ServiceStatus::ACTIVE,
            'expires_at' => now()->subDays(2),
            'provision_data' => [],
        ]);

        $automationService = new ServiceLifecycleAutomationService(
            $this->fakeSettingService([
                'expire_suspend_enabled' => true,
                'expire_suspend_after_days' => 0,
                'expire_suspend_notify_enabled' => false,
                'expire_terminate_enabled' => true,
                'expire_terminate_after_days' => 0,
            ]),
            $this->createMock(NotificationService::class),
            $this->createMock(UserNotificationService::class),
        );

        $summary = $automationService->handle();
        $service->refresh();

        $this->assertSame(1, $summary['suspended']);
        $this->assertSame(0, $summary['cancelled']);
        $this->assertSame(ServiceStatus::SUSPENDED, (int) $service->status);
        $this->assertSame('expired', $service->suspended_reason);
        $this->assertNotEmpty($service->provision_data['expired_suspended_at'] ?? null);
    }

    public function test_it_clears_suspend_reason_when_a_service_is_auto_cancelled(): void
    {
        $service = Service::query()->create([
            'name' => '测试服务B',
            'status' => ServiceStatus::SUSPENDED,
            'expires_at' => now()->subDays(5),
            'suspended_reason' => 'expired',
            'provision_data' => [
                'expired_suspended_at' => now()->subDays(3)->format('Y-m-d H:i:s'),
            ],
        ]);

        $automationService = new ServiceLifecycleAutomationService(
            $this->fakeSettingService([
                'expire_suspend_enabled' => true,
                'expire_suspend_after_days' => 0,
                'expire_suspend_notify_enabled' => false,
                'expire_terminate_enabled' => true,
                'expire_terminate_after_days' => 1,
            ]),
            $this->createMock(NotificationService::class),
            $this->createMock(UserNotificationService::class),
        );

        $summary = $automationService->handle();
        $service->refresh();

        $this->assertSame(1, $summary['cancelled']);
        $this->assertSame(ServiceStatus::CANCELLED, (int) $service->status);
        $this->assertNull($service->suspended_reason);
        $this->assertArrayNotHasKey('expired_suspended_at', $service->provision_data);
    }

    public function test_it_does_not_suspend_a_service_renewed_before_write(): void
    {
        // 模拟扫描窗口内完成续费：到期时间已延长，暂停任务必须跳过（锁内重读校验防覆盖续费）
        $service = Service::query()->create([
            'name' => '续费恢复服务',
            'status' => ServiceStatus::ACTIVE,
            'expires_at' => now()->addDays(30),
            'provision_data' => [],
        ]);

        $automationService = new ServiceLifecycleAutomationService(
            $this->fakeSettingService([
                'expire_suspend_enabled' => true,
                'expire_suspend_after_days' => 0,
                'expire_suspend_notify_enabled' => false,
                'expire_terminate_enabled' => false,
                'expire_terminate_after_days' => 1,
            ]),
            $this->createMock(NotificationService::class),
            $this->createMock(UserNotificationService::class),
        );

        $summary = $automationService->handle();
        $service->refresh();

        $this->assertSame(0, $summary['suspended']);
        $this->assertSame(ServiceStatus::ACTIVE, (int) $service->status);
        $this->assertArrayNotHasKey('expired_suspended_at', $service->provision_data);
    }

    public function test_it_does_not_cancel_a_service_restored_by_renewal_before_write(): void
    {
        // 模拟扫描窗口内用户续费恢复 ACTIVE：取消任务必须跳过，避免不可逆取消刚续费的服务
        $service = Service::query()->create([
            'name' => '取消前续费服务',
            'status' => ServiceStatus::ACTIVE,
            'expires_at' => now()->addDays(30),
            'suspended_reason' => null,
            'provision_data' => [],
        ]);

        $automationService = new ServiceLifecycleAutomationService(
            $this->fakeSettingService([
                'expire_suspend_enabled' => true,
                'expire_suspend_after_days' => 0,
                'expire_suspend_notify_enabled' => false,
                'expire_terminate_enabled' => true,
                'expire_terminate_after_days' => 1,
            ]),
            $this->createMock(NotificationService::class),
            $this->createMock(UserNotificationService::class),
        );

        $summary = $automationService->handle();
        $service->refresh();

        $this->assertSame(0, $summary['cancelled']);
        $this->assertSame(ServiceStatus::ACTIVE, (int) $service->status);
    }

    public function test_it_does_not_suspend_when_expires_at_is_still_in_future(): void
    {
        // 到期时间未超过阈值（严格小于才暂停）时保持 ACTIVE
        $service = Service::query()->create([
            'name' => '阈值边界服务',
            'status' => ServiceStatus::ACTIVE,
            'expires_at' => now()->addSeconds(30),
            'provision_data' => [],
        ]);

        $automationService = new ServiceLifecycleAutomationService(
            $this->fakeSettingService([
                'expire_suspend_enabled' => true,
                'expire_suspend_after_days' => 0,
                'expire_suspend_notify_enabled' => false,
                'expire_terminate_enabled' => false,
                'expire_terminate_after_days' => 1,
            ]),
            $this->createMock(NotificationService::class),
            $this->createMock(UserNotificationService::class),
        );

        $summary = $automationService->handle();
        $service->refresh();

        $this->assertSame(0, $summary['suspended']);
        $this->assertSame(ServiceStatus::ACTIVE, (int) $service->status);
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
