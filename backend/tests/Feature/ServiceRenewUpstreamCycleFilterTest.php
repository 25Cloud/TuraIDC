<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Constants\ServiceStatus;
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
use App\Services\Upstream\ProviderRegistry;
use App\Services\Upstream\ProviderResolver;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * 续费周期按上游可续周期过滤的回归测试。
 *
 * 商品仅配置月付价格时，本地会按单价派生出季/半年/年价格并全部开放续费；
 * 上游（如智简魔方财务）对换周期续费校验商品定价，未启用该周期时拒绝，
 * 导致用户已扣款但履约失败。buildRenewConfig 必须按上游 /host/renewpage 口径收敛周期集合。
 */
class ServiceRenewUpstreamCycleFilterTest extends TestCase
{
    /** @var list<int> */
    private array $productIds = [];

    /** @var list<int> */
    private array $userIds = [];

    /**
     * 共享测试库上的既有列表类断言按索引取值，残留行会污染它们，测试结束时清理本类 fixture。
     */
    protected function tearDown(): void
    {
        DB::connection()->table('services')->whereIn('product_id', $this->productIds)->delete();
        DB::connection()->table('products')->whereIn('id', $this->productIds)->delete();
        DB::connection()->table('users')->whereIn('id', $this->userIds)->delete();

        parent::tearDown();
    }

    #[Test]
    public function renew_preview_only_offers_cycles_the_upstream_supports(): void
    {
        ['user' => $user, 'service' => $service] = $this->createUpstreamServiceFixture();

        $capability = new UpstreamCycleFilterCapability(['monthly']);
        $preview = $this->makeRenewService($capability)->previewForUser($user, (int) $service->id);

        $this->assertSame(['monthly'], array_column($preview['cycles'], 'billing_cycle'));
        $this->assertSame('monthly', $preview['default_cycle']);
    }

    #[Test]
    public function renew_preview_moves_default_cycle_when_upstream_drops_the_current_cycle(): void
    {
        ['user' => $user, 'service' => $service] = $this->createUpstreamServiceFixture();

        $capability = new UpstreamCycleFilterCapability(['semiannually']);
        $preview = $this->makeRenewService($capability)->previewForUser($user, (int) $service->id);

        $this->assertSame(['semiannually'], array_column($preview['cycles'], 'billing_cycle'));
        $this->assertSame('semiannually', $preview['default_cycle']);
    }

    #[Test]
    public function renew_preview_falls_back_to_local_cycles_when_upstream_is_unreachable(): void
    {
        ['user' => $user, 'service' => $service] = $this->createUpstreamServiceFixture();

        $capability = new UpstreamCycleFilterCapability(null);
        $preview = $this->makeRenewService($capability)->previewForUser($user, (int) $service->id);

        $this->assertSame(
            ['monthly', 'quarterly', 'semiannually', 'annually'],
            array_column($preview['cycles'], 'billing_cycle'),
        );
        $this->assertSame('monthly', $preview['default_cycle']);
    }

    #[Test]
    public function renew_preview_falls_back_when_upstream_cycles_have_no_overlap_with_local_pricing(): void
    {
        ['user' => $user, 'service' => $service] = $this->createUpstreamServiceFixture();

        $capability = new UpstreamCycleFilterCapability(['fourly']);
        $preview = $this->makeRenewService($capability)->previewForUser($user, (int) $service->id);

        $this->assertSame(
            ['monthly', 'quarterly', 'semiannually', 'annually'],
            array_column($preview['cycles'], 'billing_cycle'),
        );
    }

    #[Test]
    public function renew_preview_keeps_local_cycles_for_capabilities_without_upstream_cycle_support(): void
    {
        ['user' => $user, 'service' => $service] = $this->createUpstreamServiceFixture();

        $preview = $this->makeRenewService(new BareRenewalCapability)->previewForUser($user, (int) $service->id);

        $this->assertSame(
            ['monthly', 'quarterly', 'semiannually', 'annually'],
            array_column($preview['cycles'], 'billing_cycle'),
        );
    }

    private function makeRenewService(object $capability): ServiceRenewService
    {
        $supplier = new Supplier(['name' => 'Fake cycle filter supplier']);
        $bindingResolver = new UpstreamCycleFilterBindingResolver($supplier);

        return new ServiceRenewService(
            new InvoiceService,
            new ProviderResolver(
                new ProviderRegistry([
                    new UpstreamCycleFilterDriver($capability),
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

    /**
     * @return array{user: User, service: Service}
     */
    private function createUpstreamServiceFixture(): array
    {
        $suffix = bin2hex(random_bytes(4));

        $user = User::query()->create([
            'email' => 'renew-cycle-filter-'.$suffix.'@example.com',
            'password' => 'Temp@123456',
            'phone' => '13'.str_pad((string) random_int(0, 999999999), 9, '0', STR_PAD_LEFT),
            'status' => 1,
            'nickname' => 'Renew Cycle Filter',
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
        $this->mirrorUserToIdc($user, 'renew-cycle-filter-'.$suffix);
        $this->userIds[] = (int) $user->id;

        $product = Product::query()->create([
            'name' => 'Renew Cycle Filter Product '.$suffix,
            'product_type' => 'vps',
            'pricing' => ['monthly' => '48.00'],
            'setup_fee' => '0.00',
            'config_options' => [],
            'purchase_requires' => [],
            'stock' => -1,
            'status' => 1,
            'auto_setup' => 0,
        ]);
        $this->mirrorProductToIdc($product, 'renew-cycle-filter-'.$suffix);
        $this->productIds[] = (int) $product->id;

        $service = Service::query()->create([
            'user_id' => (int) $user->id,
            'product_id' => (int) $product->id,
            'name' => 'Renew Cycle Filter Service '.$suffix,
            'domain' => 'renew-cycle-filter-'.$suffix.'.example.com',
            'billing_cycle' => 'monthly',
            'amount' => '48.00',
            'status' => ServiceStatus::ACTIVE,
            'locked_pricing' => [],
            'provision_data' => [],
            'expires_at' => Carbon::parse('2026-12-20 00:00:00'),
            'auto_renew' => 0,
        ]);

        return compact('user', 'service');
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
    }

    private function mirrorProductToIdc(Product $product, string $suffix): void
    {
        DB::connection()->table('products')->updateOrInsert(
            ['id' => (int) $product->id],
            Product::buildIdcMirrorPayload($product, $suffix.'-'.(int) $product->id)
        );
    }
}

/**
 * 模拟实现了 renewableCycles 的上游插件（如 zjmf_finance）。
 * cycles 为 null 表示上游不可达（回退本地集合）。
 */
final class UpstreamCycleFilterCapability implements ProvidesRenewal
{
    public function __construct(private readonly ?array $cycles) {}

    /**
     * @return list<string>|null
     */
    public function renewableCycles(Supplier $supplier, int $hostId): ?array
    {
        return $this->cycles;
    }
}

/** 模拟未实现 renewableCycles 的上游插件，应保持原有本地周期行为。 */
final class BareRenewalCapability implements ProvidesRenewal {}

final class UpstreamCycleFilterDriver implements UpstreamDriver
{
    public const KEY = 'test_upstream_cycle_filter';

    public function __construct(private readonly object $capability) {}

    public function key(): string
    {
        return self::KEY;
    }

    public function label(): string
    {
        return '测试上游周期过滤';
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

final class UpstreamCycleFilterBindingResolver extends PluginBindingResolver
{
    public function __construct(private readonly Supplier $supplier) {}

    public function providerKeyForService(Service $service): ?string
    {
        return UpstreamCycleFilterDriver::KEY;
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
