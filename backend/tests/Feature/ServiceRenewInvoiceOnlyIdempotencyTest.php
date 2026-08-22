<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Constants\InvoiceStatus;
use App\Constants\OrderStatus;
use App\Constants\ServiceStatus;
use App\Exceptions\BusinessException;
use App\Models\Invoice;
use App\Models\Order;
use App\Models\Product;
use App\Models\Service;
use App\Models\Supplier;
use App\Models\User;
use App\Services\Finance\CouponService;
use App\Services\Finance\InvoiceService;
use App\Services\Integrations\Plugins\PluginBindingResolver;
use App\Services\Provisioning\ServiceRenewService;
use App\Services\System\NotificationService;
use App\Services\System\OperationLogService;
use App\Services\System\SettingService;
use App\Services\Upstream\Contracts\ProvidesRenewal;
use App\Services\Upstream\Contracts\UpstreamDriver;
use App\Services\Upstream\Drivers\HostingPanelApi\HostingPanelApiDriver;
use App\Services\Upstream\Drivers\HostingPanelApi\HostingPanelApiTransport;
use App\Services\Upstream\ProviderRegistry;
use App\Services\Upstream\ProviderResolver;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ServiceRenewInvoiceOnlyIdempotencyTest extends TestCase
{
    private function mirrorProductToIdc(Product $product, string $suffix): void
    {
        DB::connection()->table('products')->updateOrInsert(
            ['id' => (int) $product->id],
            Product::buildIdcMirrorPayload($product, 'renew-idempotency-'.$suffix.'-'.(int) $product->id)
        );
    }

    private function mirrorUserToIdc(User $user, string $suffix): void
    {
        $payload = [
            'email' => $user->email,
            'phone' => $user->phone,
            'password' => Hash::make('Temp@123456'),
            'status' => 1,
            'referral_code' => 'R'.strtoupper(substr(md5($suffix.'-'.$user->id), 0, 8)),
            'referrer_user_id' => null,
            'member_level_id' => null,
            'login_email_alert' => 1,
            'login_notify' => 1,
            'login_location_alert' => 1,
            'password_change_alert' => 1,
            'phone_change_alert' => 1,
            'email_change_alert' => 1,
            'marketing_alert' => 0,
            'is_verified' => 0,
            'verification_status' => 0,
            'verification_message' => '',
            'verification_certify_id' => null,
            'referred_at' => null,
            'verified_at' => null,
            'last_login_ip' => null,
            'last_login_at' => null,
            'deleted_at' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ];

        DB::connection()->table('users')->updateOrInsert(['id' => (int) $user->id], $payload);
        DB::connection()->table('users')->updateOrInsert(['id' => (int) $user->id], $payload);
    }

    /**
     * @return array{service: Service, invoice: Invoice}
     */
    private function createUpstreamRenewFixture(string $suffix): array
    {
        $user = User::query()->create([
            'email' => 'renew-upstream-'.$suffix.'@example.com',
            'password' => 'Temp@123456',
            'phone' => '13'.str_pad((string) random_int(0, 999999999), 9, '0', STR_PAD_LEFT),
            'status' => 1,
            'nickname' => 'Renew Upstream',
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
        $this->mirrorUserToIdc($user, 'renew-upstream-'.$suffix);

        $product = Product::query()->create([
            'name' => 'Renew Upstream Product '.$suffix,
            'product_type' => 'vps',
            'pricing' => ['monthly' => '48.00'],
            'setup_fee' => '0.00',
            'config_options' => [],
            'purchase_requires' => [],
            'stock' => -1,
            'status' => 1,
            'auto_setup' => 0,
        ]);
        $this->mirrorProductToIdc($product, $suffix);

        $service = Service::query()->create([
            'user_id' => (int) $user->id,
            'product_id' => (int) $product->id,
            'name' => 'Renew Upstream Service '.$suffix,
            'domain' => 'renew-upstream-'.$suffix.'.example.com',
            'billing_cycle' => 'monthly',
            'amount' => '48.00',
            'status' => ServiceStatus::ACTIVE,
            'locked_pricing' => ['monthly' => '48.00'],
            'provision_data' => [],
            'expires_at' => Carbon::parse('2026-06-20 00:00:00'),
            'auto_renew' => 0,
        ]);

        $invoice = Invoice::query()->create([
            'invoice_no' => 'INVRENEWUPSTREAM'.strtoupper($suffix),
            'user_id' => (int) $user->id,
            'product_id' => (int) $product->id,
            'service_id' => (int) $service->id,
            'type' => 'renew',
            'amount' => '48.00',
            'paid_amount' => '48.00',
            'billing_cycle' => 'monthly',
            'status' => InvoiceStatus::PAID,
            'paid_at' => now(),
            'due_date' => now()->addDay(),
        ]);

        return compact('service', 'invoice');
    }

    private function makeUpstreamRenewService(FakeInvoiceRenewalCapability $capability): ServiceRenewService
    {
        $supplier = new Supplier(['name' => 'Fake renewal supplier']);
        $bindingResolver = new FakeInvoiceRenewalBindingResolver($supplier);

        return new ServiceRenewService(
            new InvoiceService,
            new ProviderResolver(
                new ProviderRegistry([
                    new FakeInvoiceRenewalDriver($capability),
                ]),
                $bindingResolver,
            ),
            $this->createMock(CouponService::class),
            $this->createMock(OperationLogService::class),
            new class extends SettingService
            {
                public function getAutomationConfig(): array
                {
                    return array_merge(parent::defaultAutomationConfig(), [
                        'expire_unsuspend_notify_enabled' => false,
                    ]);
                }
            },
            $this->createMock(NotificationService::class),
            $bindingResolver,
        );
    }

    #[Test]
    public function process_paid_renew_invoice_recovers_the_current_upstream_invoice_without_creating_another_one(): void
    {
        $suffix = bin2hex(random_bytes(4));
        ['service' => $service, 'invoice' => $invoice] = $this->createUpstreamRenewFixture($suffix);
        $capability = new FakeInvoiceRenewalCapability;

        $service->forceFill([
            'provision_data' => [
                'renew_invoice_id' => (int) $invoice->id,
                'upstream_invoice_id' => 78123,
                'renew_recovery_context' => ['payment' => 'credit'],
            ],
        ])->save();

        $result = $this->makeUpstreamRenewService($capability)->processPaidRenewInvoice($invoice);

        $this->assertNotNull($result);
        $this->assertSame(0, $capability->renewServiceInvoiceCalls);
        $this->assertSame(1, $capability->recoverCalls);
        $this->assertSame(78123, $capability->lastRecoveredUpstreamInvoiceId);
        $this->assertSame(['payment' => 'credit'], $capability->lastRecoveryContext);
        $this->assertSame(78123, (int) (($result->provision_data ?? [])['upstream_invoice_id'] ?? 0));
        $this->assertSame('上游续费账单仍未支付完成', (string) (($result->provision_data ?? [])['renew_error'] ?? ''));
    }

    #[Test]
    public function process_paid_renew_invoice_aborts_when_inflight_submitted_without_upstream_id(): void
    {
        $suffix = bin2hex(random_bytes(4));
        ['service' => $service, 'invoice' => $invoice] = $this->createUpstreamRenewFixture($suffix);
        $capability = new FakeInvoiceRenewalCapability;

        // 模拟崩溃窗口：上次尝试已确认上游创建账单、但账单号未落库（renew_inflight.status=submitted）。
        $service->forceFill([
            'provision_data' => [
                'renew_inflight' => [
                    'invoice_id' => (int) $invoice->id,
                    'billing_cycle' => 'monthly',
                    'status' => 'submitted',
                    'upstream_invoice_id' => 0,
                ],
            ],
        ])->save();

        $result = $this->makeUpstreamRenewService($capability)->processPaidRenewInvoice($invoice);

        $this->assertNotNull($result);
        // 不得重复提交 /host/renew，避免上游二次创建续费账单、二次扣供应商余额。
        $this->assertSame(0, $capability->renewServiceInvoiceCalls);
        $this->assertSame(0, $capability->recoverCalls);
        $this->assertSame('failed', (string) (($result->provision_data ?? [])['renew_fulfillment_status'] ?? ''));
        $this->assertStringContainsString('人工核实', (string) (($result->provision_data ?? [])['renew_error'] ?? ''));
    }

    #[Test]
    public function process_paid_renew_invoice_aborts_when_inflight_pending_submit(): void
    {
        $suffix = bin2hex(random_bytes(4));
        ['service' => $service, 'invoice' => $invoice] = $this->createUpstreamRenewFixture($suffix);
        $capability = new FakeInvoiceRenewalCapability;

        // 模拟崩溃窗口：调用上游前落库的 pending_submit。进程中断后无法区分
        // 「上游调用前崩溃」与「上游已受理、结果未落库」，保守中止自动重试。
        $service->forceFill([
            'provision_data' => [
                'renew_inflight' => [
                    'invoice_id' => (int) $invoice->id,
                    'billing_cycle' => 'monthly',
                    'status' => 'pending_submit',
                    'upstream_invoice_id' => 0,
                ],
            ],
        ])->save();

        $result = $this->makeUpstreamRenewService($capability)->processPaidRenewInvoice($invoice);

        $this->assertNotNull($result);
        // 不得重复提交 /host/renew：pending_submit 可能已被上游受理，重放会二次扣供应商余额。
        $this->assertSame(0, $capability->renewServiceInvoiceCalls);
        $this->assertSame(0, $capability->recoverCalls);
        $this->assertSame('failed', (string) (($result->provision_data ?? [])['renew_fulfillment_status'] ?? ''));
        $this->assertStringContainsString('人工核实', (string) (($result->provision_data ?? [])['renew_error'] ?? ''));
    }

    #[Test]
    public function process_paid_renew_invoice_writes_back_known_inflight_upstream_id_and_recovers(): void
    {
        $suffix = bin2hex(random_bytes(4));
        ['service' => $service, 'invoice' => $invoice] = $this->createUpstreamRenewFixture($suffix);
        $capability = new FakeInvoiceRenewalCapability;

        // 崩溃窗口内上游账单 id 已由 inflight 持久化但未落库到 upstream_invoice_id：
        // 写回并走恢复路径，不重复提交 /host/renew。
        $service->forceFill([
            'provision_data' => [
                'renew_inflight' => [
                    'invoice_id' => (int) $invoice->id,
                    'billing_cycle' => 'monthly',
                    'status' => 'submitted',
                    'upstream_invoice_id' => 78125,
                ],
            ],
        ])->save();

        $result = $this->makeUpstreamRenewService($capability)->processPaidRenewInvoice($invoice);

        $this->assertNotNull($result);
        $this->assertSame(0, $capability->renewServiceInvoiceCalls);
        $this->assertSame(1, $capability->recoverCalls);
        $this->assertSame(78125, $capability->lastRecoveredUpstreamInvoiceId);
        $this->assertSame(78125, (int) (($result->provision_data ?? [])['upstream_invoice_id'] ?? 0));
        $this->assertSame('上游续费账单仍未支付完成', (string) (($result->provision_data ?? [])['renew_error'] ?? ''));
    }

    #[Test]
    public function process_paid_renew_invoice_does_not_recover_an_upstream_invoice_owned_by_an_older_local_invoice(): void
    {
        $suffix = bin2hex(random_bytes(4));
        ['service' => $service, 'invoice' => $olderInvoice] = $this->createUpstreamRenewFixture($suffix);
        $currentInvoice = Invoice::query()->create([
            'invoice_no' => 'INVRENEWCURRENT'.strtoupper($suffix),
            'user_id' => (int) $olderInvoice->user_id,
            'product_id' => (int) $olderInvoice->product_id,
            'service_id' => (int) $service->id,
            'type' => 'renew',
            'amount' => '48.00',
            'paid_amount' => '48.00',
            'billing_cycle' => 'monthly',
            'status' => InvoiceStatus::PAID,
            'paid_at' => now(),
            'due_date' => now()->addDay(),
        ]);
        $capability = new FakeInvoiceRenewalCapability(throwOnRenew: true);

        $service->forceFill([
            'provision_data' => [
                'renew_invoice_id' => (int) $olderInvoice->id,
                'upstream_invoice_id' => 78124,
            ],
        ])->save();

        $result = $this->makeUpstreamRenewService($capability)->processPaidRenewInvoice($currentInvoice);

        $this->assertNotNull($result);
        $this->assertSame(1, $capability->renewServiceInvoiceCalls);
        $this->assertSame(0, $capability->recoverCalls);
        $this->assertNull(($result->provision_data ?? [])['upstream_invoice_id'] ?? null);
        $this->assertSame((int) $currentInvoice->id, (int) (($result->provision_data ?? [])['renew_invoice_id'] ?? 0));
    }

    #[Test]
    public function process_paid_renew_invoice_skips_duplicate_completion_for_same_invoice(): void
    {
        $suffix = bin2hex(random_bytes(4));

        $user = User::query()->create([
            'email' => 'renew-idempotency-'.$suffix.'@example.com',
            'password' => 'Temp@123456',
            'phone' => '13'.str_pad((string) random_int(0, 999999999), 9, '0', STR_PAD_LEFT),
            'status' => 1,
            'nickname' => 'Renew Idempotency',
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
        $this->mirrorUserToIdc($user, 'renew-idempotency-'.$suffix);

        $product = Product::query()->create([
            'name' => 'Renew Idempotency Product '.$suffix,
            'product_type' => 'vps',
            'pricing' => ['monthly' => '30.00'],
            'setup_fee' => '0.00',
            'config_options' => [],
            'purchase_requires' => [],
            'stock' => -1,
            'status' => 1,
            'auto_setup' => 0,
        ]);
        $this->mirrorProductToIdc($product, $suffix);

        $originalExpiresAt = Carbon::parse('2026-06-01 00:00:00');

        $service = Service::query()->create([
            'user_id' => (int) $user->id,
            'product_id' => (int) $product->id,
            'name' => 'Invoice Only Renew Service '.$suffix,
            'domain' => 'renew-'.$suffix.'.example.com',
            'billing_cycle' => 'monthly',
            'amount' => '30.00',
            'status' => ServiceStatus::ACTIVE,
            'locked_pricing' => ['monthly' => '30.00'],
            'provision_data' => [],
            'expires_at' => $originalExpiresAt,
            'auto_renew' => 0,
        ]);

        $invoice = Invoice::query()->create([
            'invoice_no' => 'INVRENEWDONE'.strtoupper($suffix),
            'user_id' => (int) $user->id,
            'product_id' => (int) $product->id,
            'service_id' => (int) $service->id,
            'type' => 'renew',
            'amount' => '30.00',
            'paid_amount' => '30.00',
            'billing_cycle' => 'monthly',
            'status' => InvoiceStatus::PAID,
            'paid_at' => now(),
            'due_date' => now()->addDay(),
        ]);

        $service->forceFill([
            'provision_data' => [
                'last_renew_invoice_id' => (int) $invoice->id,
                'last_renew_invoice_no' => (string) $invoice->invoice_no,
                'last_renewed_at' => '2026-05-01 00:00:00',
                'last_renew_billing_cycle' => 'monthly',
                'last_renew_source' => 'local_invoice',
            ],
        ])->save();

        $renewService = new ServiceRenewService(
            new InvoiceService,
            new ProviderResolver(
                new ProviderRegistry([
                    new HostingPanelApiDriver($this->createMock(HostingPanelApiTransport::class)),
                ])
            ),
            $this->createMock(CouponService::class),
            $this->createMock(OperationLogService::class),
            new class extends SettingService
            {
                public function getAutomationConfig(): array
                {
                    return array_merge(parent::defaultAutomationConfig(), [
                        'expire_unsuspend_notify_enabled' => false,
                    ]);
                }
            },
            $this->createMock(NotificationService::class),
        );

        $result = $renewService->processPaidRenewInvoice($invoice);

        $this->assertNotNull($result);
        $this->assertSame((int) $service->id, (int) $result->id);
        $this->assertSame(
            $originalExpiresAt->format('Y-m-d H:i:s'),
            optional($result->expires_at)->format('Y-m-d H:i:s')
        );
        $this->assertSame((int) $invoice->id, (int) (($result->provision_data ?? [])['last_renew_invoice_id'] ?? 0));
        $this->assertSame(
            $originalExpiresAt->format('Y-m-d H:i:s'),
            optional($service->fresh()?->expires_at)->format('Y-m-d H:i:s')
        );
    }

    #[Test]
    public function process_paid_renew_invoice_sets_service_invoice_id_after_local_success(): void
    {
        $suffix = bin2hex(random_bytes(4));

        $user = User::query()->create([
            'email' => 'renew-bind-'.$suffix.'@example.com',
            'password' => 'Temp@123456',
            'phone' => '13'.str_pad((string) random_int(0, 999999999), 9, '0', STR_PAD_LEFT),
            'status' => 1,
            'nickname' => 'Renew Bind',
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
        $this->mirrorUserToIdc($user, 'renew-bind-'.$suffix);

        $product = Product::query()->create([
            'name' => 'Renew Bind Product '.$suffix,
            'product_type' => 'vps',
            'pricing' => ['monthly' => '45.00'],
            'setup_fee' => '0.00',
            'config_options' => [],
            'purchase_requires' => [],
            'stock' => -1,
            'status' => 1,
            'auto_setup' => 0,
        ]);
        $this->mirrorProductToIdc($product, $suffix);

        $service = Service::query()->create([
            'user_id' => (int) $user->id,
            'product_id' => (int) $product->id,
            'name' => 'Renew Bind Service '.$suffix,
            'domain' => 'bind-'.$suffix.'.example.com',
            'billing_cycle' => 'monthly',
            'amount' => '45.00',
            'status' => ServiceStatus::ACTIVE,
            'locked_pricing' => ['monthly' => '45.00'],
            'provision_data' => [],
            'expires_at' => Carbon::parse('2026-06-15 00:00:00'),
            'auto_renew' => 0,
        ]);

        $invoice = Invoice::query()->create([
            'invoice_no' => 'INVRENEWBIND'.strtoupper($suffix),
            'user_id' => (int) $user->id,
            'product_id' => (int) $product->id,
            'service_id' => (int) $service->id,
            'type' => 'renew',
            'amount' => '40.00',
            'discount' => '5.00',
            'paid_amount' => '40.00',
            'billing_cycle' => 'monthly',
            'status' => InvoiceStatus::PAID,
            'paid_at' => now(),
            'due_date' => now()->addDay(),
            'trace_id' => 'renew-bind-'.$suffix,
        ]);

        DB::connection()->table('invoices')->updateOrInsert(
            ['id' => (int) $invoice->id],
            [
                'user_id' => (int) $user->id,
                'order_id' => null,
                'service_id' => (int) $service->id,
                'type' => 'renew',
                'amount' => '45.00',
                'paid_amount' => '45.00',
                'status' => InvoiceStatus::PAID,
                'billing_cycle' => 'monthly',
                'invoice_no' => (string) $invoice->invoice_no,
                'due_date' => now()->addDay(),
                'paid_at' => now(),
                'created_at' => now(),
                'updated_at' => now(),
            ]
        );

        $renewService = new ServiceRenewService(
            new InvoiceService,
            new ProviderResolver(
                new ProviderRegistry([
                    new HostingPanelApiDriver($this->createMock(HostingPanelApiTransport::class)),
                ])
            ),
            $this->createMock(CouponService::class),
            $this->createMock(OperationLogService::class),
            new class extends SettingService
            {
                public function getAutomationConfig(): array
                {
                    return array_merge(parent::defaultAutomationConfig(), [
                        'expire_unsuspend_notify_enabled' => false,
                    ]);
                }
            },
            $this->createMock(NotificationService::class),
        );

        $result = $renewService->processPaidRenewInvoice($invoice);

        $this->assertNotNull($result);
        $this->assertSame((int) $invoice->id, (int) ($result->invoice_id ?? 0));
        $this->assertSame((int) $invoice->id, (int) ($service->fresh()?->invoice_id ?? 0));
        $this->assertSame((int) $invoice->id, (int) (($result->provision_data ?? [])['last_renew_invoice_id'] ?? 0));
        $this->assertSame('45.00', (string) $result->fresh()->amount);
        $this->assertDatabaseHas('service_provision_attempts', [
            'service_id' => (int) $service->id,
            'action' => 'renew',
            'attempt_status' => 'success',
            'trace_id' => 'renew-bind-'.$suffix,
        ]);
    }

    #[Test]
    public function create_renew_invoice_blocks_when_same_cycle_already_fulfilled(): void
    {
        $suffix = bin2hex(random_bytes(4));
        ['service' => $service, 'invoice' => $fulfilledInvoice] = $this->createUpstreamRenewFixture($suffix);

        // 服务未过期，且同周期已有已履约续费账单（模拟自动续费先扣款履约后，用户再次手动续费）。
        $service->forceFill([
            'expires_at' => now()->addDays(20),
            'provision_data' => [
                'last_renew_invoice_id' => (int) $fulfilledInvoice->id,
                'last_renew_invoice_no' => (string) $fulfilledInvoice->invoice_no,
            ],
        ])->save();

        $renewService = new ServiceRenewService(
            new InvoiceService,
            new ProviderResolver(
                new ProviderRegistry([
                    new HostingPanelApiDriver($this->createMock(HostingPanelApiTransport::class)),
                ])
            ),
            $this->createMock(CouponService::class),
            $this->createMock(OperationLogService::class),
            new class extends SettingService
            {
                public function getAutomationConfig(): array
                {
                    return array_merge(parent::defaultAutomationConfig(), [
                        'expire_unsuspend_notify_enabled' => false,
                    ]);
                }
            },
            $this->createMock(NotificationService::class),
        );

        try {
            $renewService->createRenewInvoiceForUser($service->user, (int) $service->id, 'monthly', 0, [
                'trace_id' => 'renew-block-fulfilled-'.$suffix,
            ]);
            $this->fail('Expected fulfilled same-cycle renew to be blocked.');
        } catch (BusinessException $exception) {
            $this->assertSame('当前续费周期已完成，请勿重复续费', $exception->getMessage());
        }

        $this->assertSame(
            0,
            Invoice::query()
                ->where('service_id', (int) $service->id)
                ->where('type', 'renew')
                ->where('status', InvoiceStatus::UNPAID)
                ->count()
        );
    }

    #[Test]
    public function create_renew_invoice_reuses_paid_unfulfilled_invoice_instead_of_creating_another_charge_target(): void
    {
        $suffix = bin2hex(random_bytes(4));

        $user = User::query()->create([
            'email' => 'renew-blocking-'.$suffix.'@example.com',
            'password' => 'Temp@123456',
            'phone' => '13'.str_pad((string) random_int(0, 999999999), 9, '0', STR_PAD_LEFT),
            'status' => 1,
            'nickname' => 'Renew Blocking',
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
        $this->mirrorUserToIdc($user, 'renew-blocking-'.$suffix);

        $product = Product::query()->create([
            'name' => 'Renew Blocking Product '.$suffix,
            'product_type' => 'vps',
            'pricing' => ['monthly' => '39.00'],
            'setup_fee' => '0.00',
            'config_options' => [],
            'purchase_requires' => [],
            'stock' => -1,
            'status' => 1,
            'auto_setup' => 0,
        ]);
        $this->mirrorProductToIdc($product, $suffix);

        $service = Service::query()->create([
            'user_id' => (int) $user->id,
            'product_id' => (int) $product->id,
            'name' => 'Renew Blocking Service '.$suffix,
            'domain' => 'renew-blocking-'.$suffix.'.example.com',
            'billing_cycle' => 'monthly',
            'amount' => '39.00',
            'status' => ServiceStatus::ACTIVE,
            'locked_pricing' => ['monthly' => '39.00'],
            'provision_data' => [
                'renew_error' => '上游续费失败，请联系管理员处理',
                'renew_fulfillment_status' => 'failed',
            ],
            'expires_at' => Carbon::parse('2026-06-28 00:00:00'),
            'auto_renew' => 1,
        ]);

        $paidInvoice = Invoice::query()->create([
            'invoice_no' => 'INVRENEWBLOCK'.strtoupper($suffix),
            'user_id' => (int) $user->id,
            'product_id' => (int) $product->id,
            'service_id' => (int) $service->id,
            'type' => 'renew',
            'amount' => '39.00',
            'paid_amount' => '39.00',
            'billing_cycle' => 'monthly',
            'status' => InvoiceStatus::PAID,
            'paid_at' => now(),
            'due_date' => now()->addDay(),
            'config_snapshot' => [
                'auto_renew' => 1,
                'renew_fulfillment_status' => 'failed',
            ],
        ]);

        $couponService = $this->createMock(CouponService::class);
        $couponService->expects($this->never())->method('reserveOwnedCouponForInvoice');

        $renewService = new ServiceRenewService(
            new InvoiceService,
            new ProviderResolver(
                new ProviderRegistry([
                    new HostingPanelApiDriver($this->createMock(HostingPanelApiTransport::class)),
                ])
            ),
            $couponService,
            $this->createMock(OperationLogService::class),
            new class extends SettingService
            {
                public function getAutomationConfig(): array
                {
                    return array_merge(parent::defaultAutomationConfig(), [
                        'expire_unsuspend_notify_enabled' => false,
                    ]);
                }
            },
            $this->createMock(NotificationService::class),
        );

        $result = $renewService->createRenewInvoiceForUser($user, (int) $service->id, 'monthly', 0, [
            'auto_renew' => true,
            'trace_id' => 'auto-renew-blocking-'.$suffix,
        ]);

        $this->assertSame((int) $paidInvoice->id, (int) $result->id);
        $this->assertSame(InvoiceStatus::PAID, (int) $result->status);
        $this->assertSame(
            1,
            Invoice::query()
                ->where('service_id', $service->id)
                ->where('type', 'renew')
                ->count()
        );
    }

    #[Test]
    public function create_renew_invoice_never_reuses_an_older_paid_invoice_when_a_newer_paid_invoice_exists(): void
    {
        $suffix = bin2hex(random_bytes(4));

        $user = User::query()->create([
            'email' => 'renew-paid-priority-'.$suffix.'@example.com',
            'password' => 'Temp@123456',
            'phone' => '13'.str_pad((string) random_int(0, 999999999), 9, '0', STR_PAD_LEFT),
            'status' => 1,
            'nickname' => 'Renew Paid Priority',
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
        $this->mirrorUserToIdc($user, 'renew-paid-priority-'.$suffix);

        $product = Product::query()->create([
            'name' => 'Renew Paid Priority Product '.$suffix,
            'product_type' => 'vps',
            'pricing' => ['monthly' => '57.00'],
            'setup_fee' => '0.00',
            'config_options' => [],
            'purchase_requires' => [],
            'stock' => -1,
            'status' => 1,
            'auto_setup' => 0,
        ]);
        $this->mirrorProductToIdc($product, $suffix);

        $service = Service::query()->create([
            'user_id' => (int) $user->id,
            'product_id' => (int) $product->id,
            'name' => 'Renew Paid Priority Service '.$suffix,
            'domain' => 'renew-paid-priority-'.$suffix.'.example.com',
            'billing_cycle' => 'monthly',
            'amount' => '57.00',
            'status' => ServiceStatus::ACTIVE,
            'locked_pricing' => ['monthly' => '57.00'],
            'provision_data' => [],
            'expires_at' => Carbon::parse('2026-06-30 00:00:00'),
            'auto_renew' => 1,
        ]);

        $olderPaidInvoice = Invoice::query()->create([
            'invoice_no' => 'INVRENEWOLDER'.strtoupper($suffix),
            'user_id' => (int) $user->id,
            'product_id' => (int) $product->id,
            'service_id' => (int) $service->id,
            'type' => 'renew',
            'amount' => '57.00',
            'paid_amount' => '57.00',
            'billing_cycle' => 'monthly',
            'status' => InvoiceStatus::PAID,
            'paid_at' => Carbon::parse('2026-05-01 13:41:24'),
            'due_date' => Carbon::parse('2026-05-08 00:00:00'),
            'config_snapshot' => [
                'renew_fulfillment_status' => 'failed',
            ],
        ]);

        $newerPaidInvoice = Invoice::query()->create([
            'invoice_no' => 'INVRENEWNEWER'.strtoupper($suffix),
            'user_id' => (int) $user->id,
            'product_id' => (int) $product->id,
            'service_id' => (int) $service->id,
            'type' => 'renew',
            'amount' => '57.00',
            'paid_amount' => '57.00',
            'billing_cycle' => 'monthly',
            'status' => InvoiceStatus::PAID,
            'paid_at' => Carbon::parse('2026-06-01 19:28:53'),
            'due_date' => Carbon::parse('2026-06-08 00:00:00'),
        ]);

        $service->forceFill([
            'provision_data' => [
                'last_renew_invoice_id' => (int) $newerPaidInvoice->id,
                'last_renew_invoice_no' => (string) $newerPaidInvoice->invoice_no,
            ],
        ])->save();

        $couponService = $this->createMock(CouponService::class);
        $couponService->expects($this->once())
            ->method('reserveOwnedCouponForInvoice')
            ->willReturn([]);

        $renewService = new ServiceRenewService(
            new InvoiceService,
            new ProviderResolver(
                new ProviderRegistry([
                    new HostingPanelApiDriver($this->createMock(HostingPanelApiTransport::class)),
                ])
            ),
            $couponService,
            $this->createMock(OperationLogService::class),
            new class extends SettingService
            {
                public function getAutomationConfig(): array
                {
                    return array_merge(parent::defaultAutomationConfig(), [
                        'expire_unsuspend_notify_enabled' => false,
                    ]);
                }
            },
            $this->createMock(NotificationService::class),
        );

        $result = $renewService->createRenewInvoiceForUser($user, (int) $service->id, 'monthly', 0, [
            'auto_renew' => true,
            'trace_id' => 'auto-renew-paid-priority-'.$suffix,
        ]);

        $this->assertNotSame((int) $olderPaidInvoice->id, (int) $result->id);
    }

    #[Test]
    public function create_renew_order_rolls_back_zero_amount_auto_renew_attempt(): void
    {
        $suffix = bin2hex(random_bytes(4));

        $user = User::query()->create([
            'email' => 'renew-zero-auto-'.$suffix.'@example.com',
            'password' => 'Temp@123456',
            'phone' => '13'.str_pad((string) random_int(0, 999999999), 9, '0', STR_PAD_LEFT),
            'status' => 1,
            'nickname' => 'Renew Zero Auto',
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

        $product = Product::query()->create([
            'name' => 'Renew Zero Auto Product '.$suffix,
            'product_type' => 'vps',
            'pricing' => ['monthly' => '49.00'],
            'setup_fee' => '0.00',
            'config_options' => [],
            'purchase_requires' => [],
            'stock' => -1,
            'status' => 1,
            'auto_setup' => 0,
        ]);

        $service = Service::query()->create([
            'user_id' => (int) $user->id,
            'product_id' => (int) $product->id,
            'name' => 'Renew Zero Auto Service '.$suffix,
            'domain' => 'renew-zero-auto-'.$suffix.'.example.com',
            'billing_cycle' => 'monthly',
            'amount' => '49.00',
            'status' => ServiceStatus::ACTIVE,
            'locked_pricing' => ['monthly' => '49.00'],
            'provision_data' => [],
            'expires_at' => Carbon::parse('2026-07-01 00:00:00'),
            'auto_renew' => 1,
        ]);

        $couponService = $this->createMock(CouponService::class);
        $couponService->expects($this->once())
            ->method('reserveOwnedCouponForOrder')
            ->willReturn([
                'discount_amount' => '49.00',
            ]);

        $renewService = new ServiceRenewService(
            new InvoiceService,
            new ProviderResolver(
                new ProviderRegistry([
                    new HostingPanelApiDriver($this->createMock(HostingPanelApiTransport::class)),
                ])
            ),
            $couponService,
            $this->createMock(OperationLogService::class),
            new class extends SettingService
            {
                public function getAutomationConfig(): array
                {
                    return array_merge(parent::defaultAutomationConfig(), [
                        'expire_unsuspend_notify_enabled' => false,
                    ]);
                }
            },
            $this->createMock(NotificationService::class),
        );

        try {
            $renewService->createRenewOrderForUser($user, (int) $service->id, 'monthly', 0, [
                'auto_renew' => true,
                'trace_id' => 'auto-renew-zero-order-'.$suffix,
            ]);
            $this->fail('Expected zero-amount auto-renew order creation to be blocked.');
        } catch (BusinessException $exception) {
            $this->assertSame('自动续费金额异常，已拦截本次续费', $exception->getMessage());
        }

        $this->assertSame(
            0,
            Order::query()
                ->where('service_id', (int) $service->id)
                ->where('type', 'renew')
                ->count()
        );
        $this->assertSame(
            0,
            Invoice::query()
                ->where('service_id', (int) $service->id)
                ->where('type', 'renew')
                ->count()
        );
    }

    #[Test]
    public function process_paid_renew_invoice_ignores_real_legacy_order_binding_and_keeps_invoice_first_context(): void
    {
        $suffix = bin2hex(random_bytes(4));

        $user = User::query()->create([
            'email' => 'renew-real-order-'.$suffix.'@example.com',
            'password' => 'Temp@123456',
            'phone' => '13'.str_pad((string) random_int(0, 999999999), 9, '0', STR_PAD_LEFT),
            'status' => 1,
            'nickname' => 'Renew Real Order',
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
        $this->mirrorUserToIdc($user, 'renew-real-order-'.$suffix);

        $product = Product::query()->create([
            'name' => 'Renew Real Order Product '.$suffix,
            'product_type' => 'vps',
            'pricing' => ['monthly' => '52.00'],
            'setup_fee' => '0.00',
            'config_options' => [],
            'purchase_requires' => [],
            'stock' => -1,
            'status' => 1,
            'auto_setup' => 0,
        ]);
        $this->mirrorProductToIdc($product, $suffix);

        $service = Service::query()->create([
            'user_id' => (int) $user->id,
            'product_id' => (int) $product->id,
            'name' => 'Renew Real Order Service '.$suffix,
            'domain' => 'renew-real-'.$suffix.'.example.com',
            'billing_cycle' => 'monthly',
            'amount' => '52.00',
            'status' => ServiceStatus::ACTIVE,
            'locked_pricing' => ['monthly' => '52.00'],
            'provision_data' => [],
            'expires_at' => Carbon::parse('2026-06-20 00:00:00'),
            'auto_renew' => 0,
        ]);

        $legacyOrder = Order::query()->create([
            'order_no' => 'ORDRENEWREAL'.strtoupper($suffix),
            'user_id' => (int) $user->id,
            'product_id' => (int) $product->id,
            'service_id' => (int) $service->id,
            'type' => 'renew',
            'amount' => '52.00',
            'discount' => '0.00',
            'paid_amount' => '0.00',
            'billing_cycle' => 'monthly',
            'status' => OrderStatus::PENDING,
        ]);

        $invoice = Invoice::query()->create([
            'invoice_no' => 'INVRENEWREAL'.strtoupper($suffix),
            'user_id' => (int) $user->id,
            'product_id' => (int) $product->id,
            'service_id' => (int) $service->id,
            'order_id' => (int) $legacyOrder->id,
            'type' => 'renew',
            'amount' => '52.00',
            'paid_amount' => '52.00',
            'billing_cycle' => 'monthly',
            'status' => InvoiceStatus::PAID,
            'paid_at' => now(),
            'product_snapshot_json' => [
                'order_id' => (int) $legacyOrder->id,
                'order_no' => (string) $legacyOrder->order_no,
            ],
            'due_date' => now()->addDay(),
        ]);

        DB::connection()->table('invoices')->updateOrInsert(
            ['id' => (int) $invoice->id],
            [
                'user_id' => (int) $user->id,
                'order_id' => (int) $legacyOrder->id,
                'service_id' => (int) $service->id,
                'type' => 'renew',
                'amount' => '52.00',
                'paid_amount' => '52.00',
                'status' => InvoiceStatus::PAID,
                'billing_cycle' => 'monthly',
                'invoice_no' => (string) $invoice->invoice_no,
                'due_date' => now()->addDay(),
                'paid_at' => now(),
                'created_at' => now(),
                'updated_at' => now(),
            ]
        );

        $renewService = new ServiceRenewService(
            new InvoiceService,
            new ProviderResolver(
                new ProviderRegistry([
                    new HostingPanelApiDriver($this->createMock(HostingPanelApiTransport::class)),
                ])
            ),
            $this->createMock(CouponService::class),
            $this->createMock(OperationLogService::class),
            new class extends SettingService
            {
                public function getAutomationConfig(): array
                {
                    return array_merge(parent::defaultAutomationConfig(), [
                        'expire_unsuspend_notify_enabled' => false,
                    ]);
                }
            },
            $this->createMock(NotificationService::class),
        );

        $result = $renewService->processPaidRenewInvoice($invoice);

        $this->assertNotNull($result);
        $this->assertSame((int) $invoice->id, (int) ($result->invoice_id ?? 0));
        $this->assertSame(0, (int) ($result->order_id ?? 0));
        $this->assertSame((int) $invoice->id, (int) (($result->provision_data ?? [])['last_renew_invoice_id'] ?? 0));
        $this->assertSame('local_invoice', (string) (($result->provision_data ?? [])['last_renew_source'] ?? ''));

        $storedOrder = $legacyOrder->fresh();
        $this->assertSame(OrderStatus::PENDING, (int) ($storedOrder?->status ?? -1));
        $this->assertSame('0.00', number_format((float) ($storedOrder?->paid_amount ?? 0), 2, '.', ''));
    }

    #[Test]
    public function process_paid_renew_order_delegates_to_bound_invoice_and_keeps_legacy_order_pending(): void
    {
        $suffix = bin2hex(random_bytes(4));

        $user = User::query()->create([
            'email' => 'renew-order-delegate-'.$suffix.'@example.com',
            'password' => 'Temp@123456',
            'phone' => '13'.str_pad((string) random_int(0, 999999999), 9, '0', STR_PAD_LEFT),
            'status' => 1,
            'nickname' => 'Renew Order Delegate',
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
        $this->mirrorUserToIdc($user, 'renew-order-delegate-'.$suffix);

        $product = Product::query()->create([
            'name' => 'Renew Order Delegate Product '.$suffix,
            'product_type' => 'vps',
            'pricing' => ['monthly' => '61.00'],
            'setup_fee' => '0.00',
            'config_options' => [],
            'purchase_requires' => [],
            'stock' => -1,
            'status' => 1,
            'auto_setup' => 0,
        ]);
        $this->mirrorProductToIdc($product, $suffix);

        $service = Service::query()->create([
            'user_id' => (int) $user->id,
            'product_id' => (int) $product->id,
            'name' => 'Renew Order Delegate Service '.$suffix,
            'domain' => 'renew-order-'.$suffix.'.example.com',
            'billing_cycle' => 'monthly',
            'amount' => '61.00',
            'status' => ServiceStatus::ACTIVE,
            'locked_pricing' => ['monthly' => '61.00'],
            'provision_data' => [],
            'expires_at' => Carbon::parse('2026-06-25 00:00:00'),
            'auto_renew' => 0,
        ]);

        $legacyOrder = Order::query()->create([
            'order_no' => 'ORDRENEWDELEGATE'.strtoupper($suffix),
            'user_id' => (int) $user->id,
            'product_id' => (int) $product->id,
            'service_id' => (int) $service->id,
            'type' => 'renew',
            'amount' => '61.00',
            'discount' => '0.00',
            'paid_amount' => '0.00',
            'billing_cycle' => 'monthly',
            'status' => OrderStatus::PENDING,
        ]);

        $invoice = Invoice::query()->create([
            'invoice_no' => 'INVRENEWDELEGATE'.strtoupper($suffix),
            'user_id' => (int) $user->id,
            'product_id' => (int) $product->id,
            'service_id' => (int) $service->id,
            'order_id' => (int) $legacyOrder->id,
            'type' => 'renew',
            'amount' => '61.00',
            'paid_amount' => '61.00',
            'billing_cycle' => 'monthly',
            'status' => InvoiceStatus::PAID,
            'paid_at' => now(),
            'product_snapshot_json' => [
                'order_id' => (int) $legacyOrder->id,
                'order_no' => (string) $legacyOrder->order_no,
            ],
            'due_date' => now()->addDay(),
        ]);

        DB::connection()->table('invoices')->updateOrInsert(
            ['id' => (int) $invoice->id],
            [
                'user_id' => (int) $user->id,
                'order_id' => (int) $legacyOrder->id,
                'service_id' => (int) $service->id,
                'type' => 'renew',
                'amount' => '61.00',
                'paid_amount' => '61.00',
                'status' => InvoiceStatus::PAID,
                'billing_cycle' => 'monthly',
                'invoice_no' => (string) $invoice->invoice_no,
                'due_date' => now()->addDay(),
                'paid_at' => now(),
                'created_at' => now(),
                'updated_at' => now(),
            ]
        );

        $renewService = new ServiceRenewService(
            new InvoiceService,
            new ProviderResolver(
                new ProviderRegistry([
                    new HostingPanelApiDriver($this->createMock(HostingPanelApiTransport::class)),
                ])
            ),
            $this->createMock(CouponService::class),
            $this->createMock(OperationLogService::class),
            new class extends SettingService
            {
                public function getAutomationConfig(): array
                {
                    return array_merge(parent::defaultAutomationConfig(), [
                        'expire_unsuspend_notify_enabled' => false,
                    ]);
                }
            },
            $this->createMock(NotificationService::class),
        );

        $result = $renewService->processPaidRenewOrder($legacyOrder);

        $this->assertNotNull($result);
        $this->assertSame((int) $invoice->id, (int) ($result->invoice_id ?? 0));
        $this->assertSame(0, (int) ($result->order_id ?? 0));
        $this->assertSame((int) $invoice->id, (int) (($result->provision_data ?? [])['last_renew_invoice_id'] ?? 0));
        $this->assertSame('local_invoice', (string) (($result->provision_data ?? [])['last_renew_source'] ?? ''));

        $storedOrder = $legacyOrder->fresh();
        $this->assertSame(OrderStatus::PENDING, (int) ($storedOrder?->status ?? -1));
        $this->assertSame('0.00', number_format((float) ($storedOrder?->paid_amount ?? 0), 2, '.', ''));
    }

    #[Test]
    public function process_paid_renew_order_without_bound_invoice_returns_null_instead_of_falling_back_to_legacy_order_flow(): void
    {
        $suffix = bin2hex(random_bytes(4));

        $user = User::query()->create([
            'email' => 'renew-order-no-invoice-'.$suffix.'@example.com',
            'password' => 'Temp@123456',
            'phone' => '13'.str_pad((string) random_int(0, 999999999), 9, '0', STR_PAD_LEFT),
            'status' => 1,
            'nickname' => 'Renew Order No Invoice',
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
        $this->mirrorUserToIdc($user, 'renew-order-no-invoice-'.$suffix);

        $product = Product::query()->create([
            'name' => 'Renew Order No Invoice Product '.$suffix,
            'product_type' => 'vps',
            'pricing' => ['monthly' => '61.00'],
            'setup_fee' => '0.00',
            'config_options' => [],
            'purchase_requires' => [],
            'stock' => -1,
            'status' => 1,
            'auto_setup' => 0,
        ]);
        $this->mirrorProductToIdc($product, $suffix);

        $service = Service::query()->create([
            'user_id' => (int) $user->id,
            'product_id' => (int) $product->id,
            'name' => 'Renew Order No Invoice Service '.$suffix,
            'domain' => 'renew-no-invoice-'.$suffix.'.example.com',
            'billing_cycle' => 'monthly',
            'amount' => '61.00',
            'status' => ServiceStatus::ACTIVE,
            'locked_pricing' => ['monthly' => '61.00'],
            'provision_data' => [],
            'expires_at' => Carbon::parse('2026-06-25 00:00:00'),
            'auto_renew' => 0,
            'invoice_id' => null,
            'order_id' => null,
        ]);

        $legacyOrder = Order::query()->create([
            'order_no' => 'ORDRENEWNOINV'.strtoupper($suffix),
            'user_id' => (int) $user->id,
            'product_id' => (int) $product->id,
            'service_id' => (int) $service->id,
            'type' => 'renew',
            'amount' => '61.00',
            'discount' => '0.00',
            'paid_amount' => '61.00',
            'billing_cycle' => 'monthly',
            'status' => OrderStatus::PAID,
            'paid_at' => now(),
        ]);

        $renewService = new ServiceRenewService(
            new InvoiceService,
            new ProviderResolver(
                new ProviderRegistry([
                    new HostingPanelApiDriver($this->createMock(HostingPanelApiTransport::class)),
                ])
            ),
            $this->createMock(CouponService::class),
            $this->createMock(OperationLogService::class),
            new class extends SettingService
            {
                public function getAutomationConfig(): array
                {
                    return array_merge(parent::defaultAutomationConfig(), [
                        'expire_unsuspend_notify_enabled' => false,
                    ]);
                }
            },
            $this->createMock(NotificationService::class),
        );

        $result = $renewService->processPaidRenewOrder($legacyOrder);

        $this->assertNull($result);
        $storedOrder = $legacyOrder->fresh();
        $this->assertSame(OrderStatus::PAID, (int) ($storedOrder?->status ?? -1));
        $this->assertNull($service->fresh()?->invoice_id);
        $this->assertNull(($service->fresh()?->provision_data ?? [])['last_renew_invoice_id'] ?? null);
    }
}

final class FakeInvoiceRenewalCapability implements ProvidesRenewal
{
    public int $renewServiceInvoiceCalls = 0;

    public int $recoverCalls = 0;

    public int $lastRecoveredUpstreamInvoiceId = 0;

    /** @var array<string, mixed> */
    public array $lastRecoveryContext = [];

    public function __construct(private readonly bool $throwOnRenew = false) {}

    /**
     * @return array<string, mixed>
     */
    public function renewServiceInvoice(Supplier $supplier, int $hostId, string $billingCycle): array
    {
        $this->renewServiceInvoiceCalls++;

        if ($this->throwOnRenew) {
            throw new BusinessException('模拟创建上游续费账单失败');
        }

        return [
            'upstream_invoice_id' => 99999,
            'payment_completed' => false,
        ];
    }

    /**
     * @param  array<string, mixed>  $context
     * @return array<string, mixed>
     */
    public function recoverRenewInvoiceWithContext(
        Supplier $supplier,
        int $hostId,
        int $upstreamInvoiceId,
        array $context,
    ): array {
        $this->recoverCalls++;
        $this->lastRecoveredUpstreamInvoiceId = $upstreamInvoiceId;
        $this->lastRecoveryContext = $context;

        return [
            'payment_completed' => false,
            'fund_error' => '上游续费账单仍未支付完成',
        ];
    }
}

final class FakeInvoiceRenewalBindingResolver extends PluginBindingResolver
{
    public function __construct(private readonly Supplier $supplier) {}

    public function providerKeyForService(Service $service): ?string
    {
        return FakeInvoiceRenewalDriver::KEY;
    }

    public function upstreamServiceIdForService(Service $service): ?string
    {
        return '88001';
    }

    public function supplierForService(Service $service): ?Supplier
    {
        return $this->supplier;
    }

    public function supplierForProduct(Product $product): ?Supplier
    {
        return $this->supplier;
    }

    public function supplierWithRuntimeCredentials(Supplier $supplier, bool $includeSecrets = true, ?string $providerKey = null): Supplier
    {
        return $supplier;
    }
}

final class FakeInvoiceRenewalDriver implements UpstreamDriver
{
    public const KEY = 'test_invoice_renewal';

    public function __construct(private readonly FakeInvoiceRenewalCapability $capability) {}

    public function key(): string
    {
        return self::KEY;
    }

    public function label(): string
    {
        return '测试续费能力';
    }

    /**
     * @return array<int, class-string>
     */
    public function capabilities(): array
    {
        return [ProvidesRenewal::class];
    }

    public function supports(string $capability): bool
    {
        return $capability === ProvidesRenewal::class;
    }

    public function resolve(string $capability): ?object
    {
        return $this->supports($capability) ? $this->capability : null;
    }
}
