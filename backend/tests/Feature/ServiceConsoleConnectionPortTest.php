<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Constants\ProductType;
use App\Constants\ServiceStatus;
use App\Models\Product;
use App\Models\Service;
use App\Models\User;
use App\Services\ClientServiceConsole\ServiceDetailService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * 连接端口：魔方财务对 KVM/云服务器实例不下发 host.port，上游面板自己也不填，
 * 于是整条链路都是 0，实例页只能显示 `--`。这类机器的 SSH 端口就是 22，
 * 只对机器类产品兜底；面板型产品上游会给端口，兜成 22 反而是错的。
 */
class ServiceConsoleConnectionPortTest extends TestCase
{
    use DatabaseTransactions;

    /** @var list<int> */
    private array $productIds = [];

    /** @var list<int> */
    private array $serviceIds = [];

    /** @var list<int> */
    private array $userIds = [];

    protected function tearDown(): void
    {
        DB::connection()->table('services')->whereIn('id', $this->serviceIds)->delete();
        DB::connection()->table('products')->whereIn('id', $this->productIds)->delete();
        DB::connection()->table('users')->whereIn('id', $this->userIds)->delete();

        parent::tearDown();
    }

    #[Test]
    public function machine_service_falls_back_to_ssh_port_22_when_upstream_omits_port(): void
    {
        $suffix = bin2hex(random_bytes(4));
        [$user, $service] = $this->createService($suffix, ['service_type_code' => ProductType::VPS, 'product_type' => ProductType::CLOUD_SERVER]);

        app(ServiceDetailService::class)->syncServiceFromRemote($service->fresh(), [
            'domainstatus' => 'Active',
            'username' => 'root',
            'password' => 'Ztest'.$suffix,
            'dedicatedip' => '203.0.113.'.random_int(2, 250),
        ]);

        $detail = app(ServiceDetailService::class)->getDetailForUser($user, (int) $service->id);

        $this->assertSame(22, (int) ($detail['connection']['port'] ?? 0));
        $this->assertSame('root', (string) ($detail['connection']['username'] ?? ''));
    }

    #[Test]
    public function panel_service_keeps_zero_port_when_upstream_omits_it(): void
    {
        $suffix = bin2hex(random_bytes(4));
        [$user, $service] = $this->createService($suffix, ['service_type_code' => ProductType::HOSTING, 'product_type' => ProductType::WEB_HOSTING]);

        app(ServiceDetailService::class)->syncServiceFromRemote($service->fresh(), [
            'domainstatus' => 'Active',
            'username' => 'panel'.$suffix,
        ]);

        $detail = app(ServiceDetailService::class)->getDetailForUser($user, (int) $service->id);

        $this->assertSame(0, (int) ($detail['connection']['port'] ?? -1));
    }

    #[Test]
    public function upstream_port_wins_over_the_default(): void
    {
        $suffix = bin2hex(random_bytes(4));
        [$user, $service] = $this->createService($suffix, ['service_type_code' => ProductType::VPS, 'product_type' => ProductType::CLOUD_SERVER]);

        app(ServiceDetailService::class)->syncServiceFromRemote($service->fresh(), [
            'domainstatus' => 'Active',
            'username' => 'root',
            'port' => 2222,
            'dedicatedip' => '203.0.113.9',
        ]);

        $detail = app(ServiceDetailService::class)->getDetailForUser($user, (int) $service->id);

        $this->assertSame(2222, (int) ($detail['connection']['port'] ?? 0));
    }

    /**
     * @param  array<string, mixed>  $productOverrides
     * @return array{0:User, 1:Service}
     */
    private function createService(string $suffix, array $productOverrides): array
    {
        $user = User::query()->create([
            'email' => 'port-'.$suffix.'@example.com',
            'password' => 'Temp@123456',
            'phone' => '13'.str_pad((string) random_int(0, 999999999), 9, '0', STR_PAD_LEFT),
            'status' => 1,
            'nickname' => 'Port Test',
        ]);
        $this->userIds[] = (int) $user->id;

        $product = Product::query()->create(array_merge([
            'custom_display_name' => 'Port Product '.$suffix,
            'service_type_code' => ProductType::VPS,
            'product_type' => ProductType::CLOUD_SERVER,
            'pricing' => ['monthly' => '10.00'],
            'setup_fee' => '0.00',
            'config_options' => [],
            'purchase_requires' => [],
            'stock' => -1,
            'status' => 1,
            'auto_setup' => 0,
        ], $productOverrides));
        $this->productIds[] = (int) $product->id;

        $service = Service::query()->create([
            'user_id' => (int) $user->id,
            'product_id' => (int) $product->id,
            'name' => 'Port Service '.$suffix,
            'domain' => 'port-'.$suffix.'.example.com',
            'billing_cycle' => 'monthly',
            'amount' => '10.00',
            'status' => ServiceStatus::ACTIVE,
            'locked_pricing' => [],
            'provision_data' => [
                'connection_secret' => Crypt::encryptString((string) json_encode([
                    'hostname' => 'port-'.$suffix.'.example.com',
                    'username' => 'root',
                    'password' => 'Ztest'.$suffix,
                    'port' => 0,
                    'internal_ip' => '',
                ])),
            ],
            'expires_at' => now()->addMonth(),
            'auto_renew' => 0,
        ]);
        $this->serviceIds[] = (int) $service->id;

        return [$user, $service];
    }
}
