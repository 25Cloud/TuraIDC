<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Constants\InvoiceStatus;
use App\Constants\ServiceStatus;
use App\Models\Invoice;
use App\Models\Product;
use App\Models\Service;
use App\Models\User;
use App\Services\Automation\ServiceStatusSyncService;
use App\Services\ClientServiceConsole\ClientServiceConsoleService;
use App\Services\Finance\FinanceLedgerQueryService;
use App\Services\Finance\InvoiceService;
use App\Services\Finance\PaymentService;
use App\Services\Provisioning\ProvisionService;
use App\Services\Referral\ReferralService;
use App\Services\System\NotificationService;
use App\Services\System\OperationLogService;
use App\Services\System\SettingService;
use App\Services\User\UserService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class AdminUserManualProvisionInvoiceOnlyTest extends TestCase
{
    use DatabaseTransactions;

    private function mirrorUserToIdc(User $user, string $suffix): void
    {
        $payload = [
            'email' => $user->email,
            'phone' => $user->phone,
            'password' => Hash::make('Temp@123456'),
            'nickname' => (string) $user->nickname,
            'company' => '',
            'qq' => '',
            'alipay_real_name' => '',
            'alipay_account' => '',
            'status' => 1,
            'referral_code' => 'M'.strtoupper(substr(md5($suffix.'-'.$user->id), 0, 8)),
            'referrer_user_id' => null,
            'member_level_id' => null,
            'total_sales_amount' => '0.00',
            'login_email_alert' => 1,
            'login_notify' => 1,
            'login_location_alert' => 1,
            'password_change_alert' => 1,
            'phone_change_alert' => 1,
            'email_change_alert' => 1,
            'marketing_alert' => 0,
            'is_verified' => 0,
            'real_name' => '',
            'id_card' => '',
            'verification_status' => 0,
            'verification_message' => '',
            'verification_certify_id' => null,
            'referred_at' => null,
            'verified_at' => null,
            'last_login_ip' => null,
            'last_login_at' => null,
            'admin_note' => null,
            'deleted_at' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ];

        DB::connection()->table('users')->updateOrInsert(['id' => (int) $user->id], $payload);
    }

    private function mirrorProductToIdc(Product $product, string $suffix): void
    {
        DB::connection()->table('products')->updateOrInsert(
            ['id' => (int) $product->id],
            Product::buildIdcMirrorPayload($product, 'manual-provision-'.$suffix.'-'.(int) $product->id)
        );
    }

    public function test_manual_provision_service_uses_invoice_retry_when_order_is_missing(): void
    {
        $suffix = bin2hex(random_bytes(4));

        $user = User::query()->forceCreate([
            'email' => 'manual-provision-invoice-only-'.$suffix.'@example.com',
            'password' => 'Temp@123456',
            'phone' => '13'.str_pad((string) random_int(0, 999999999), 9, '0', STR_PAD_LEFT),
            'status' => 1,
            'nickname' => 'Manual Provision Invoice Only',
            'real_name' => '',
            'id_card' => '',
            'verification_status' => 0,
            'verification_message' => '',
            'verification_certify_id' => null,
            'member_level_id' => null,
            'total_sales_amount' => '0.00',
            'referrer_user_id' => null,
            'verified_at' => null,
        ]);
        $this->mirrorUserToIdc($user, 'manual-provision-'.$suffix);

        $product = Product::query()->create([
            'name' => 'Manual Provision Invoice Only Product '.$suffix,
            'product_type' => 'vps',
            'pricing' => ['monthly' => '88.00'],
            'setup_fee' => '0.00',
            'config_options' => [],
            'purchase_requires' => [],
            'stock' => -1,
            'status' => 1,
            'auto_setup' => 1,
        ]);
        $this->mirrorProductToIdc($product, $suffix);

        $service = Service::query()->create([
            'user_id' => (int) $user->id,
            'product_id' => (int) $product->id,
            'service_no' => 'MANUAL-'.$suffix,
            'name' => 'Manual Provision Invoice Only Service '.$suffix,
            'domain' => 'manual-provision-'.$suffix.'.example.com',
            'billing_cycle' => 'monthly',
            'amount' => '88.00',
            'status' => ServiceStatus::PENDING,
            'locked_pricing' => [],
            'provision_data' => [
                'source_invoice_id' => null,
                'provision_error' => '上游接口超时',
            ],
            'expires_at' => now()->addMonth(),
            'auto_renew' => 0,
        ]);

        $invoice = Invoice::query()->create([
            'invoice_no' => 'MANUALPROVINV'.strtoupper($suffix),
            'user_id' => (int) $user->id,
            'product_id' => (int) $product->id,
            'service_id' => (int) $service->id,
            'type' => 'normal',
            'amount' => '88.00',
            'paid_amount' => '88.00',
            'status' => InvoiceStatus::PAID,
            'billing_cycle' => 'monthly',
            'config_snapshot' => ['hostname' => 'manual-'.$suffix],
            'config_pricing_snapshot' => [],
            'due_date' => now()->addDay(),
            'paid_at' => now(),
        ]);

        $service->forceFill([
            'invoice_id' => (int) $invoice->id,
            'provision_data' => array_merge((array) $service->provision_data, [
                'source_invoice_id' => (int) $invoice->id,
            ]),
        ])->save();

        $provisionService = $this->createMock(ProvisionService::class);
        $provisionService->expects($this->never())
            ->method('retryFailedProvision');
        $provisionService->expects($this->once())
            ->method('retryFailedProvisionByInvoice')
            ->with($this->callback(fn (Invoice $candidate): bool => (int) $candidate->id === (int) $invoice->id))
            ->willReturn($service);

        $clientServiceConsoleService = $this->createMock(ClientServiceConsoleService::class);
        $clientServiceConsoleService->expects($this->once())
            ->method('getDetailForUser')
            ->with($this->callback(fn (User $candidate): bool => (int) $candidate->id === (int) $user->id), (int) $service->id, true)
            ->willReturn([
                'id' => (int) $service->id,
                'service_id' => (int) $service->id,
            ]);

        $operationLogService = $this->createMock(OperationLogService::class);
        $operationLogService->expects($this->once())
            ->method('writeServiceConsoleLog');

        $userService = new UserService(
            $clientServiceConsoleService,
            $this->createMock(ReferralService::class),
            $this->createMock(InvoiceService::class),
            $this->createMock(PaymentService::class),
            $this->createMock(FinanceLedgerQueryService::class),
            $this->createMock(NotificationService::class),
            $operationLogService,
            $provisionService,
            $this->createMock(ServiceStatusSyncService::class),
            $this->createMock(SettingService::class),
        );

        $result = $userService->manualProvisionService($user, (int) $service->id, [
            'remark' => '重新提交上游开通',
        ], [
            'operator_id' => 9001,
            'operator_name' => 'Admin Reviewer',
            'trace_id' => 'manual-provision-invoice-only-test',
            'ip_address' => '127.0.0.1',
        ]);

        $this->assertSame((int) $service->id, (int) ($result['service_id'] ?? 0));
    }
}
