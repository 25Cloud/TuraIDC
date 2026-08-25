<?php

declare(strict_types=1);

namespace App\Services\Supplier;

use App\Models\AdminUser;
use App\Models\Supplier;
use App\Models\SupplierBalance;
use App\Services\System\NotificationService;
use App\Support\AdminPermissions;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

/**
 * 上游余额相关的管理员邮件通知。
 *
 * 走既有的通知模板体系（notification_templates + NotificationService::sendTemplateEmail），
 * 与管理员订单通知使用同一套模板格式与变量占位符，管理端「通知模板」页面可直接
 * 编辑内容、开关启用、发送测试邮件。
 */
class AdminSupplierBalanceNotifier
{
    public function __construct(
        private readonly NotificationService $notificationService,
    ) {}

    /**
     * 余额跌破阈值提醒。
     *
     * $previousBalance 传 decimal 字符串而非 float：金额从取值到展示全程不进浮点路径。
     */
    public function notifyLowBalance(Supplier $supplier, SupplierBalance $record, ?string $previousBalance): void
    {
        $this->sendToAdmins(
            NotificationService::TEMPLATE_ADMIN_SUPPLIER_LOW_BALANCE,
            '上游余额不足提醒',
            [
                'supplier_name' => (string) $supplier->name,
                'provider_key' => (string) ($record->provider_key ?? '-'),
                'balance' => $this->money($record->balance),
                'previous_balance' => $previousBalance === null ? '-' : $this->money($previousBalance),
                'threshold' => $this->money($record->low_balance_threshold),
                'currency' => (string) ($record->currency ?? '-'),
                'synced_at' => optional($record->last_synced_at)->format('Y-m-d H:i:s') ?? '-',
            ],
            ['supplier_id' => (int) $supplier->id]
        );
    }

    /**
     * 上游开通因余额不足失败的提醒。
     *
     * @param  array<string, mixed>  $context
     */
    public function notifyProvisionFailed(Supplier $supplier, SupplierBalance $record, array $context): void
    {
        $this->sendToAdmins(
            NotificationService::TEMPLATE_ADMIN_SUPPLIER_PROVISION_FAILED,
            '上游余额不足导致开通失败',
            [
                'supplier_name' => (string) $supplier->name,
                'balance' => $record->balance === null ? '-' : $this->money($record->balance),
                'threshold' => $this->money($record->low_balance_threshold),
                'order_no' => (string) ($context['order_no'] ?? '-'),
                'product_name' => (string) ($context['product_name'] ?? '-'),
                'user_name' => (string) ($context['user_name'] ?? '-'),
                'error_message' => (string) ($context['error_message'] ?? '-'),
                'failed_at' => now()->format('Y-m-d H:i:s'),
            ],
            ['supplier_id' => (int) $supplier->id, 'order_no' => (string) ($context['order_no'] ?? '')]
        );
    }

    /**
     * @param  array<string, mixed>  $params
     * @param  array<string, mixed>  $logContext
     */
    private function sendToAdmins(string $templateCode, string $scene, array $params, array $logContext): void
    {
        $recipients = $this->resolveRecipients();
        if ($recipients->isEmpty()) {
            Log::warning('[上游余额通知] 没有可接收的管理员，本次提醒未发出', $logContext + ['scene' => $scene]);

            return;
        }

        $attempted = 0;
        $failed = 0;

        foreach ($recipients as $admin) {
            $attempted++;

            try {
                $this->notificationService->sendTemplateEmail(
                    (string) $admin->email,
                    $templateCode,
                    $params + ['recipient_name' => $this->recipientName($admin)]
                );
            } catch (\Throwable $exception) {
                $failed++;

                Log::warning('[上游余额通知] 邮件发送失败', $logContext + [
                    'scene' => $scene,
                    'admin_id' => (int) $admin->id,
                    'email' => (string) $admin->email,
                    'template_code' => $templateCode,
                    'message' => $exception->getMessage(),
                ]);
            }
        }

        // 与管理员订单通知保持一致的口径：整批失败说明发信通道整体不可用，
        // 升级日志级别但不外抛——本通知挂在定时任务与开通流程上，外抛会连带
        // 让同步任务或开通流程失败，代价远大于一封提醒邮件。
        if ($attempted > 0 && $failed === $attempted) {
            Log::error('[上游余额通知] 全部收件人发送失败，本次提醒已丢失', $logContext + [
                'scene' => $scene,
                'template_code' => $templateCode,
                'attempted' => $attempted,
            ]);
        }
    }

    /**
     * @return Collection<int, AdminUser>
     */
    private function resolveRecipients(): Collection
    {
        return AdminUser::query()
            ->with('role')
            ->where('status', 1)
            ->whereNotNull('email')
            ->orderBy('id')
            ->get(['id', 'username', 'nickname', 'email', 'role_id'])
            ->filter(function (AdminUser $admin): bool {
                $email = trim((string) ($admin->email ?? ''));

                return $email !== ''
                    && (
                        $admin->hasPermission(AdminPermissions::SUPPLIER_MANAGE)
                        || $admin->hasPermission(AdminPermissions::SUPPLIER_DETAIL)
                        || $admin->hasPermission(AdminPermissions::SUPPLIER_LIST)
                    );
            })
            ->unique(fn (AdminUser $admin) => mb_strtolower(trim((string) $admin->email)))
            ->values();
    }

    private function recipientName(AdminUser $admin): string
    {
        $nickname = trim((string) ($admin->nickname ?? ''));

        return $nickname !== '' ? $nickname : (string) $admin->username;
    }

    /**
     * 金额格式化成两位小数字符串。
     *
     * 走整数分往返而不是 number_format((float) $value)：邮件里的余额、阈值都是要拿去
     * 跟人对账的数字，(float) 转换在极端值上会改变末位，展示与库里的值就不一致了。
     */
    private function money(mixed $value): string
    {
        return SupplierBalance::centsToDecimal(SupplierBalance::toCents($value));
    }
}
