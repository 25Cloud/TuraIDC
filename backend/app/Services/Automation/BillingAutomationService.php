<?php

namespace App\Services\Automation;

use App\Constants\InvoiceStatus;
use App\Constants\OrderType;
use App\Constants\ServiceStatus;
use App\Constants\UserNotificationType;
use App\Models\AutomationLog;
use App\Models\Invoice;
use App\Models\Service;
use App\Services\Notification\UserNotificationService;
use App\Services\Provisioning\ServiceRenewService;
use App\Services\System\NotificationService;
use App\Services\System\SettingService;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;

class BillingAutomationService
{
    public function __construct(
        private SettingService $settingService,
        private NotificationService $notificationService,
        private ServiceRenewService $serviceRenewService,
        private UserNotificationService $userNotificationService,
    ) {}

    public function handle(): array
    {
        $config = $this->settingService->getAutomationConfig();

        $results = [
            'renew_notice_sent' => $this->sendRenewNotices($config),
            'auto_renew_upcoming_sent' => $this->sendAutoRenewUpcomingNotices($config),
            'renew_orders_created' => $this->createRenewOrders($config),
            'invoice_pre_due_sent' => $this->sendInvoiceBeforeDueReminders($config),
            'invoice_overdue_sent' => $this->sendInvoiceOverdueReminders($config),
            'invoices_marked_overdue' => $this->markInvoicesOverdue($config),
        ];

        // 续费履约补偿：检查已支付但 fulfillment_pending 未清除的账单
        $results['fulfillment_recovered'] = $this->recoverStuckFulfillments();

        return $results;
    }

    // ─── 服务续费提醒 ──────────────────────────────────────────────────────────

    private function sendRenewNotices(array $config): int
    {
        $renewNoticeDays = collect((array) ($config['renew_notice_days_before'] ?? []))
            ->map(fn ($item) => (int) $item)
            ->filter(fn (int $item) => in_array($item, [7, 3, 1], true))
            ->unique()
            ->sort()
            ->values()
            ->all();

        if (! $config['renew_notice_enabled'] || $renewNoticeDays === []) {
            return 0;
        }

        $resolvedNow = now();
        $siteName = (string) config('idc.site_name', config('app.name', '服务商'));
        $maxRenewNoticeDays = max($renewNoticeDays);

        // 仅通知正常与已暂停的服务（已取消、已终止不再提醒）
        $services = Service::query()
            ->with('user:id,email,nickname')
            ->whereIn('status', [ServiceStatus::ACTIVE, ServiceStatus::SUSPENDED])
            ->whereNotNull('expires_at')
            ->whereBetween('expires_at', [
                $resolvedNow->copy()->startOfDay(),
                $resolvedNow->copy()->addDays($maxRenewNoticeDays)->endOfDay(),
            ])
            ->get();

        $count = 0;

        foreach ($services as $service) {
            $email = trim((string) ($service->user?->email ?? ''));
            if ($email === '') {
                continue;
            }

            $expiresAt = $service->expires_at instanceof Carbon
                ? $service->expires_at
                : Carbon::parse($service->expires_at);

            $days = $this->resolveDaysLeft($expiresAt, $resolvedNow);
            $milestoneDays = $this->resolveRenewNoticeMilestone($days, $renewNoticeDays);

            if ($milestoneDays === null) {
                continue;
            }

            $ruleKey = 'expiry:'.$expiresAt->format('Y-m-d').':days:'.$milestoneDays;
            if (! AutomationLog::recordOnce('billing-maintenance', 'renew_notice', 'service', (int) $service->id, $ruleKey)) {
                continue;
            }

            $displayName = $service->user?->display_name ?? '客户';
            $expiryStr = $expiresAt->format('Y-m-d H:i');

            $urgencyLine = $days === 0
                ? "您的服务 {$service->name} 今天到期，请立即续费。"
                : ($days === 1
                    ? "您的服务 {$service->name} 明天到期，请尽快完成续费。"
                    : "您的服务 {$service->name} 将在 {$days} 天后到期。");

            try {
                $this->notificationService->sendTemplateEmail($email, NotificationService::TEMPLATE_SERVICE_RENEW_REMINDER, [
                    'site_name' => $siteName,
                    'display_name' => $displayName,
                    'service_name' => (string) $service->name,
                    'days_left' => $days,
                    'expires_at' => $expiryStr,
                    'billing_cycle_label' => $this->resolveCycleLabel((string) $service->billing_cycle),
                    'urgency_message' => $urgencyLine,
                ]);
                $this->userNotificationService->create(
                    (int) $service->user_id,
                    UserNotificationType::SERVICE_RENEW_REMINDER,
                    '服务续费提醒',
                    $urgencyLine,
                    '/client/services/'.$service->id,
                    ['service_id' => (int) $service->id, 'days_left' => $days, 'expires_at' => $expiryStr]
                );
                AutomationLog::markExecuted(
                    'billing-maintenance',
                    'renew_notice',
                    'service',
                    (int) $service->id,
                    $ruleKey,
                    [
                        'email' => $email,
                        'days_left' => $days,
                        'expires_at' => $expiryStr,
                    ]
                );
                $count++;
            } catch (\Throwable $exception) {
                AutomationLog::forgetRecord('billing-maintenance', 'renew_notice', 'service', (int) $service->id, $ruleKey);
                Log::warning('[定时任务] 服务续费提醒发送失败', [
                    'service_id' => $service->id,
                    'email' => $email,
                    'message' => $exception->getMessage(),
                ]);
            }
        }

        return $count;
    }

    // ─── 即将自动续费提醒 ──────────────────────────────────────────────────────

    /**
     * 在自动续费执行前 1 天，向开启了自动续费的用户发送"即将自动续费"邮件通知。
     *
     * 通知时机 = auto_renew_days_before + 1 天，即比实际扣款日早 1 天。
     * 示例：auto_renew_days_before=3 → 到期前 4 天发送通知，到期前 3 天扣款。
     */
    private function sendAutoRenewUpcomingNotices(array $config): int
    {
        if (! $config['renew_notice_enabled']) {
            return 0;
        }

        $autoRenewDays = (int) config('idc.auto_renew_days_before', 3);
        $noticeDay = $autoRenewDays + 1; // 自动续费前 1 天通知

        $resolvedNow = now();
        $siteName = (string) config('idc.site_name', config('app.name', '服务商'));
        $targetDate = $resolvedNow->copy()->addDays($noticeDay)->toDateString();

        $services = Service::query()
            ->with('user:id,email,nickname')
            ->where('auto_renew', 1)
            ->where('status', ServiceStatus::ACTIVE)
            ->whereNotNull('expires_at')
            ->whereBetween('expires_at', [
                $targetDate.' 00:00:00',
                $targetDate.' 23:59:59',
            ])
            ->get();

        $count = 0;

        foreach ($services as $service) {
            $email = trim((string) ($service->user?->email ?? ''));
            if ($email === '') {
                continue;
            }

            $expiresAt = $service->expires_at instanceof Carbon
                ? $service->expires_at
                : Carbon::parse($service->expires_at);

            $ruleKey = 'expiry:'.$expiresAt->format('Y-m-d').':auto_renew_upcoming:'.$noticeDay;
            if (! AutomationLog::recordOnce(
                'billing-maintenance', 'auto_renew_upcoming', 'service', (int) $service->id, $ruleKey
            )) {
                continue;
            }

            $displayName = $service->user?->display_name ?? '客户';
            $expiryStr = $expiresAt->format('Y-m-d H:i');
            $autoRenewDate = $expiresAt->copy()->subDays($autoRenewDays)->format('Y-m-d');
            $cycleLabel = $this->resolveCycleLabel((string) $service->billing_cycle);

            $urgencyLine = "您的服务 {$service->name} 将于 {$autoRenewDate} 由系统自动续费（{$cycleLabel}），请确保账户余额充足。如不希望自动续费，请在 {$autoRenewDate} 前登录控制台关闭自动续费。";

            try {
                $this->notificationService->sendTemplateEmail(
                    $email,
                    NotificationService::TEMPLATE_AUTO_RENEW_UPCOMING,
                    [
                        'site_name' => $siteName,
                        'display_name' => $displayName,
                        'service_name' => (string) $service->name,
                        'expires_at' => $expiryStr,
                        'auto_renew_date' => $autoRenewDate,
                        'billing_cycle_label' => $cycleLabel,
                        'urgency_message' => $urgencyLine,
                    ]
                );
                $this->userNotificationService->create(
                    (int) $service->user_id,
                    UserNotificationType::SERVICE_AUTO_RENEW_UPCOMING,
                    '即将自动续费',
                    $urgencyLine,
                    '/client/services/'.$service->id,
                    ['service_id' => (int) $service->id, 'expires_at' => $expiryStr, 'auto_renew_date' => $autoRenewDate]
                );
                AutomationLog::markExecuted(
                    'billing-maintenance',
                    'auto_renew_upcoming',
                    'service',
                    (int) $service->id,
                    $ruleKey,
                    [
                        'email' => $email,
                        'expires_at' => $expiryStr,
                        'auto_renew_date' => $autoRenewDate,
                    ]
                );
                $count++;
            } catch (\Throwable $exception) {
                AutomationLog::forgetRecord('billing-maintenance', 'auto_renew_upcoming', 'service', (int) $service->id, $ruleKey);
                Log::warning('[定时任务] 自动续费预告发送失败', [
                    'service_id' => $service->id,
                    'email' => $email,
                    'message' => $exception->getMessage(),
                ]);
            }
        }

        return $count;
    }

    // ─── 自动生成续费账单 ──────────────────────────────────────────────────────

    private function createRenewOrders(array $config): int
    {
        $renewNoticeDays = collect((array) ($config['renew_notice_days_before'] ?? []))
            ->map(fn ($item) => (int) $item)
            ->filter(fn (int $item) => in_array($item, [7, 3, 1], true))
            ->unique()
            ->sort()
            ->values()
            ->all();

        if (! $config['renew_create_invoice_enabled'] || $renewNoticeDays === []) {
            return 0;
        }

        $resolvedNow = now();
        // 取最大提前天数作为建单窗口，窗口内若此前未建单则补建一次。
        $triggerDays = max($renewNoticeDays);

        $services = Service::query()
            ->with('user:id,email,nickname')
            ->where('status', ServiceStatus::ACTIVE)
            ->where('auto_renew', 1)
            ->whereNotNull('expires_at')
            ->whereBetween('expires_at', [
                $resolvedNow->copy()->startOfDay(),
                $resolvedNow->copy()->addDays($triggerDays)->endOfDay(),
            ])
            ->get();

        $count = 0;

        foreach ($services as $service) {
            if (! $service->user) {
                continue;
            }

            $expiresAt = $service->expires_at instanceof Carbon
                ? $service->expires_at
                : Carbon::parse($service->expires_at);

            $days = $this->resolveDaysLeft($expiresAt, $resolvedNow);
            if ($days < 0 || $days > $triggerDays) {
                continue;
            }

            $ruleKey = 'expiry:'.$expiresAt->format('Y-m-d').':auto_order:'.$triggerDays;
            if (! AutomationLog::recordOnce(
                'billing-maintenance', 'renew_order_create', 'service', (int) $service->id, $ruleKey
            )) {
                continue;
            }

            try {
                $this->serviceRenewService->createRenewInvoiceForUser(
                    $service->user, (int) $service->id, (string) $service->billing_cycle
                );
                Log::info('[定时任务] 自动生成续费订单', [
                    'service_id' => $service->id,
                    'days_left' => $days,
                ]);
                AutomationLog::markExecuted(
                    'billing-maintenance',
                    'renew_order_create',
                    'service',
                    (int) $service->id,
                    $ruleKey,
                    [
                        'days_left' => $days,
                        'expires_at' => $expiresAt->format('Y-m-d H:i:s'),
                    ]
                );
                $count++;
            } catch (\Throwable $exception) {
                AutomationLog::forgetRecord('billing-maintenance', 'renew_order_create', 'service', (int) $service->id, $ruleKey);
                Log::warning('[定时任务] 自动生成续费订单失败', [
                    'service_id' => $service->id,
                    'message' => $exception->getMessage(),
                ]);
            }
        }

        return $count;
    }

    // ─── 账单到期前提醒 ────────────────────────────────────────────────────────

    private function sendInvoiceBeforeDueReminders(array $config): int
    {
        if (! $config['invoice_unpaid_reminder_enabled'] || $config['invoice_unpaid_before_due_days'] < 0) {
            return 0;
        }

        $siteName = (string) config('idc.site_name', config('app.name', '服务商'));
        $targetDays = $config['invoice_unpaid_before_due_days'];

        $invoices = Invoice::query()
            ->with(['user:id,email,nickname', 'order.product:id,product_type,service_type_code,product_group_id,config_options,purchase_requires'])
            ->where('status', InvoiceStatus::UNPAID)
            ->whereNotNull('due_date')
            ->get();

        $count = 0;

        foreach ($invoices as $invoice) {
            $dueDate = Carbon::parse($invoice->due_date);
            $days = (int) now()->startOfDay()->diffInDays($dueDate->copy()->startOfDay(), false);

            if ($days !== $targetDays) {
                continue;
            }

            $ruleKey = 'due:'.$dueDate->format('Y-m-d').':before:'.$days;
            if (! AutomationLog::recordOnce(
                'billing-maintenance', 'invoice_before_due', 'invoice', (int) $invoice->id, $ruleKey
            )) {
                continue;
            }

            $tailText = $days === 0
                ? '账单今天到期，请立即完成付款，避免账单逾期影响您的服务使用。'
                : ($days === 1
                    ? '账单明天到期，请尽快完成付款。'
                    : "账单将在 {$days} 天后到期，请及时完成付款。");

            if ($this->sendInvoiceReminderEmail(
                $invoice,
                $siteName,
                NotificationService::TEMPLATE_INVOICE_PAYMENT_REMINDER,
                $tailText
            )) {
                AutomationLog::markExecuted(
                    'billing-maintenance',
                    'invoice_before_due',
                    'invoice',
                    (int) $invoice->id,
                    $ruleKey,
                    [
                        'days_before_due' => $days,
                        'due_date' => $dueDate->format('Y-m-d'),
                    ]
                );
                $count++;

                continue;
            }

            AutomationLog::forgetRecord('billing-maintenance', 'invoice_before_due', 'invoice', (int) $invoice->id, $ruleKey);
        }

        return $count;
    }

    // ─── 账单逾期后催付 ────────────────────────────────────────────────────────

    private function sendInvoiceOverdueReminders(array $config): int
    {
        if (! $config['invoice_unpaid_reminder_enabled'] || $config['invoice_overdue_reminder_days'] === []) {
            return 0;
        }

        $siteName = (string) config('idc.site_name', config('app.name', '服务商'));

        $invoices = Invoice::query()
            ->with(['user:id,email,nickname', 'order.product:id,product_type,service_type_code,product_group_id,config_options,purchase_requires'])
            ->whereIn('status', [InvoiceStatus::UNPAID, InvoiceStatus::OVERDUE])
            ->whereNotNull('due_date')
            ->get();

        $count = 0;

        foreach ($invoices as $invoice) {
            $dueDate = Carbon::parse($invoice->due_date);
            $overdue = (int) $dueDate->copy()->startOfDay()->diffInDays(now()->startOfDay(), false);

            if ($overdue <= 0 || ! in_array($overdue, $config['invoice_overdue_reminder_days'], true)) {
                continue;
            }

            $ruleKey = 'due:'.$dueDate->format('Y-m-d').':overdue:'.$overdue;
            if (! AutomationLog::recordOnce(
                'billing-maintenance', 'invoice_overdue', 'invoice', (int) $invoice->id, $ruleKey
            )) {
                continue;
            }

            $tailText = "账单已逾期 {$overdue} 天，请尽快完成付款，以避免影响您的服务使用。若继续未付，我们将按相关条款处理。";

            if ($this->sendInvoiceReminderEmail(
                $invoice,
                $siteName,
                NotificationService::TEMPLATE_INVOICE_OVERDUE_REMINDER,
                $tailText
            )) {
                AutomationLog::markExecuted(
                    'billing-maintenance',
                    'invoice_overdue',
                    'invoice',
                    (int) $invoice->id,
                    $ruleKey,
                    [
                        'overdue_days' => $overdue,
                        'due_date' => $dueDate->format('Y-m-d'),
                    ]
                );
                $count++;

                continue;
            }

            AutomationLog::forgetRecord('billing-maintenance', 'invoice_overdue', 'invoice', (int) $invoice->id, $ruleKey);
        }

        return $count;
    }

    // ─── 未付款账单标为逾期 ───────────────────────────────────────────────────

    private function markInvoicesOverdue(array $config): int
    {
        $graceDays = $config['invoice_overdue_after_days'];

        // due_date 当天不算逾期，需要严格晚于 due_date（加上宽限天数）才标记
        $threshold = now()->subDays($graceDays)->toDateString();

        return Invoice::query()
            ->where('status', InvoiceStatus::UNPAID)
            ->whereNotNull('due_date')
            ->where('due_date', '<', $threshold)   // 严格小于，避免到期当天被误标
            ->update(['status' => InvoiceStatus::OVERDUE]);
    }

    // ─── 公共邮件发送 ──────────────────────────────────────────────────────────

    private function sendInvoiceReminderEmail(Invoice $invoice, string $siteName, string $templateCode, string $tailText): bool
    {
        $email = trim((string) ($invoice->user?->email ?? ''));
        if ($email === '') {
            return false;
        }

        $displayName = $invoice->user?->display_name ?? '客户';
        $dueDate = Carbon::parse($invoice->due_date)->format('Y-m-d');

        try {
            $this->notificationService->sendTemplateEmail($email, $templateCode, [
                'site_name' => $siteName,
                'display_name' => $displayName,
                'invoice_no' => (string) $invoice->invoice_no,
                'order_no' => (string) ($invoice->order?->order_no ?? ''),
                'product_name' => (string) ($invoice->order?->display_product_name ?? ''),
                'amount' => number_format((float) $invoice->amount, 2, '.', ''),
                'due_date' => $dueDate,
                'notice_message' => $tailText,
            ]);

            $userId = (int) ($invoice->user_id ?? $invoice->user?->id ?? 0);
            if ($userId > 0) {
                $isOverdue = $templateCode === NotificationService::TEMPLATE_INVOICE_OVERDUE_REMINDER;
                $this->userNotificationService->create(
                    $userId,
                    $isOverdue ? UserNotificationType::INVOICE_OVERDUE_REMINDER : UserNotificationType::INVOICE_PAYMENT_REMINDER,
                    $isOverdue ? '账单逾期提醒' : '账单待支付提醒',
                    $tailText,
                    '/client/invoices/'.$invoice->id,
                    ['invoice_id' => (int) $invoice->id, 'amount' => (float) $invoice->amount, 'due_date' => $dueDate]
                );
            }

            return true;
        } catch (\Throwable $exception) {
            Log::warning('[定时任务] 账单提醒邮件发送失败', [
                'invoice_id' => $invoice->id,
                'email' => $email,
                'message' => $exception->getMessage(),
            ]);

            return false;
        }
    }

    private function resolveCycleLabel(string $cycle): string
    {
        return [
            'monthly' => '月付',
            'quarterly' => '季付',
            'semiannually' => '半年付',
            'annually' => '年付',
            'biennially' => '两年付',
            'triennially' => '三年付',
            'one_time' => '一次性',
            'onetime' => '一次性',
        ][$cycle] ?? $cycle;
    }

    private function resolveDaysLeft(Carbon $expiresAt, ?Carbon $resolvedNow = null): int
    {
        $now = $resolvedNow?->copy() ?? now();

        return (int) $now->startOfDay()->diffInDays($expiresAt->copy()->startOfDay(), false);
    }

    private function resolveRenewNoticeMilestone(int $daysLeft, array $renewNoticeDays): ?int
    {
        if ($daysLeft < 0) {
            return null;
        }

        foreach ($renewNoticeDays as $milestoneDays) {
            if ($daysLeft <= (int) $milestoneDays) {
                return (int) $milestoneDays;
            }
        }

        return null;
    }

    /**
     * 续费履约补偿恢复
     *
     * 扫描已支付但 fulfillment_pending 未清除的续费账单，
     * 重新触发续费履约流程以修复支付事务提交后 handlePaidInvoice 未完成导致的缺口。
     */
    private function recoverStuckFulfillments(): int
    {
        $stuckInvoices = Invoice::query()
            ->with(['service.product.supplier'])
            ->where('status', InvoiceStatus::PAID)
            ->where('type', OrderType::RENEW)
            ->whereNotNull('config_snapshot')
            ->get()
            ->filter(function (Invoice $invoice): bool {
                $config = is_array($invoice->config_snapshot ?? null) ? $invoice->config_snapshot : [];

                return ! empty($config['fulfillment_pending']);
            });

        $count = 0;

        foreach ($stuckInvoices as $invoice) {
            try {
                // 检查是否已被其他进程处理
                $config = is_array($invoice->config_snapshot ?? null) ? $invoice->config_snapshot : [];
                if (empty($config['fulfillment_pending'])) {
                    continue;
                }

                // 检查服务是否已续费
                $service = $invoice->service;
                if ($service) {
                    $renewProvisionData = is_array($service->provision_data ?? null) ? $service->provision_data : [];
                    if ((int) ($renewProvisionData['last_renew_invoice_id'] ?? 0) === (int) $invoice->id) {
                        // 实际已履约完成，仅需清除标记
                        $config['fulfillment_pending'] = false;
                        $config['fulfillment_cleared_at'] = now()->toDateTimeString();
                        $invoice->forceFill(['config_snapshot' => $config])->saveQuietly();
                        $count++;

                        continue;
                    }
                }

                // 重新触发续费履约；只有已实际完成时才能清除 pending。
                $service = $this->serviceRenewService->processPaidRenewInvoice($invoice);
                if (! $this->serviceRenewService->isRenewInvoiceFulfilled($invoice, $service)) {
                    Log::warning('[定时任务] 续费履约补偿尚未完成，保留 pending 等待后续重试', [
                        'invoice_id' => $invoice->id,
                        'invoice_no' => $invoice->invoice_no ?? '',
                    ]);

                    continue;
                }

                $config['fulfillment_pending'] = false;
                $config['fulfillment_cleared_at'] = now()->toDateTimeString();
                $invoice->forceFill(['config_snapshot' => $config])->saveQuietly();

                Log::info('[定时任务] 续费履约补偿恢复成功', [
                    'invoice_id' => $invoice->id,
                    'invoice_no' => $invoice->invoice_no ?? '',
                ]);
                $count++;
            } catch (\Throwable $exception) {
                Log::warning('[定时任务] 续费履约补偿恢复失败', [
                    'invoice_id' => $invoice->id,
                    'invoice_no' => $invoice->invoice_no ?? '',
                    'message' => $exception->getMessage(),
                ]);
            }
        }

        return $count;
    }
}
