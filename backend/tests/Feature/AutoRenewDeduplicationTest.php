<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Constants\FinanceLedgerEventType;
use App\Constants\InvoiceStatus;
use App\Constants\OrderStatus;
use App\Constants\ServiceStatus;
use App\Models\AccountTransaction;
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
use App\Services\Upstream\ProviderRegistry;
use App\Services\Upstream\ProviderResolver;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class AutoRenewDeduplicationTest extends TestCase
{
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
    }

    /**
     * @return array{user: User, product: Product, service: Service}
     */
    private function createRenewFixture(string $suffix): array
    {
        $user = User::query()->create([
            'email' => 'renew-dedup-'.$suffix.'@example.com',
            'password' => 'Temp@123456',
            'phone' => '13'.str_pad((string) random_int(0, 999999999), 9, '0', STR_PAD_LEFT),
            'status' => 1,
            'nickname' => 'Renew Dedup',
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
        $this->mirrorUserToIdc($user, 'renew-dedup-'.$suffix);

        $product = Product::query()->create([
            'name' => 'Renew Dedup Product '.$suffix,
            'product_type' => 'vps',
            'pricing' => ['monthly' => '48.00'],
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
            'name' => 'Renew Dedup Service '.$suffix,
            'domain' => 'renew-dedup-'.$suffix.'.example.com',
            'billing_cycle' => 'monthly',
            'amount' => '48.00',
            'status' => ServiceStatus::ACTIVE,
            'locked_pricing' => ['monthly' => '48.00'],
            'provision_data' => [],
            'expires_at' => Carbon::parse('2026-09-20 00:00:00'),
            'auto_renew' => 0,
        ]);

        return compact('user', 'product', 'service');
    }

    private function makeRenewService(): ServiceRenewService
    {
        $supplier = new Supplier(['name' => 'Fake renew dedup supplier']);
        $bindingResolver = new FakeRenewDedupBindingResolver($supplier);
        $couponService = $this->createMock(CouponService::class);
        $couponService->method('reserveOwnedCouponForOrder')->willReturn([
            'coupon_id' => null,
            'user_coupon_id' => null,
            'code' => null,
            'discount_amount' => 0,
        ]);

        return new ServiceRenewService(
            new InvoiceService,
            new ProviderResolver(new ProviderRegistry([]), $bindingResolver),
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
            $bindingResolver,
        );
    }

    #[Test]
    public function create_renew_order_reuses_paid_unfulfilled_order_without_creating_new_one(): void
    {
        $suffix = bin2hex(random_bytes(4));
        ['user' => $user, 'product' => $product, 'service' => $service] = $this->createRenewFixture($suffix);

        $existingOrder = Order::query()->create([
            'order_no' => Order::generateOrderNo(),
            'projection_type' => Order::PROJECTION_TYPE_PROVISIONING,
            'user_id' => (int) $user->id,
            'product_id' => (int) $product->id,
            'service_id' => (int) $service->id,
            'type' => 'renew',
            'amount' => '48.00',
            'discount' => '0.00',
            'paid_amount' => '48.00',
            'billing_cycle' => 'monthly',
            'quantity' => 1,
            'config_snapshot' => [],
            'status' => OrderStatus::PAID,
        ]);

        Invoice::query()->create([
            'invoice_no' => 'INVRENEWDEDUP'.strtoupper($suffix),
            'user_id' => (int) $user->id,
            'product_id' => (int) $product->id,
            'service_id' => (int) $service->id,
            'order_id' => (int) $existingOrder->id,
            'type' => 'renew',
            'amount' => '48.00',
            'paid_amount' => '48.00',
            'billing_cycle' => 'monthly',
            'status' => InvoiceStatus::PAID,
            'paid_at' => now(),
            'due_date' => now()->addDay(),
        ]);

        $beforeCount = Order::query()->where('service_id', $service->id)->count();

        $order = $this->makeRenewService()->createRenewOrderForUser(
            $user,
            (int) $service->id,
            'monthly',
            0,
            ['auto_renew' => true, 'trace_id' => 'auto_renew:dedup:'.$suffix]
        );

        $this->assertSame((int) $existingOrder->id, (int) $order->id);
        $this->assertNotNull($order->invoice);
        $this->assertSame((int) $beforeCount, Order::query()->where('service_id', $service->id)->count());
    }

    #[Test]
    public function create_renew_order_reuses_unpaid_order_with_same_amount(): void
    {
        $suffix = bin2hex(random_bytes(4));
        ['user' => $user, 'product' => $product, 'service' => $service] = $this->createRenewFixture($suffix);

        $existingOrder = Order::query()->create([
            'order_no' => Order::generateOrderNo(),
            'projection_type' => Order::PROJECTION_TYPE_PROVISIONING,
            'user_id' => (int) $user->id,
            'product_id' => (int) $product->id,
            'service_id' => (int) $service->id,
            'type' => 'renew',
            'amount' => '48.00',
            'discount' => '0.00',
            'paid_amount' => '0.00',
            'billing_cycle' => 'monthly',
            'quantity' => 1,
            'config_snapshot' => [],
            'status' => OrderStatus::PENDING,
        ]);

        Invoice::query()->create([
            'invoice_no' => 'INVRENEWUNPAID'.strtoupper($suffix),
            'user_id' => (int) $user->id,
            'product_id' => (int) $product->id,
            'service_id' => (int) $service->id,
            'order_id' => (int) $existingOrder->id,
            'type' => 'renew',
            'amount' => '48.00',
            'paid_amount' => '0.00',
            'billing_cycle' => 'monthly',
            'status' => InvoiceStatus::UNPAID,
            'due_date' => now()->addDay(),
        ]);

        $beforeCount = Order::query()->where('service_id', $service->id)->count();

        $order = $this->makeRenewService()->createRenewOrderForUser(
            $user,
            (int) $service->id,
            'monthly',
            0,
            ['auto_renew' => true, 'trace_id' => 'auto_renew:dedup:'.$suffix]
        );

        $this->assertSame((int) $existingOrder->id, (int) $order->id);
        $this->assertSame((int) $beforeCount, Order::query()->where('service_id', $service->id)->count());
    }

    #[Test]
    public function process_paid_renew_invoice_auto_refunds_superseded_invoice(): void
    {
        $suffix = bin2hex(random_bytes(4));
        ['user' => $user, 'product' => $product, 'service' => $service] = $this->createRenewFixture($suffix);

        $olderOrder = Order::query()->create([
            'order_no' => Order::generateOrderNo(),
            'projection_type' => Order::PROJECTION_TYPE_PROVISIONING,
            'user_id' => (int) $user->id,
            'product_id' => (int) $product->id,
            'service_id' => (int) $service->id,
            'type' => 'renew',
            'amount' => '48.00',
            'discount' => '0.00',
            'paid_amount' => '48.00',
            'billing_cycle' => 'monthly',
            'quantity' => 1,
            'config_snapshot' => [],
            'status' => OrderStatus::PAID,
        ]);

        $olderInvoice = Invoice::query()->create([
            'invoice_no' => 'INVRENEWOLDER'.strtoupper($suffix),
            'user_id' => (int) $user->id,
            'product_id' => (int) $product->id,
            'service_id' => (int) $service->id,
            'order_id' => (int) $olderOrder->id,
            'type' => 'renew',
            'amount' => '48.00',
            'paid_amount' => '48.00',
            'billing_cycle' => 'monthly',
            'status' => InvoiceStatus::PAID,
            'paid_at' => now(),
            'due_date' => now()->addDay(),
        ]);

        // 余额支付流水：自动退款的可退金额依赖该流水识别余额支付来源
        AccountTransaction::query()->create([
            'user_id' => (int) $user->id,
            'account_type' => 'cash',
            'event_type' => FinanceLedgerEventType::INVOICE_PAYMENT,
            'change_amount' => '-48.00',
            'balance_after' => '0.00',
            'source_type' => 'invoice',
            'source_id' => (int) $olderInvoice->id,
            'origin_type' => 'invoice',
            'origin_id' => (int) $olderInvoice->id,
            'remark' => '续费账单支付测试',
            'operator' => 'system',
            'trace_id' => 'trace-renew-older-'.$suffix,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        Invoice::query()->create([
            'invoice_no' => 'INVRENEWNEWER'.strtoupper($suffix),
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

        $result = $this->makeRenewService()->processPaidRenewInvoice($olderInvoice->fresh());

        $this->assertNotNull($result);
        $this->assertSame((int) InvoiceStatus::REFUNDED, (int) $olderInvoice->fresh()->status);
        $this->assertSame((int) OrderStatus::REFUNDED, (int) $olderOrder->fresh()->status);
    }
}

final class FakeRenewDedupBindingResolver extends PluginBindingResolver
{
    public function __construct(private readonly Supplier $supplier) {}

    public function providerKeyForService(Service $service): ?string
    {
        return null;
    }

    public function upstreamServiceIdForService(Service $service): ?string
    {
        return null;
    }

    public function supplierForService(Service $service): ?Supplier
    {
        return null;
    }

    public function supplierForProduct(Product $product): ?Supplier
    {
        return null;
    }
}
