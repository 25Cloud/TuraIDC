<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Constants\InvoiceStatus;
use App\Constants\ServiceStatus;
use App\Exceptions\BusinessException;
use App\Models\Invoice;
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
use App\Services\Upstream\Contracts\ProvidesContextualRenewalRecovery;
use App\Services\Upstream\Contracts\ProvidesInvoiceRenewal;
use App\Services\Upstream\Contracts\ProvidesRenewal;
use App\Services\Upstream\Contracts\UpstreamDriver;
use App\Services\Upstream\ProviderRegistry;
use App\Services\Upstream\ProviderResolver;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * 续费履约记账丢失后的自愈回归。
 *
 * 线上场景：上游续费已付款成功，但本地履约记账（renew_fulfillment_status /
 * last_renew_invoice_id / renew_invoice_id）在后续写入中被覆盖，导致
 * 「已支付未履约」判定永远为真——用户被「该周期已有一笔正在处理的续费」永久拦截，
 * 且没有任何自愈入口。
 *
 * 本用例覆盖：
 * - 记账丢失但续费单快照里留有上游账单号时，先与上游对账补齐履约再放行，且不重复提交上游续费；
 * - 上游对账不通过（未支付 / 不可达）时维持原有拦截，不放行也不重复扣款。
 */
class ServiceRenewUnfulfilledReconcileTest extends TestCase
{
    use DatabaseTransactions;

    #[Test]
    public function lost_fulfillment_record_is_reconciled_with_upstream_and_renew_is_allowed(): void
    {
        [$user, $service] = $this->fixture();
        $paidInvoice = $this->paidRenewInvoice($user, $service, [
            'renew_inflight' => [
                'status' => 'submitted',
                'invoice_id' => 0,
                'upstream_invoice_id' => 812684,
            ],
        ]);

        // 本地履约记账丢失：只剩上游账单号，没有 renew_invoice_id / 履约状态
        $service->forceFill([
            'provision_data' => [
                'provider_key' => ReconcileRenewDriver::KEY,
                'upstream_host_id' => 2735,
                'upstream_invoice_id' => 812684,
            ],
        ])->save();

        $capability = new ReconcileRenewCapability(paymentCompleted: true);
        $renewService = $this->makeRenewService($capability);

        $newInvoice = $renewService->createRenewInvoiceForUser(
            $user,
            (int) $service->id,
            'monthly',
            0,
            [],
            true
        );

        // 上游只做对账，绝不重复提交续费（重复提交会二次扣供应商余额）
        $this->assertSame(0, $capability->renewServiceInvoiceCalls);
        $this->assertSame(1, $capability->recoverCalls);
        $this->assertSame(812684, $capability->lastRecoveredUpstreamInvoiceId);

        // 履约已补齐：新账单与旧账单不是同一笔
        $this->assertNotSame((int) $paidInvoice->id, (int) $newInvoice->id);

        $service->refresh();
        $provisionData = is_array($service->provision_data) ? $service->provision_data : [];
        $this->assertSame('succeeded', (string) ($provisionData['renew_fulfillment_status'] ?? ''));
        $this->assertSame((int) $paidInvoice->id, (int) ($provisionData['last_renew_invoice_id'] ?? 0));
        $this->assertSame((int) $paidInvoice->id, (int) ($provisionData['renew_invoice_id'] ?? 0));

        // 到期时间取上游 nextduedate，与上游口径一致
        $this->assertSame(
            '2026-10-27 21:20:00',
            optional($service->expires_at)->format('Y-m-d H:i:s')
        );
    }

    #[Test]
    public function unreconcilable_upstream_invoice_keeps_renew_blocked(): void
    {
        [$user, $service] = $this->fixture();
        $this->paidRenewInvoice($user, $service, [
            'renew_inflight' => [
                'status' => 'submitted',
                'invoice_id' => 0,
                'upstream_invoice_id' => 812684,
            ],
        ]);

        $service->forceFill([
            'provision_data' => [
                'provider_key' => ReconcileRenewDriver::KEY,
                'upstream_host_id' => 2735,
                'upstream_invoice_id' => 812684,
            ],
        ])->save();

        $capability = new ReconcileRenewCapability(paymentCompleted: false);
        $renewService = $this->makeRenewService($capability);

        $this->expectException(BusinessException::class);
        $this->expectExceptionMessage('该周期已有一笔正在处理的续费');

        $renewService->createRenewInvoiceForUser(
            $user,
            (int) $service->id,
            'monthly',
            0,
            [],
            true
        );
    }

    /**
     * @return array{0: User, 1: Service}
     */
    private function fixture(): array
    {
        $user = User::factory()->create();
        $product = Product::query()->create([
            'name' => '续费对账回归'.uniqid(),
            'product_type' => 'cloud_host',
            'status' => 1,
            'pricing' => ['monthly' => '20.00'],
            'setup_fee' => '0.00',
            'config_options' => [],
            'purchase_requires' => [],
            'stock' => -1,
            'auto_setup' => 0,
        ]);
        $service = Service::query()->create([
            'user_id' => (int) $user->id,
            'product_id' => (int) $product->id,
            'billing_cycle' => 'monthly',
            'amount' => 20.00,
            'status' => ServiceStatus::ACTIVE,
            'expires_at' => now()->addMonth(),
        ]);

        return [$user, $service];
    }

    /**
     * @param  array<string, mixed>  $configSnapshot
     */
    private function paidRenewInvoice(User $user, Service $service, array $configSnapshot = []): Invoice
    {
        return Invoice::query()->create([
            'invoice_no' => Invoice::generateInvoiceNo(),
            'user_id' => (int) $user->id,
            'product_id' => (int) $service->product_id,
            'service_id' => (int) $service->id,
            'type' => 'renew',
            'amount' => '20.00',
            'paid_amount' => '20.00',
            'billing_cycle' => 'monthly',
            'status' => InvoiceStatus::PAID,
            'paid_at' => now(),
            'due_date' => now()->addDay(),
            'config_snapshot' => $configSnapshot,
        ]);
    }

    private function makeRenewService(ReconcileRenewCapability $capability): ServiceRenewService
    {
        $supplier = new Supplier(['name' => '对账测试供应商']);
        $bindingResolver = new ReconcileBindingResolver($supplier);

        return new ServiceRenewService(
            new InvoiceService,
            new ProviderResolver(
                new ProviderRegistry([
                    new ReconcileRenewDriver($capability),
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
}

final class ReconcileRenewCapability implements ProvidesContextualRenewalRecovery, ProvidesInvoiceRenewal
{
    public int $renewServiceInvoiceCalls = 0;

    public int $recoverCalls = 0;

    public int $lastRecoveredUpstreamInvoiceId = 0;

    public function __construct(private readonly bool $paymentCompleted) {}

    /**
     * @return array<string, mixed>
     */
    public function renewServiceInvoice(Supplier $supplier, int $hostId, string $billingCycle): array
    {
        $this->renewServiceInvoiceCalls++;

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
        array $context = [],
    ): array {
        $this->recoverCalls++;
        $this->lastRecoveredUpstreamInvoiceId = $upstreamInvoiceId;

        return [
            'payment_completed' => $this->paymentCompleted,
            'fund_error' => $this->paymentCompleted ? '' : '上游续费账单仍未支付完成',
            'host_detail' => $this->paymentCompleted
                ? ['domainstatus' => 'Active', 'nextduedate' => 1793107200]
                : [],
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    public function recoverRenewInvoice(Supplier $supplier, int $hostId, int $upstreamInvoiceId): ?array
    {
        return $this->recoverRenewInvoiceWithContext($supplier, $hostId, $upstreamInvoiceId, []);
    }
}

final class ReconcileBindingResolver extends PluginBindingResolver
{
    public function __construct(private readonly Supplier $supplier) {}

    public function providerKeyForService(Service $service): ?string
    {
        return ReconcileRenewDriver::KEY;
    }

    public function upstreamServiceIdForService(Service $service): ?string
    {
        return '2735';
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

final class ReconcileRenewDriver implements UpstreamDriver
{
    public const KEY = 'test_reconcile_renewal';

    public function __construct(private readonly ReconcileRenewCapability $capability) {}

    public function key(): string
    {
        return self::KEY;
    }

    public function label(): string
    {
        return '续费对账测试能力';
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
