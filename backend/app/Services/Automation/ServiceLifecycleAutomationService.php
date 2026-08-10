<?php

namespace App\Services\Automation;

use App\Constants\ServiceStatus;
use App\Constants\UserNotificationType;
use App\Models\AutomationLog;
use App\Models\Service;
use App\Services\Notification\UserNotificationService;
use App\Services\System\NotificationService;
use App\Services\System\SettingService;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;

class ServiceLifecycleAutomationService
{
    private const EXPIRED_SUSPENDED_AT_KEY = 'expired_suspended_at';

    public function __construct(
        private SettingService $settingService,
        private NotificationService $notificationService,
        private UserNotificationService $userNotificationService,
    ) {}

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
            $provisionData = is_array($service->provision_data ?? null) ? $service->provision_data : [];
            $provisionData[self::EXPIRED_SUSPENDED_AT_KEY] = $handledAt->format('Y-m-d H:i:s');

            $service->forceFill([
                'status' => ServiceStatus::SUSPENDED,
                'suspended_reason' => 'expired',
                'provision_data' => $provisionData,
            ])->save();

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
            ->where('suspended_reason', 'expired')
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
            ->where('suspended_reason', 'expired')
            ->whereNotNull('expires_at')
            ->get();

        $count = 0;

        foreach ($services as $service) {
            $suspendedAt = $this->resolveExpiredSuspendedAt($service, (int) ($config['expire_suspend_after_days'] ?? 0));
            if (! $suspendedAt instanceof Carbon) {
                continue;
            }

            $terminateAt = $suspendedAt->copy()->addDays($graceDays);
            if ($terminateAt->isFuture()) {
                continue;
            }

            $provisionData = is_array($service->provision_data ?? null) ? $service->provision_data : [];
            unset($provisionData[self::EXPIRED_SUSPENDED_AT_KEY]);

            $service->forceFill([
                'status' => ServiceStatus::CANCELLED,
                'suspended_reason' => null,
                'provision_data' => $provisionData,
            ])->save();

            Log::info('[定时任务] 服务超保留期自动取消', [
                'service_id' => $service->id,
                'service_name' => $service->name,
                'expires_at' => $service->expires_at?->toDateTimeString(),
                'suspended_at' => $suspendedAt->toDateTimeString(),
                'grace_days' => $graceDays,
                'terminate_at' => $terminateAt->toDateTimeString(),
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
