<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Constants\ProductType;
use App\Constants\ServiceStatus;
use App\Models\Product;
use App\Models\Service;
use App\Models\User;
use App\Services\ZjmfUpstream\DcimService;
use Carbon\Carbon;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * 上游服务商 API（被魔方财务对接）认证与按钮分发回归测试。
 *
 * 重点回归：登录账号条件必须与鉴权中间件一致（status=1 + api_open=1）。
 * 登录放行而中间件拒绝会形成「登录成功 → 每次请求 405 → 魔方财务强制重登 →
 * 再 405」的死循环，下游表现为对接持续 405。
 */
class ZjmfUpstreamApiTest extends TestCase
{
    /** @var list<int> */
    private array $productIds = [];

    /** @var list<int> */
    private array $serviceIds = [];

    /** @var list<int> */
    private array $userIds = [];

    /**
     * 共享测试库上的既有列表类断言按索引取值，残留行会污染它们。
     */
    protected function tearDown(): void
    {
        DB::connection()->table('services')->whereIn('id', $this->serviceIds)->delete();
        DB::connection()->table('products')->whereIn('id', $this->productIds)->delete();
        DB::connection()->table('users')->whereIn('id', $this->userIds)->delete();

        parent::tearDown();
    }

    #[Test]
    public function login_issues_jwt_for_enabled_api_account(): void
    {
        $suffix = bin2hex(random_bytes(4));
        $this->createApiUser($suffix, ['status' => 1, 'api_open' => 1]);

        $this->postJson('/api/v2/zjmf/zjmf_api_login', [
            'username' => 'zjmfapi_'.$suffix,
            'password' => 'ZjmfApi@123456',
        ])
            ->assertOk()
            ->assertJsonPath('status', 200)
            ->assertJsonStructure(['jwt']);
    }

    #[Test]
    public function login_rejects_disabled_account_to_avoid_permanent_405_loop(): void
    {
        $suffix = bin2hex(random_bytes(4));
        $this->createApiUser($suffix, ['status' => 0, 'api_open' => 1]);

        $this->postJson('/api/v2/zjmf/zjmf_api_login', [
            'username' => 'zjmfapi_'.$suffix,
            'password' => 'ZjmfApi@123456',
        ])
            ->assertOk()
            ->assertJsonPath('status', 400);
    }

    #[Test]
    public function login_rejects_account_without_api_access(): void
    {
        $suffix = bin2hex(random_bytes(4));
        $this->createApiUser($suffix, ['status' => 1, 'api_open' => 0]);

        $this->postJson('/api/v2/zjmf/zjmf_api_login', [
            'username' => 'zjmfapi_'.$suffix,
            'password' => 'ZjmfApi@123456',
        ])
            ->assertOk()
            ->assertJsonPath('status', 400);
    }

    #[Test]
    public function business_request_with_invalid_jwt_returns_405(): void
    {
        $this->getJson('/api/v2/zjmf/user_info', ['Authorization' => 'Bearer invalid.token.value'])
            ->assertOk()
            ->assertJsonPath('status', 405);
    }

    #[Test]
    public function business_request_with_valid_jwt_passes_authentication(): void
    {
        $suffix = bin2hex(random_bytes(4));
        $user = $this->createApiUser($suffix, ['status' => 1, 'api_open' => 1]);

        $jwt = $this->postJson('/api/v2/zjmf/zjmf_api_login', [
            'username' => 'zjmfapi_'.$suffix,
            'password' => 'ZjmfApi@123456',
        ])->assertOk()->json('jwt');

        $this->assertIsString($jwt) && $jwt !== '';

        $this->getJson('/api/v2/zjmf/user_info', ['Authorization' => 'Bearer '.$jwt])
            ->assertOk()
            ->assertJsonPath('status', 200)
            ->assertJsonPath('user.id', (int) $user->id);
    }

    #[Test]
    public function provision_button_dispatches_power_actions(): void
    {
        $suffix = bin2hex(random_bytes(4));
        $user = $this->createApiUser($suffix, ['status' => 1, 'api_open' => 1]);
        $jwt = $this->postJson('/api/v2/zjmf/zjmf_api_login', [
            'username' => 'zjmfapi_'.$suffix,
            'password' => 'ZjmfApi@123456',
        ])->assertOk()->json('jwt');

        $dcim = $this->createMock(DcimService::class);
        $dcim->method('on')->willReturn(['status' => 200, 'msg' => '操作成功']);
        $this->swap(DcimService::class, $dcim);

        $this->postJson('/api/v2/zjmf/provision/button', ['id' => 321, 'func' => 'on'], ['Authorization' => 'Bearer '.$jwt])
            ->assertOk()
            ->assertJsonPath('status', 200);
        $dcim->expects($this->never())->method('off');
    }

    #[Test]
    public function provision_button_rejects_unknown_func(): void
    {
        $suffix = bin2hex(random_bytes(4));
        $this->createApiUser($suffix, ['status' => 1, 'api_open' => 1]);
        $jwt = $this->postJson('/api/v2/zjmf/zjmf_api_login', [
            'username' => 'zjmfapi_'.$suffix,
            'password' => 'ZjmfApi@123456',
        ])->assertOk()->json('jwt');

        $this->postJson('/api/v2/zjmf/provision/button', ['id' => 321, 'func' => 'flow'], ['Authorization' => 'Bearer '.$jwt])
            ->assertOk()
            ->assertJsonPath('status', 400);
    }

    #[Test]
    public function host_header_exposes_custom_area_and_login_info_for_cdn_products(): void
    {
        $suffix = bin2hex(random_bytes(4));
        $user = $this->createApiUser($suffix, ['status' => 1, 'api_open' => 1]);
        $service = $this->createCdnService($user, $suffix);
        $jwt = $this->apiJwt($suffix);

        $response = $this->getJson(
            '/api/v2/zjmf/host/header?host_id='.(int) $service->id,
            ['Authorization' => 'Bearer '.$jwt],
        )->assertOk()->assertJsonPath('status', 200);

        // 自定义 tab：CDN 面板型产品下发「配置信息」区域，格式 [{key,name}]
        $response->assertJsonPath('data.module_client_area.0.key', 'info')
            ->assertJsonPath('data.module_client_area.0.name', '配置信息');

        // 登录信息区：魔方财务模板按 [{name,value}] 渲染
        $mainArea = $response->json('data.module_client_main_area');
        $this->assertIsArray($mainArea);
        $this->assertNotEmpty($mainArea);
        $values = array_column($mainArea, 'value');
        $this->assertContains('cdn-panel-'.$suffix.'.example.test', $values);
        $this->assertContains('cdnuser'.$suffix, $values);
    }

    #[Test]
    public function custom_content_endpoint_returns_html_for_declared_area(): void
    {
        $suffix = bin2hex(random_bytes(4));
        $user = $this->createApiUser($suffix, ['status' => 1, 'api_open' => 1]);
        $service = $this->createCdnService($user, $suffix);
        $jwt = $this->apiJwt($suffix);

        $response = $this->postJson(
            '/api/v2/zjmf/zjmf_api/provision/custom/content',
            ['id' => (int) $service->id, 'key' => 'info'],
            ['Authorization' => 'Bearer '.$jwt],
        )->assertOk()->assertJsonPath('status', 200);

        $html = (string) $response->json('data.html');
        $this->assertNotSame('', $html);
        $this->assertStringContainsString('cdn-panel-'.$suffix.'.example.test', $html);
        $this->assertStringContainsString('/api/v2/zjmf/provision/custom/'.(int) $service->id, $html);
    }

    #[Test]
    public function custom_content_endpoint_rejects_undeclared_area(): void
    {
        $suffix = bin2hex(random_bytes(4));
        $user = $this->createApiUser($suffix, ['status' => 1, 'api_open' => 1]);
        $service = $this->createCdnService($user, $suffix);
        $jwt = $this->apiJwt($suffix);

        // key 必须来自 host/header 下发的 module_client_area，未声明的区域不返回内容
        $this->postJson(
            '/api/v2/zjmf/zjmf_api/provision/custom/content',
            ['id' => (int) $service->id, 'key' => 'not_declared'],
            ['Authorization' => 'Bearer '.$jwt],
        )->assertOk()->assertJsonPath('status', 400);
    }

    #[Test]
    public function host_header_keeps_cloud_products_without_custom_area(): void
    {
        $suffix = bin2hex(random_bytes(4));
        $user = $this->createApiUser($suffix, ['status' => 1, 'api_open' => 1]);
        $service = $this->createServiceWithProductType($user, $suffix, 'cloud_server');
        $jwt = $this->apiJwt($suffix);

        // 机房型产品走 /dcim/* 控制接口，不下发自定义区域，避免下游渲染空面板
        $this->getJson(
            '/api/v2/zjmf/host/header?host_id='.(int) $service->id,
            ['Authorization' => 'Bearer '.$jwt],
        )->assertOk()
            ->assertJsonPath('status', 200)
            ->assertJsonPath('data.module_client_area', [])
            ->assertJsonPath('data.module_client_main_area', []);
    }

    #[Test]
    public function host_header_reports_traffic_usage_flag_from_quota(): void
    {
        $suffix = bin2hex(random_bytes(4));
        $user = $this->createApiUser($suffix, ['status' => 1, 'api_open' => 1]);

        // 有流量配额 -> 下游展示流量用量；无配额 -> 不展示
        $withQuota = $this->createServiceWithProductType($user, $suffix.'q', 'cloud_server', ['bw_limit' => 500]);
        $withoutQuota = $this->createServiceWithProductType($user, $suffix.'n', 'cloud_server');
        $jwt = $this->apiJwt($suffix);

        $this->getJson(
            '/api/v2/zjmf/host/header?host_id='.(int) $withQuota->id,
            ['Authorization' => 'Bearer '.$jwt],
        )->assertOk()->assertJsonPath('data.host_data.show_traffic_usage', true);

        $this->getJson(
            '/api/v2/zjmf/host/header?host_id='.(int) $withoutQuota->id,
            ['Authorization' => 'Bearer '.$jwt],
        )->assertOk()->assertJsonPath('data.host_data.show_traffic_usage', false);
    }

    #[Test]
    public function provision_custom_action_is_idempotent_without_controllable_supplier(): void
    {
        $suffix = bin2hex(random_bytes(4));
        $user = $this->createApiUser($suffix, ['status' => 1, 'api_open' => 1]);
        $service = $this->createCdnService($user, $suffix);
        $jwt = $this->apiJwt($suffix);

        // 服务未接入可控供应商时幂等受理，避免下游因单点失败卡住
        $this->postJson(
            '/api/v2/zjmf/provision/custom/'.(int) $service->id,
            ['func' => 'refresh'],
            ['Authorization' => 'Bearer '.$jwt],
        )->assertOk()->assertJsonPath('status', 200);
    }

    private function apiJwt(string $suffix): string
    {
        return (string) $this->postJson('/api/v2/zjmf/zjmf_api_login', [
            'username' => 'zjmfapi_'.$suffix,
            'password' => 'ZjmfApi@123456',
        ])->assertOk()->json('jwt');
    }

    private function createCdnService(User $user, string $suffix): Service
    {
        return $this->createServiceWithProductType($user, $suffix, ProductType::CDN);
    }

    /**
     * @param  array<string, mixed>  $provisionOverrides
     */
    private function createServiceWithProductType(
        User $user,
        string $suffix,
        string $productType,
        array $provisionOverrides = [],
    ): Service {
        $product = Product::query()->create([
            'name' => 'Zjmf Upstream '.$productType.' '.$suffix,
            'product_type' => $productType,
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
            'name' => 'Zjmf Upstream Service '.$suffix,
            'domain' => 'cdn-'.$suffix.'.example.com',
            'billing_cycle' => 'monthly',
            'amount' => '30.00',
            'status' => ServiceStatus::ACTIVE,
            'locked_pricing' => [],
            'provision_data' => array_merge([
                'connection_secret' => Crypt::encryptString((string) json_encode([
                    'hostname' => 'cdn-panel-'.$suffix.'.example.test',
                    'username' => 'cdnuser'.$suffix,
                    'password' => 'CdnPass'.$suffix,
                    'port' => 8443,
                    'internal_ip' => '',
                ])),
            ], $provisionOverrides),
            'expires_at' => Carbon::parse('2026-12-20 00:00:00'),
            'auto_renew' => 0,
        ]);
        $this->serviceIds[] = (int) $service->id;

        return $service;
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function createApiUser(string $suffix, array $overrides): User
    {
        $user = User::query()->create([
            'email' => 'zjmfapi-'.$suffix.'@example.com',
            'password' => 'Temp@123456',
            'phone' => '13'.str_pad((string) random_int(0, 999999999), 9, '0', STR_PAD_LEFT),
            'status' => 1,
            'nickname' => 'Zjmf Upstream Api',
        ]);

        $user->forceFill([
            'api_open' => (int) ($overrides['api_open'] ?? 1),
            'api_username' => 'zjmfapi_'.$suffix,
            'api_password' => Hash::make('ZjmfApi@123456'),
            'status' => (int) ($overrides['status'] ?? 1),
        ])->save();
        $this->userIds[] = (int) $user->id;

        return $user->refresh();
    }
}
