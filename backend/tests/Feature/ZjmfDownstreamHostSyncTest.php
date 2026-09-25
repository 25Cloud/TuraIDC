<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Constants\ServiceStatus;
use App\Models\Product;
use App\Models\Service;
use App\Models\User;
use App\Services\Ticket\TicketUpstreamCallbackToken;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * 魔方财务上游 → 本系统（Tura 作为下游）主机状态推送接收端：POST /api/host/sync。
 *
 * 协议对齐魔方 app/zjmf.php pushHostInfo + createSign：
 *   - 验签：strtoupper(md5(json_encode(ksort(['id','token','rand_str'], SORT_STRING))))，
 *     兼容 id 的 int/string 两种 json 形式；
 *   - 载荷镜像上游权威状态（domainstatus/nextduedate/连接信息/IP/OS）。
 */
final class ZjmfDownstreamHostSyncTest extends TestCase
{
    /** @var list<int> */
    private array $userIds = [];

    /** @var list<int> */
    private array $productIds = [];

    /** @var list<int> */
    private array $serviceIds = [];

    private string $currentSuffix = '';

    protected function tearDown(): void
    {
        DB::connection()->table('services')->whereIn('id', $this->serviceIds)->delete();
        DB::connection()->table('products')->whereIn('id', $this->productIds)->delete();
        DB::connection()->table('users')->whereIn('id', $this->userIds)->delete();

        parent::tearDown();
    }

    #[Test]
    public function host_sync_push_mirrors_upstream_state_with_int_id_signature(): void
    {
        $service = $this->makeService();

        $payload = $this->basePayload((int) $service->id);
        $payload['signature'] = $this->legacySignature($payload, (int) $service->id, intId: true);

        $this->postJson('/api/host/sync', $payload)
            ->assertOk()
            ->assertJsonPath('status', 200)
            ->assertJsonPath('service_id', (int) $service->id);

        $service->refresh();
        $this->assertSame(ServiceStatus::SUSPENDED, (int) $service->status);
        $this->assertSame('资源超配', (string) $service->suspended_reason);
        $this->assertSame('203.0.113.77', (string) ($service->provision_data['dedicated_ip'] ?? ''));
        $this->assertSame(['203.0.113.77', '203.0.113.78'], array_values((array) ($service->provision_data['assigned_ips'] ?? [])));
        $this->assertSame('Ubuntu 24.04', (string) ($service->provision_data['os'] ?? ''));
        $this->assertSame('sync-'.$this->suffixLocal().'.example.test', (string) $service->domain);

        $connection = json_decode((string) Crypt::decryptString((string) $service->provision_data['connection_secret']), true);
        $this->assertSame('upstream-user', (string) ($connection['username'] ?? ''));
        $this->assertSame('NewPass@123', (string) ($connection['password'] ?? ''));
        $this->assertSame(2222, (int) ($connection['port'] ?? 0));
    }

    #[Test]
    public function host_sync_push_accepts_string_id_signature(): void
    {
        $service = $this->makeService();

        $payload = $this->basePayload((int) $service->id);
        $payload['signature'] = $this->legacySignature($payload, (int) $service->id, intId: false);

        $this->postJson('/api/host/sync', $payload)
            ->assertOk()
            ->assertJsonPath('status', 200);
    }

    #[Test]
    public function host_sync_push_rejects_invalid_signature(): void
    {
        $service = $this->makeService();

        $payload = $this->basePayload((int) $service->id);
        $payload['signature'] = strtoupper(Str::random(32));

        $this->postJson('/api/host/sync', $payload)
            ->assertOk()
            ->assertJsonPath('status', 400);

        $service->refresh();
        $this->assertSame(ServiceStatus::ACTIVE, (int) $service->status);
    }

    #[Test]
    public function host_sync_push_terminate_marks_service_cancelled(): void
    {
        $service = $this->makeService();

        $payload = $this->basePayload((int) $service->id);
        $payload['domainstatus'] = 'Deleted';
        $payload['type'] = 'terminate';
        $payload['signature'] = $this->legacySignature($payload, (int) $service->id, intId: true);

        $this->postJson('/api/host/sync', $payload)
            ->assertOk()
            ->assertJsonPath('status', 200);

        $service->refresh();
        $this->assertSame(ServiceStatus::CANCELLED, (int) $service->status);
    }

    #[Test]
    public function host_sync_push_for_missing_service_is_rejected(): void
    {
        $payload = $this->basePayload(999999999);
        $payload['signature'] = $this->legacySignature($payload, 999999999, intId: true);

        $this->postJson('/api/host/sync', $payload)
            ->assertOk()
            ->assertJsonPath('status', 400);
    }

    /**
     * @return array<string, mixed>
     */
    private function basePayload(int $serviceId): array
    {
        return [
            'id' => $serviceId,
            'host_id' => 8801,
            'domain' => 'sync-'.$this->suffixLocal().'.example.test',
            'username' => 'upstream-user',
            'password' => 'NewPass@123',
            'dedicatedip' => '203.0.113.77',
            'assignedips' => '203.0.113.77,203.0.113.78',
            'port' => 2222,
            'os' => 'Ubuntu 24.04',
            'domainstatus' => 'Suspended',
            'suspendreason' => '资源超配',
            'nextduedate' => '2026-10-25',
            'type' => 'suspend',
            'rand_str' => Str::lower(Str::random(6)),
        ];
    }

    private function legacySignature(array $payload, int $serviceId, bool $intId): string
    {
        $signed = [
            'id' => $intId ? $serviceId : (string) $serviceId,
            'token' => TicketUpstreamCallbackToken::forServiceId($serviceId),
            'rand_str' => (string) $payload['rand_str'],
        ];
        ksort($signed, SORT_STRING);

        return strtoupper(md5((string) json_encode($signed)));
    }

    private function makeService(): Service
    {
        $this->currentSuffix = '';
        $suffix = $this->suffixLocal();

        $user = User::query()->create([
            'email' => "host-sync-{$suffix}@example.test",
            'password' => 'Temp@123456',
            'phone' => '13'.str_pad((string) random_int(0, 999999999), 9, '0', STR_PAD_LEFT),
            'status' => 1,
        ]);
        $this->userIds[] = (int) $user->id;

        $product = Product::query()->create([
            'name' => 'Host Sync Product '.$suffix,
            'product_type' => 'server',
            'pricing' => ['monthly' => '30.00'],
            'setup_fee' => '0.00',
            'config_options' => [],
            'purchase_requires' => [],
            'stock' => -1,
            'status' => 1,
            'auto_setup' => 0,
        ]);
        $this->productIds[] = (int) $product->id;

        $service = Service::query()->create([
            'user_id' => (int) $user->id,
            'product_id' => (int) $product->id,
            'name' => 'Host Sync Service '.$suffix,
            'domain' => 'sync-'.$suffix.'.example.test',
            'billing_cycle' => 'monthly',
            'amount' => '30.00',
            'status' => ServiceStatus::ACTIVE,
            'locked_pricing' => [],
            'provision_data' => [
                'upstream_host_id' => 8800,
                'dedicated_ip' => '203.0.113.9',
                'assigned_ips' => ['203.0.113.9'],
                'os' => 'Debian 12',
                'connection_secret' => Crypt::encryptString((string) json_encode([
                    'hostname' => 'sync-'.$suffix.'.example.test',
                    'username' => 'root',
                    'password' => 'OldPass@123',
                    'port' => 22,
                ])),
            ],
            'expires_at' => now()->addDays(3),
            'auto_renew' => 0,
        ]);
        $this->serviceIds[] = (int) $service->id;

        return $service;
    }

    private function suffixLocal(): string
    {
        if ($this->currentSuffix === '') {
            $this->currentSuffix = strtolower(bin2hex(random_bytes(5)));
        }

        return $this->currentSuffix;
    }
}
