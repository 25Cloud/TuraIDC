<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Constants\ServiceStatus;
use App\Models\Product;
use App\Models\Service;
use App\Models\User;
use App\Models\ZjmfUpstreamBinding;
use App\Services\ZjmfUpstream\ZjmfDownstreamPushService;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * 上游 → 下游（魔方财务）状态推送：POST {downstream_url}/api/host/sync。
 *
 * 此前全仓只有下方接收端（PushService 的验签），没有任何发送方，
 * 上游暂停/到期/删除与续费结果无法主动同步给魔方财务。
 */
class ZjmfDownstreamPushTest extends TestCase
{
    /** @var list<int> */
    private array $userIds = [];

    /** @var list<int> */
    private array $productIds = [];

    /** @var list<int> */
    private array $serviceIds = [];

    protected function tearDown(): void
    {
        if ($this->serviceIds !== []) {
            DB::connection()->table('zjmf_upstream_bindings')->whereIn('service_id', $this->serviceIds)->delete();
        }
        DB::connection()->table('services')->whereIn('id', $this->serviceIds)->delete();
        DB::connection()->table('products')->whereIn('id', $this->productIds)->delete();
        DB::connection()->table('users')->whereIn('id', $this->userIds)->delete();

        parent::tearDown();
    }

    #[Test]
    public function pushes_signed_payload_to_downstream_host_sync(): void
    {
        Http::fake(['*' => Http::response(['status' => 200, 'msg' => '更新成功'])]);

        $token = str_repeat('d', 32);
        [$service, $binding] = $this->makeServiceWithBinding($token);

        $delivered = app(ZjmfDownstreamPushService::class)->pushForService(
            $service,
            ZjmfDownstreamPushService::TYPE_SUSPEND
        );

        $this->assertTrue($delivered);

        Http::assertSent(function ($request) use ($binding, $token): bool {
            if (! str_contains($request->url(), '/api/host/sync')) {
                return false;
            }

            $data = (array) $request->data();

            // id 必须是下游的 host id，本系统服务 id 放 host_id
            if ((int) ($data['id'] ?? 0) !== (int) $binding->downstream_id) {
                return false;
            }
            if ((string) ($data['domainstatus'] ?? '') !== 'Suspended') {
                return false;
            }
            if ((string) ($data['type'] ?? '') !== 'suspend') {
                return false;
            }

            // 签名必须与下游 validateSign 口径一致：
            // strtoupper(md5(json_encode(ksort(['id','token','rand_str'], SORT_STRING))))
            $expected = ['id' => (int) $data['id'], 'token' => $token, 'rand_str' => (string) $data['rand_str']];
            ksort($expected, SORT_STRING);

            return strtoupper(md5((string) json_encode($expected))) === (string) ($data['signature'] ?? '');
        });
    }

    #[Test]
    public function skips_silently_when_service_has_no_downstream_binding(): void
    {
        Http::fake();

        [$service] = $this->makeServiceWithBinding(str_repeat('e', 32), withBinding: false);

        $delivered = app(ZjmfDownstreamPushService::class)->pushForService($service, 'create');

        $this->assertFalse($delivered);
        Http::assertNothingSent();
    }

    #[Test]
    public function downstream_failure_does_not_throw(): void
    {
        // 下游不可达 / 返回业务失败时只记日志，不影响主流程
        Http::fake(['*' => Http::response(['status' => 400, 'msg' => '签名验证失败'])]);

        [$service] = $this->makeServiceWithBinding(str_repeat('f', 32));

        $delivered = app(ZjmfDownstreamPushService::class)->pushForService($service, 'create');

        $this->assertFalse($delivered);
    }

    /**
     * @return array{0: Service, 1: ZjmfUpstreamBinding}
     */
    private function makeServiceWithBinding(string $token, bool $withBinding = true): array
    {
        $suffix = bin2hex(random_bytes(4));

        $user = User::query()->create([
            'email' => "zjmf-push-{$suffix}@example.test",
            'password' => 'Temp@123456',
            'phone' => '13'.str_pad((string) random_int(0, 999999999), 9, '0', STR_PAD_LEFT),
            'status' => 1,
        ]);
        $this->userIds[] = (int) $user->id;

        $product = Product::query()->create([
            'name' => 'Zjmf Push Product '.$suffix,
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
            'name' => 'Zjmf Push Service '.$suffix,
            'domain' => 'push-'.$suffix.'.example.test',
            'billing_cycle' => 'monthly',
            'amount' => '30.00',
            'status' => ServiceStatus::SUSPENDED,
            'suspended_reason' => '到期未续费',
            'locked_pricing' => [],
            'provision_data' => [
                'dedicated_ip' => '203.0.113.9',
                'assigned_ips' => ['203.0.113.9'],
                'os' => 'Debian 12',
                'connection_secret' => Crypt::encryptString((string) json_encode([
                    'hostname' => 'push-'.$suffix.'.example.test',
                    'username' => 'root',
                    'password' => 'PushPass123',
                    'port' => 22,
                ])),
            ],
            'expires_at' => now()->addDays(10),
            'auto_renew' => 0,
        ]);
        $this->serviceIds[] = (int) $service->id;

        $binding = ZjmfUpstreamBinding::query()->create([
            'user_id' => (int) $user->id,
            'service_id' => (int) $service->id,
            'downstream_url' => $withBinding ? 'https://downstream-'.$suffix.'.example.test' : '',
            'downstream_token' => $token,
            'downstream_id' => random_int(1000, 9999),
            'domain' => 'push-'.$suffix.'.example.test',
        ]);

        return [$service, $binding];
    }
}
