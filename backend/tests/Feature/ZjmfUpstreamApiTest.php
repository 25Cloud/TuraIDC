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
     *
     * 删除顺序按外键依赖：结算类用例会生成账单与订单（invoice 引用 product/order），
     * 必须先清账单与订单，否则 products 删除会被外键阻塞。
     */
    protected function tearDown(): void
    {
        if ($this->userIds !== []) {
            DB::connection()->table('invoices')->whereIn('user_id', $this->userIds)->delete();
            DB::connection()->table('orders')->whereIn('user_id', $this->userIds)->delete();
        }

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

    /**
     * 魔方财务对 zjmf_api 产品的全部实例操作都走 /provision/default + func 分发
     * （Host.php: on/off/reboot/hard_off/hard_reboot/vnc/status/reinstall/crack_pass/rescueSystem）。
     * 此前未知 func 一律返回 status=200 静默受理，下游判定成功但什么都没做。
     */
    #[Test]
    public function provision_default_dispatches_power_actions_to_local_console(): void
    {
        $suffix = bin2hex(random_bytes(4));
        $user = $this->createApiUser($suffix, ['status' => 1, 'api_open' => 1]);
        $jwt = $this->apiJwt($suffix);
        $service = $this->createCdnService($user, $suffix);

        $power = $this->createMock(\App\Services\ClientServiceConsole\ServicePowerService::class);
        $power->method('powerActionForUser')->willReturn(['message' => '指令已发送']);
        $this->swap(\App\Services\ClientServiceConsole\ServicePowerService::class, $power);

        foreach (['on', 'off', 'reboot', 'hard_off', 'hard_reboot'] as $func) {
            $this->postJson('/api/v2/zjmf/provision/default', [
                'id' => (int) $service->id,
                'func' => $func,
            ], ['Authorization' => 'Bearer '.$jwt])
                ->assertOk()
                ->assertJsonPath('status', 200);
        }
    }

    #[Test]
    public function provision_default_status_returns_power_state_payload(): void
    {
        $suffix = bin2hex(random_bytes(4));
        $user = $this->createApiUser($suffix, ['status' => 1, 'api_open' => 1]);
        $jwt = $this->apiJwt($suffix);
        $service = $this->createCdnService($user, $suffix);

        $power = $this->createMock(\App\Services\ClientServiceConsole\ServicePowerService::class);
        $power->method('getModuleStatusForUser')->willReturn([
            'status' => 'on',
            'description' => '开机',
            'is_finished' => true,
            'is_success' => true,
        ]);
        $this->swap(\App\Services\ClientServiceConsole\ServicePowerService::class, $power);

        // 魔方读取 data.status + data.des（Host.php status 分支）
        $this->postJson('/api/v2/zjmf/provision/default', [
            'id' => (int) $service->id,
            'func' => 'status',
        ], ['Authorization' => 'Bearer '.$jwt])
            ->assertOk()
            ->assertJsonPath('status', 200)
            ->assertJsonPath('data.status', 'on')
            ->assertJsonPath('data.des', '开机');
    }

    #[Test]
    public function provision_default_reinstall_requires_os_parameter(): void
    {
        $suffix = bin2hex(random_bytes(4));
        $user = $this->createApiUser($suffix, ['status' => 1, 'api_open' => 1]);
        $jwt = $this->apiJwt($suffix);
        $service = $this->createCdnService($user, $suffix);

        // 缺 os：明确失败，不再静默受理（走 HTTP 全链路验证协议返回）
        $this->postJson('/api/v2/zjmf/provision/default', [
            'id' => (int) $service->id,
            'func' => 'reinstall',
        ], ['Authorization' => 'Bearer '.$jwt])
            ->assertOk()
            ->assertJsonPath('status', 400);

        // 带 os：直接验证 service 层分发（HTTP 层依赖已由上一个请求覆盖）
        $power = $this->createMock(\App\Services\ClientServiceConsole\ServicePowerService::class);
        $power->expects($this->once())
            ->method('reinstallForUser')
            ->with($this->anything(), (int) $service->id, ['os_id' => '12'])
            ->willReturn(['message' => '重装系统任务已提交']);

        $serviceLayer = new \App\Services\ZjmfUpstream\UpstreamProvisionService(
            $this->app->make(\App\Services\Provisioning\ProvisionService::class),
            $power,
        );

        $result = $serviceLayer->execute($user, [
            'id' => (int) $service->id,
            'func' => 'reinstall',
            'os' => '12',
        ]);

        $this->assertSame(200, (int) ($result['status'] ?? 0));
    }

    #[Test]
    public function provision_default_rescue_accepts_both_spellings(): void
    {
        $suffix = bin2hex(random_bytes(4));
        $user = $this->createApiUser($suffix, ['status' => 1, 'api_open' => 1]);
        $jwt = $this->apiJwt($suffix);
        $service = $this->createCdnService($user, $suffix);

        $power = $this->createMock(\App\Services\ClientServiceConsole\ServicePowerService::class);
        $power->method('rescueForUser')->willReturn(['message' => '救援模式指令已提交']);
        $this->swap(\App\Services\ClientServiceConsole\ServicePowerService::class, $power);

        // 魔方客户端发 rescueSystem；参考服务端拼写为 rescue_system
        foreach (['rescueSystem', 'rescue_system'] as $func) {
            $this->postJson('/api/v2/zjmf/provision/default', [
                'id' => (int) $service->id,
                'func' => $func,
                'system' => '2',
            ], ['Authorization' => 'Bearer '.$jwt])
                ->assertOk()
                ->assertJsonPath('status', 200);
        }
    }

    #[Test]
    public function provision_default_rejects_unknown_func_instead_of_silent_success(): void
    {
        $suffix = bin2hex(random_bytes(4));
        $user = $this->createApiUser($suffix, ['status' => 1, 'api_open' => 1]);
        $jwt = $this->apiJwt($suffix);
        $service = $this->createCdnService($user, $suffix);

        $this->postJson('/api/v2/zjmf/provision/default', [
            'id' => (int) $service->id,
            'func' => 'changePackage',
        ], ['Authorization' => 'Bearer '.$jwt])
            ->assertOk()
            ->assertJsonPath('status', 400);
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

    /**
     * 魔方财务管理端「上游信息」直接读取这些字段且没有空值兜底，
     * 缺失会显示空白并产生 PHP 未定义索引告警。
     */
    #[Test]
    public function host_header_provides_upstream_admin_panel_fields(): void
    {
        $suffix = bin2hex(random_bytes(4));
        $user = $this->createApiUser($suffix, ['status' => 1, 'api_open' => 1]);
        $service = $this->createCdnService($user, $suffix);
        $jwt = $this->apiJwt($suffix);

        $response = $this->getJson(
            '/api/v2/zjmf/host/header?host_id='.(int) $service->id,
            ['Authorization' => 'Bearer '.$jwt],
        )->assertOk()->assertJsonPath('status', 200);

        // ClientsServicesController 读取的字段必须全部存在（无 ?? 兜底）
        foreach ([
            'regdate', 'domainstatus_desc', 'firstpaymentamount', 'firstpaymentamount_desc',
            'amount_desc', 'promo_code', 'payment', 'payment_zh',
            'billingcycle', 'billingcycle_desc', 'group', 'ocreate_time',
        ] as $key) {
            $this->assertArrayHasKey(
                $key,
                (array) $response->json('data.host_data'),
                "host_data 缺少下游管理端读取的字段：{$key}"
            );
        }

        $response->assertJsonPath('data.host_data.domainstatus', 'Active')
            ->assertJsonPath('data.host_data.domainstatus_desc', '正常')
            ->assertJsonPath('data.host_data.billingcycle', 'monthly')
            ->assertJsonPath('data.host_data.billingcycle_desc', '月付')
            ->assertJsonPath('data.host_data.amount_desc', '30.00');
    }

    /**
     * DCIM 按钮可用性：只声明真正实现的能力，未覆盖的（KVM/iKVM/BMC/流量图）标 off，
     * 避免下游渲染出点了必失败的按钮。
     */
    #[Test]
    public function host_header_declares_dcim_auth_capabilities(): void
    {
        $suffix = bin2hex(random_bytes(4));
        $user = $this->createApiUser($suffix, ['status' => 1, 'api_open' => 1]);
        $service = $this->createServiceWithProductType($user, $suffix, 'cloud_server');
        $jwt = $this->apiJwt($suffix);

        $response = $this->getJson(
            '/api/v2/zjmf/host/header?host_id='.(int) $service->id,
            ['Authorization' => 'Bearer '.$jwt],
        )->assertOk()->assertJsonPath('status', 200);

        $auth = (array) $response->json('data.dcim.auth');
        $this->assertNotEmpty($auth, 'dcim.auth 必须下发，否则下游按全 off 处理');
        foreach (['on', 'off', 'reboot', 'novnc', 'reinstall', 'rescue', 'crack_pass', 'kvm', 'ikvm', 'bmc', 'traffic'] as $action) {
            $this->assertArrayHasKey($action, $auth, "dcim.auth 缺少动作：{$action}");
        }

        // 供应商协议未覆盖的动作必须 off
        $this->assertSame('off', $auth['kvm']);
        $this->assertSame('off', $auth['ikvm']);
        $this->assertSame('off', $auth['bmc']);
        $this->assertSame('off', $auth['traffic']);

        // 重装磁盘格式化开关存在（下游按它决定是否展示该选项）
        $this->assertArrayHasKey('reinstall_format_data_disk', (array) $response->json('data'));
        $this->assertArrayHasKey('flow_packet_use_list', (array) $response->json('data.dcim'));
    }

    /**
     * 下游在魔方下单时填的主机名与配置项必须随 settle 进入本地下单，
     * 否则客户选择被静默丢弃、只能按默认值开通。
     */
    #[Test]
    public function cart_settle_passes_downstream_hostname_and_config_options(): void
    {
        $suffix = bin2hex(random_bytes(4));
        $user = $this->createApiUser($suffix, ['status' => 1, 'api_open' => 1]);
        $jwt = $this->apiJwt($suffix);

        $product = Product::query()->create([
            'name' => 'Zjmf Settle Configurable '.$suffix,
            'product_type' => 'server',
            'pricing' => ['monthly' => '30.00'],
            'setup_fee' => '0.00',
            'config_options' => [
                [
                    'field' => 'cpu',
                    'option_type' => 6,
                    'sub' => [
                        ['id' => '2', 'option_name_first' => '2', 'option_name' => '2核', 'hidden' => 0],
                    ],
                ],
            ],
            'purchase_requires' => [],
            'stock' => -1,
            'status' => 1,
            'auto_setup' => 0,
        ]);
        $this->productIds[] = (int) $product->id;

        $response = $this->postJson('/api/v2/zjmf/cart/settle', [
            'cart_data' => [
                'pid' => (int) $product->id,
                'billingcycle' => 'monthly',
                'qty' => 1,
                'host' => 'downstream-chosen-'.$suffix,
                'password' => 'DownstreamPass123',
                // 键为配置项子项 id（本系统下发的 upstream_id）
                'configoptions' => ['2' => 2],
            ],
        ], ['Authorization' => 'Bearer '.$jwt])
            ->assertOk()
            ->assertJsonPath('status', 200);

        $invoiceId = (int) $response->json('data.invoiceid');
        $this->assertGreaterThan(0, $invoiceId);

        $invoice = \App\Models\Invoice::query()->findOrFail($invoiceId);

        // 主机名归一化后（点转横杠）落进订单配置快照
        $snapshot = (array) $invoice->config_snapshot;
        $this->assertSame('downstream-chosen-'.$suffix, (string) ($snapshot['hostname'] ?? ''));

        // 配置项按 sub id 反查到字段名 cpu 并保留取值
        $order = \App\Models\Order::query()->findOrFail((int) $invoice->order_id);
        $orderSnapshot = (array) $order->config_snapshot;
        $this->assertSame('downstream-chosen-'.$suffix, (string) ($orderSnapshot['hostname'] ?? ''));
        $this->assertSame(2, (int) (($orderSnapshot['cpu'] ?? null) ?? 0), '下游选择的 CPU 配置应传导到订单');
    }

    /**
     * 下游管理端手工改绑上游主机后需要重新登记回推目标；
     * 此前该端点为 404（且返回 {code,message} 结构，违反固定 200 约定）。
     */
    #[Test]
    public function host_setdownstream_registers_push_target(): void
    {
        $suffix = bin2hex(random_bytes(4));
        $user = $this->createApiUser($suffix, ['status' => 1, 'api_open' => 1]);
        $service = $this->createCdnService($user, $suffix);
        $jwt = $this->apiJwt($suffix);

        $this->postJson('/api/v2/zjmf/host/setdownstream', [
            'id' => (int) $service->id,
            'pid' => 100,
            'downstream_url' => 'https://downstream-'.$suffix.'.example.test',
            'downstream_token' => str_repeat('a', 32),
            'downstream_id' => 4567,
        ], ['Authorization' => 'Bearer '.$jwt])
            ->assertOk()
            ->assertJsonPath('status', 200);

        $binding = DB::connection()->table('zjmf_upstream_bindings')
            ->where('user_id', (int) $user->id)
            ->where('service_id', (int) $service->id)
            ->first();

        $this->assertNotNull($binding, '应登记下游回推绑定');
        $this->assertSame('https://downstream-'.$suffix.'.example.test', (string) $binding->downstream_url);
        $this->assertSame(4567, (int) $binding->downstream_id);

        // 非法协议必须拒绝，避免登记出不可用的推送目标
        $this->postJson('/api/v2/zjmf/host/setdownstream', [
            'id' => (int) $service->id,
            'downstream_url' => 'ftp://bad.example.test',
            'downstream_token' => 'x',
        ], ['Authorization' => 'Bearer '.$jwt])
            ->assertOk()
            ->assertJsonPath('status', 400);
    }

    /**
     * 下游管理端三个面板端点此前均为 404，管理端「上游余额/上游信息/下游汇总」空白。
     */
    #[Test]
    public function admin_panels_return_protocol_payloads(): void
    {
        $suffix = bin2hex(random_bytes(4));
        $user = $this->createApiUser($suffix, ['status' => 1, 'api_open' => 1]);
        $service = $this->createCdnService($user, $suffix);
        $jwt = $this->apiJwt($suffix);

        $this->getJson('/api/v2/zjmf/cart/credit', ['Authorization' => 'Bearer '.$jwt])
            ->assertOk()
            ->assertJsonPath('status', 200)
            ->assertJsonStructure(['data' => ['balance', 'account' => ['balance', 'currency' => ['code', 'prefix']]]]);

        $this->getJson('/api/v2/zjmf/cart/hostinfo?hostid='.(int) $service->id, ['Authorization' => 'Bearer '.$jwt])
            ->assertOk()
            ->assertJsonPath('status', 200)
            ->assertJsonPath('data.host_data.id', (int) $service->id);

        $this->getJson('/api/v2/zjmf/cart/summary', ['Authorization' => 'Bearer '.$jwt])
            ->assertOk()
            ->assertJsonPath('status', 200)
            ->assertJsonStructure(['data' => ['client' => ['host_count', 'active_count', 'agent_count']]]);
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
