<?php

namespace App\Services\Automation;

use App\Constants\ServiceStatus;
use App\Constants\UserNotificationType;
use App\Models\AutomationLog;
use App\Models\Service;
use App\Services\Notification\UserNotificationService;
use App\Services\ClientServiceConsole\ServiceSuspensionService;
use App\Services\System\NotificationService;
use App\Services\System\SettingService;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class ServiceLifecycleAutomationService
{
    private const EXPIRED_SUSPENDED_AT_KEY = 'expired_suspended_at';

    public function __construct(
        private SettingService $settingService,
        private NotificationService $notificationService,
        private UserNotificationService $userNotificationService,
        private ?ServiceSuspensionService $suspensionService = null,
    ) {}

    private function suspensionService(): ServiceSuspensionService
    {
        return $this->suspensionService ??= app(ServiceSuspensionService::class);
    }

    public function handle(): array
    {
        $config = $this->settingService->getAutomationConfig();

        return [
            'suspended' => $this->suspendExpiredServices($config),
            'suspend_notified' => $this->sendSuspendNotifications($config),
            'cancelled' => $this->cancelExpiredServices($config),
        ];
    }

    private function suspendExpiredServices(array $config): int
    {
        if (! $config['expire_suspend_enabled']) {
            return 0;
        }

        $handledAt = now();

        // 到期超过 N 天（0 = 到期当天）才暂停
        $threshold = $handledAt->copy()->subDays($config['expire_suspend_after_days']);

        $services = Service::query()
            ->where('status', ServiceStatus::ACTIVE)
            ->whereNotNull('expires_at')
            ->where('expires_at', '<', $threshold)   // 严格小于，避免误杀刚好今天到期的
            ->get();

        $count = 0;

        foreach ($services as $service) {
            $suspended = DB::transaction(function () use ($service, $handledAt, $threshold) {
                $locked = Service::query()->lockForUpdate()->find((int) $service->id);

                if (! $locked instanceof Service) {
                    return false;
                }

                // 写前重读校验：续费等并发操作已改变状态或到期时间时跳过，防止把刚续费的服务打回暂停
                if ((int) $locked->status !== ServiceStatus::ACTIVE
                    || ! $locked->expires_at instanceof Carbon
                    || $locked->expires_at->gte($threshold)) {
                    return false;
                }

                $provisionData = is_array($locked->provision_data ?? null) ? $locked->provision_data : [];
                $provisionData[self::EXPIRED_SUSPENDED_AT_KEY] = $handledAt->format('Y-m-d H:i:s');

                $locked->forceFill([
                    'status' => ServiceStatus::SUSPENDED,
                    'suspended_reason' => Service::SUSPENDED_REASON_EXPIRED,
                    'provision_data' => $provisionData,
                ])->save();

                return true;
            });

            if (! $suspended) {
                continue;
            }

            Log::info('[定时任务] 服务到期自动暂停', [
                'service_id' => $service->id,
                'service_name' => $service->name,
                'expires_at' => $service->expires_at?->toDateTimeString(),
                'suspended_at' => $handledAt->toDateTimeString(),
            ]);

            $count++;
        }

        return $count;
    }

    private function sendSuspendNotifications(array $config): int
    {
        if (! $config['expire_suspend_enabled'] || ! $config['expire_suspend_notify_enabled']) {
            return 0;
        }

        $siteName = (string) config('idc.site_name', config('app.name', '服务商'));

        $services = Service::query()
            ->with('user:id,email,nickname')
            ->where('status', ServiceStatus::SUSPENDED)
            ->where('suspended_reason', Service::SUSPENDED_REASON_EXPIRED)
            ->whereNotNull('expires_at')
            ->get();

        $count = 0;

        foreach ($services as $service) {
            $email = trim((string) ($service->user?->email ?? ''));
            $expiryDate = $service->expires_at?->format('Y-m-d') ?? 'unknown';
            $ruleKey = "expiry:{$expiryDate}";

            if ($email === '' || ! AutomationLog::recordOnce(
                'service-lifecycle-maintenance', 'service_suspend_notify', 'service', (int) $service->id, $ruleKey
            )) {
                continue;
            }

            $displayName = $service->user?->display_name ?? '客户';
            $expiryStr = $service->expires_at?->format('Y-m-d H:i') ?? '-';

            try {
                $this->notificationService->sendTemplateEmail($email, NotificationService::TEMPLATE_SERVICE_SUSPENDED, [
                    'site_name' => $siteName,
                    'display_name' => $displayName,
                    'service_name' => (string) $service->name,
                    'expires_at' => $expiryStr,
                ]);
                $this->userNotificationService->create(
                    (int) $service->user_id,
                    UserNotificationType::SERVICE_EXPIRE_REMINDER,
                    '服务已到期暂停',
                    "您的服务「{$service->name}」已于 {$expiryStr} 到期并暂停，请尽快续费恢复使用。",
                    '/client/services/'.$service->id,
                    ['service_id' => (int) $service->id, 'expires_at' => $expiryStr]
                );
                AutomationLog::markExecuted(
                    'service-lifecycle-maintenance',
                    'service_suspend_notify',
                    'service',
                    (int) $service->id,
                    $ruleKey,
                    [
                        'email' => $email,
                        'expires_at' => $expiryStr,
                    ]
                );
                $count++;
            } catch (\Throwable $exception) {
                AutomationLog::forgetRecord(
                    'service-lifecycle-maintenance',
                    'service_suspend_notify',
                    'service',
                    (int) $service->id,
                    $ruleKey
                );
                Log::warning('[定时任务] 服务到期暂停通知发送失败', [
                    'service_id' => $service->id,
                    'email' => $email,
                    'message' => $exception->getMessage(),
                ]);
            }
        }

        return $count;
    }

    private function cancelExpiredServices(array $config): int
    {
        if (! $config['expire_terminate_enabled']) {
            return 0;
        }

        // 严格按“暂停后终止天数”执行，而不是按到期时间直接计算。
        $graceDays = max(1, (int) ($config['expire_terminate_after_days'] ?? 1));
        $resolvedNow = now();

        $services = Service::query()
            ->with('user:id,email,nickname')
            ->where('status', ServiceStatus::SUSPENDED)
            ->where('suspended_reason', Service::SUSPENDED_REASON_EXPIRED)
            ->whereNotNull('expires_at')
            ->get();

        $count = 0;

        foreach ($services as $service) {
            // 第一步：锁定预检（不改状态），确认该服务确实到达终止时间点
            $dueService = DB::transaction(function () use ($service, $graceDays, $config) {
                $locked = Service::query()->lockForUpdate()->find((int) $service->id);

                if (! $locked instanceof Service) {
                    return null;
                }

                if ((int) $locked->status !== ServiceStatus::SUSPENDED
                    || (string) $locked->suspended_reason !== Service::SUSPENDED_REASON_EXPIRED) {
                    return null;
                }

                $resolvedSuspendedAt = $this->resolveExpiredSuspendedAt(
                    $locked,
                    (int) ($config['expire_suspend_after_days'] ?? 0)
                );

                if (! $resolvedSuspendedAt instanceof Carbon) {
                    return null;
                }

                $terminateAt = $resolvedSuspendedAt->copy()->addDays($graceDays);
                if ($terminateAt->isFuture()) {
                    return null;
                }

                return $locked;
            });

            if (! $dueService instanceof Service) {
                continue;
            }

            // 第二步：销毁上游实例（对齐魔方下游 Host::terminate 协议）。
            // 无上游/驱动不支持终止时直接放行；销毁失败保持 SUSPENDED，下一轮重试。
            if (! $this->suspensionService()->tryTerminateUpstream($dueService, '到期自动终止')) {
                Log::warning('[定时任务] 上游实例销毁失败，服务保留暂停状态待下轮重试', [
                    'service_id' => $dueService->id,
                    'service_name' => $dueService->name,
                ]);
                continue;
            }

            // 第三步：写前重读置 CANCELLED。若上游销毁期间被续费恢复则跳过本地取消并告警人工核实
            $suspendedAt = DB::transaction(function () use ($dueService, $config) {
                $locked = Service::query()->lockForUpdate()->find((int) $dueService->id);

                if (! $locked instanceof Service) {
                    return null;
                }

                if ((int) $locked->status !== ServiceStatus::SUSPENDED
                    || (string) $locked->suspended_reason !== Service::SUSPENDED_REASON_EXPIRED) {
                    return null;
                }

                $resolvedSuspendedAt = $this->resolveExpiredSuspendedAt(
                    $locked,
                    (int) ($config['expire_suspend_after_days'] ?? 0)
                );

                if (! $resolvedSuspendedAt instanceof Carbon) {
                    return null;
                }

                $provisionData = is_array($locked->provision_data ?? null) ? $locked->provision_data : [];
                unset($provisionData[self::EXPIRED_SUSPENDED_AT_KEY]);

                $locked->forceFill([
                    'status' => ServiceStatus::CANCELLED,
                    'suspended_reason' => null,
                    'provision_data' => $provisionData,
                ])->save();

                return $resolvedSuspendedAt;
            });

            if (! $suspendedAt instanceof Carbon) {
                Log::warning('[定时任务] 上游实例已销毁但本地服务状态已被并发变更，请人工核实', [
                    'service_id' => $dueService->id,
                    'service_name' => $dueService->name,
                ]);
                continue;
            }

            Log::info('[定时任务] 服务超保留期自动取消', [
                'service_id' => $service->id,
                'service_name' => $service->name,
                'expires_at' => $service->expires_at?->toDateTimeString(),
                'suspended_at' => $suspendedAt->toDateTimeString(),
                'grace_days' => $graceDays,
                'terminate_at' => $suspendedAt->copy()->addDays($graceDays)->toDateTimeString(),
                'handled_at' => $resolvedNow->toDateTimeString(),
            ]);

            $count++;
        }

        return $count;
    }

    private function resolveExpiredSuspendedAt(Service $service, int $expireSuspendAfterDays): ?Carbon
    {
        $provisionData = is_array($service->provision_data ?? null) ? $service->provision_data : [];
        $storedSuspendedAt = trim((string) ($provisionData[self::EXPIRED_SUSPENDED_AT_KEY] ?? ''));

        if ($storedSuspendedAt !== '') {
            try {
                return Carbon::parse($storedSuspendedAt);
            } catch (\Throwable) {
                // 兼容历史脏数据，继续回退到其他时间来源。
            }
        }

        if ($service->expires_at instanceof Carbon) {
            return $service->expires_at->copy()->addDays(max(0, $expireSuspendAfterDays));
        }

        if ($service->updated_at instanceof Carbon) {
            return $service->updated_at->copy();
        }

        return null;
    }
}
