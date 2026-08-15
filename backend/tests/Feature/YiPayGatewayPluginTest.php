<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Constants\PaymentGatewayCode;
use App\Constants\PaymentStatus;
use App\Exceptions\BusinessException;
use App\Models\AccountTransaction;
use App\Models\IntegrationPlugin;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\User;
use App\Services\Integrations\Payments\Data\PaymentRefundRequest;
use App\Services\Integrations\Payments\PaymentGatewayManager;
use App\Services\Integrations\Payments\PaymentGatewayRegistry;
use App\Services\Integrations\Plugins\PluginConfigRepository;
use App\Services\Integrations\Plugins\PluginFileLoader;
use App\Services\Integrations\Plugins\PluginInstaller;
use App\Services\Integrations\Plugins\PluginRuntimeRegistry;
use App\Services\Integrations\Plugins\PluginScanner;
use TuraIDC\Plugins\Gateways\YiPay\Lib\YiPayClient;
use TuraIDC\Plugins\Gateways\YiPay\YiPayPlugin;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class YiPayGatewayPluginTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->ensurePluginTables();
        $this->cleanPluginTables();
        $this->forgetPaymentRuntime();
        config([
            'app.name' => '财务测试',
            'app.url' => 'https://api.example.test',
            'app.frontend_url' => 'https://pay.example.test',
            'app.client_console_url' => 'https://console.example.test',
        ]);
    }

    protected function tearDown(): void
    {
        $this->cleanPluginTables();
        $this->forgetPaymentRuntime();

        parent::tearDown();
    }

    public function test_yipay_manifest_exposes_payment_gateway_config(): void
    {
        $manifest = app(PluginScanner::class)->requireManifest('payment', 'yi_pay');

        $this->assertSame(PaymentGatewayCode::YIPAY, $manifest->key);
        $this->assertSame(YiPayPlugin::class, $manifest->entryClass);
        $this->assertContains('precreate', $manifest->capabilities);
        $this->assertContains('notify_verify', $manifest->capabilities);

        $configKeys = collect($manifest->configSchema)->pluck('key')->all();
        $this->assertContains('api_endpoint', $configKeys);
        $this->assertContains('merchant_id', $configKeys);
        $this->assertContains('sign_type', $configKeys);
        $this->assertContains('merchant_key', $configKeys);
        $this->assertContains('merchant_private_key', $configKeys);
        $this->assertContains('platform_public_key', $configKeys);
        $this->assertContains('payment_types', $configKeys);
        $this->assertNotContains('payment_type', $configKeys);
        $this->assertNotContains('channel_id', $configKeys);
        $this->assertNotContains('device', $configKeys);
        $this->assertNotContains('api_base_url', $configKeys);
        $this->assertNotContains('notify_url', $configKeys);
        $this->assertNotContains('ssl_verify', $configKeys);
        $this->assertNotContains('ca_bundle', $configKeys);

        $schemaByKey = collect($manifest->configSchema)->keyBy('key');
        $this->assertNotSame('https://zpayz.cn', $schemaByKey->get('api_endpoint')['value'] ?? null);
        $this->assertSame([
            'field' => 'sign_type',
            'operator' => 'eq',
            'value' => 'MD5',
        ], $schemaByKey->get('merchant_key')['visible_when'] ?? null);
        $this->assertSame([
            'field' => 'sign_type',
            'operator' => 'eq',
            'value' => 'RSA',
        ], $schemaByKey->get('merchant_private_key')['visible_when'] ?? null);
        $this->assertSame([
            'field' => 'sign_type',
            'operator' => 'eq',
            'value' => 'RSA',
        ], $schemaByKey->get('platform_public_key')['visible_when'] ?? null);
    }

    public function test_yipay_precreate_signs_form_payload_and_normalizes_qrcode(): void
    {
        $this->loadYiPayPlugin();
        $sentPayload = [];

        Http::fake(function ($request) use (&$sentPayload) {
            parse_str($request->body(), $sentPayload);

            return Http::response([
                'code' => 1,
                'msg' => 'ok',
                'O_id' => 'ZPAY123',
                'trade_no' => 'PAY202607020001',
                'qrcode' => 'https://zpayz.cn/pay/alipay/ZPAY123/',
            ]);
        });

        $client = new YiPayClient($this->config());
        $result = $client->precreate('PAY202607020001', 12.3, '图拉云 测试订单');

        $this->assertSame('https://zpayz.cn/pay/alipay/ZPAY123/', $result['qr_code']);
        $this->assertSame('PAY202607020001', $result['out_trade_no']);
        $this->assertSame('merchant-10001', $sentPayload['pid'] ?? null);
        $this->assertSame('alipay', $sentPayload['type'] ?? null);
        $this->assertSame('PAY202607020001', $sentPayload['out_trade_no'] ?? null);
        $this->assertSame('12.30', $sentPayload['money'] ?? null);
        $this->assertSame('https://api.example.test/api/v2/client/payment/notify/yipay', $sentPayload['notify_url'] ?? null);
        $this->assertSame('https://console.example.test/client/recharge', $sentPayload['return_url'] ?? null);
        $this->assertSame('财务测试', $sentPayload['sitename'] ?? null);
        $this->assertSame('pc', $sentPayload['device'] ?? null);
        $this->assertSame('MD5', $sentPayload['sign_type'] ?? null);
        $this->assertSame($this->sign($sentPayload), $sentPayload['sign'] ?? null);
        $this->assertArrayNotHasKey('cid', $sentPayload);

        Http::assertSent(fn ($request): bool => $request->url() === 'https://zpayz.cn/mapi.php');
    }

    public function test_yipay_precreate_uses_requested_enabled_payment_type(): void
    {
        $this->loadYiPayPlugin();
        $sentPayload = [];

        Http::fake(function ($request) use (&$sentPayload) {
            parse_str($request->body(), $sentPayload);

            return Http::response([
                'code' => 1,
                'msg' => 'ok',
                'qrcode' => 'https://zpayz.cn/pay/wxpay/ZPAY123/',
            ]);
        });

        $client = new YiPayClient(array_merge($this->config(), [
            'payment_types' => ['alipay', 'wxpay'],
        ]));
        $result = $client->precreate('PAY202607020002', 20, '图拉云 微信测试订单', 'wxpay');

        $this->assertSame('https://zpayz.cn/pay/wxpay/ZPAY123/', $result['qr_code']);
        $this->assertSame('wxpay', $sentPayload['type'] ?? null);
        $this->assertSame($this->sign($sentPayload), $sentPayload['sign'] ?? null);
    }

    public function test_yipay_precreate_accepts_api_success_code_200(): void
    {
        $this->loadYiPayPlugin();

        Http::fake(fn () => Http::response([
            'code' => 200,
            'msg' => 'ok',
            'payurl' => 'https://zpayz.cn/pay/alipay/ZPAY200/',
        ]));

        $client = new YiPayClient($this->config());
        $result = $client->precreate('PAY202607020200', 20, '图拉云 支付宝测试订单', 'alipay');

        $this->assertSame('https://zpayz.cn/pay/alipay/ZPAY200/', $result['qr_code']);
    }

    public function test_yipay_rsa_precreate_signs_payload_and_resolves_custom_endpoint(): void
    {
        $this->loadYiPayPlugin();
        $keys = $this->rsaKeyPair();
        $sentPayload = [];
        $sentUrl = '';

        Http::fake(function ($request) use (&$sentPayload, &$sentUrl) {
            $sentUrl = $request->url();
            parse_str($request->body(), $sentPayload);

            return Http::response([
                'code' => 1,
                'msg' => 'ok',
                'qrcode' => 'https://gateway.example.test/pay/wxpay/ZPAY123/',
            ]);
        });

        $client = new YiPayClient(array_merge($this->config(), [
            'api_endpoint' => 'https://gateway.example.test/pay/mapi.php',
            'merchant_key' => '',
            'sign_type' => 'RSA',
            'merchant_private_key' => $keys['private_key'],
            'platform_public_key' => $keys['public_key'],
            'payment_types' => ['wxpay'],
        ]));

        $result = $client->precreate('PAY202607020004', 20, '图拉云 RSA 测试订单', 'wxpay');

        $this->assertSame('https://gateway.example.test/pay/wxpay/ZPAY123/', $result['qr_code']);
        $this->assertSame('https://gateway.example.test/pay/mapi.php', $sentUrl);
        $this->assertSame('RSA', $sentPayload['sign_type'] ?? null);
        $this->assertSame('wxpay', $sentPayload['type'] ?? null);
        $this->assertNotEmpty($sentPayload['sign'] ?? '');
        $this->assertSame(1, openssl_verify(
            $this->canonicalString($sentPayload),
            base64_decode((string) $sentPayload['sign'], true),
            $keys['public_key'],
            OPENSSL_ALGO_SHA256
        ));
    }

    public function test_yipay_rejects_unselected_payment_type(): void
    {
        $this->loadYiPayPlugin();

        $client = new YiPayClient(array_merge($this->config(), [
            'payment_types' => ['wxpay'],
        ]));

        $this->expectException(BusinessException::class);
        $this->expectExceptionMessage('当前易支付未启用该支付方式');

        $client->precreate('PAY202607020003', 20, '图拉云 支付宝测试订单', 'alipay');
    }

    public function test_yipay_is_disabled_when_api_endpoint_missing(): void
    {
        $this->loadYiPayPlugin();

        $client = new YiPayClient(array_merge($this->config(), [
            'api_endpoint' => '',
        ]));

        $this->assertFalse($client->isEnabled());

        $result = (new YiPayPlugin)->execute([
            'action' => 'payment.options',
            'config' => array_merge($this->config(), [
                'api_endpoint' => '',
            ]),
        ]);

        $this->assertSame([], $result['data']['list'] ?? null);
    }

    public function test_yipay_payment_options_follow_enabled_payment_types(): void
    {
        $this->loadYiPayPlugin();

        $client = new YiPayClient(array_merge($this->config(), [
            'payment_types' => ['wxpay'],
        ]));

        $this->assertSame([
            [
                'key' => 'yipay',
                'name' => '易支付 - 微信支付',
                'label' => '微信支付',
                'option_key' => 'yipay:wxpay',
                'payment_type' => 'wxpay',
            ],
        ], $client->paymentOptions());
    }

    public function test_yipay_query_and_refund_map_provider_responses(): void
    {
        $this->loadYiPayPlugin();
        $requests = [];

        Http::fake(function ($request) use (&$requests) {
            $queryPayload = [];
            parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $queryPayload);
            $bodyPayload = [];
            parse_str($request->body(), $bodyPayload);
            $payload = array_merge($queryPayload, $bodyPayload);
            $requests[] = $payload;

            if (($payload['act'] ?? '') === 'order') {
                return Http::response([
                    'code' => 1,
                    'msg' => '查询订单号成功！',
                    'trade_no' => 'YIPAY202607020001',
                    'out_trade_no' => 'PAY202607020001',
                    'type' => 'alipay',
                    'pid' => 'merchant-10001',
                    'money' => '12.30',
                    'status' => 1,
                ]);
            }

            return Http::response([
                'code' => 1,
                'msg' => '退款成功',
            ]);
        });

        $client = new YiPayClient($this->config());
        $query = $client->query('PAY202607020001');
        $refund = $client->refund(new PaymentRefundRequest(
            outTradeNo: 'PAY202607020001',
            refundAmount: 12.3,
            refundReason: '测试退款',
            tradeNo: 'YIPAY202607020001',
            outRequestNo: 'RF202607020001',
        ));

        $this->assertSame('TRADE_SUCCESS', $query['trade_status']);
        $this->assertSame('YIPAY202607020001', $query['trade_no']);
        $this->assertSame('12.30', $query['total_amount']);
        $this->assertSame('12.30', $refund['refund_fee']);
        $this->assertSame('PAY202607020001', $refund['out_trade_no']);
        $this->assertSame('merchant-10001', $requests[0]['pid'] ?? null);
        $this->assertSame('secret-key', $requests[0]['key'] ?? null);
        $this->assertSame('refund', $requests[1]['act'] ?? null);
        $this->assertSame('YIPAY202607020001', $requests[1]['trade_no'] ?? null);
    }

    public function test_yipay_notify_verification_uses_md5_canonical_payload(): void
    {
        $this->loadYiPayPlugin();
        $client = new YiPayClient($this->config());
        $payload = [
            'pid' => 'merchant-10001',
            'name' => '图拉云 测试订单',
            'money' => '12.30',
            'out_trade_no' => 'PAY202607020001',
            'trade_no' => 'YIPAY202607020001',
            'param' => '',
            'trade_status' => 'TRADE_SUCCESS',
            'type' => 'alipay',
            'sign_type' => 'MD5',
        ];
        $payload['sign'] = $this->sign($payload);

        $this->assertTrue($client->verifyNotify($payload));

        $payload['money'] = '13.30';
        $this->assertFalse($client->verifyNotify($payload));
    }

    public function test_yipay_notify_verification_supports_rsa_signature(): void
    {
        $this->loadYiPayPlugin();
        $keys = $this->rsaKeyPair();
        $client = new YiPayClient(array_merge($this->config(), [
            'merchant_key' => '',
            'sign_type' => 'RSA',
            'merchant_private_key' => $keys['private_key'],
            'platform_public_key' => $keys['public_key'],
        ]));
        $payload = [
            'pid' => 'merchant-10001',
            'name' => '图拉云 RSA 测试订单',
            'money' => '12.30',
            'out_trade_no' => 'PAY202607020004',
            'trade_no' => 'YIPAY202607020004',
            'param' => '',
            'trade_status' => 'TRADE_SUCCESS',
            'type' => 'wxpay',
            'sign_type' => 'RSA',
        ];
        openssl_sign($this->canonicalString($payload), $signature, $keys['private_key'], OPENSSL_ALGO_SHA256);
        $payload['sign'] = base64_encode($signature);

        $this->assertTrue($client->verifyNotify($payload));

        $payload['money'] = '13.30';
        $this->assertFalse($client->verifyNotify($payload));
    }

    public function test_yipay_get_notify_callback_completes_recharge_idempotently(): void
    {
        $this->activateYiPayPlugin();

        $suffix = bin2hex(random_bytes(4));
        $tradeNo = 'YIPAY'.now()->format('YmdHis').strtoupper($suffix);
        $user = User::query()->create([
            'email' => 'yipay-callback-'.$suffix.'@example.com',
            'password' => 'Temp@123456',
            'phone' => '15'.str_pad((string) random_int(0, 999999999), 9, '0', STR_PAD_LEFT),
            'status' => 1,
            'nickname' => 'YiPay Callback',
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
        $user->forceFill(['balance' => '0.00'])->save();

        $payment = Payment::query()->create([
            'payment_no' => Payment::generatePaymentNo(),
            'user_id' => (int) $user->id,
            'invoice_id' => null,
            'gateway' => PaymentGatewayCode::YIPAY,
            'amount' => '12.30',
            'status' => PaymentStatus::PENDING,
            'trace_id' => 'test-yipay-callback',
        ]);

        $payload = [
            'pid' => 'merchant-10001',
            'name' => '图拉云 测试充值',
            'money' => '12.30',
            'out_trade_no' => (string) $payment->payment_no,
            'trade_no' => $tradeNo,
            'param' => '',
            'trade_status' => 'TRADE_SUCCESS',
            'type' => 'alipay',
            'sign_type' => 'MD5',
        ];
        $payload['sign'] = $this->sign($payload);
        $url = '/api/v2/client/payment/notify/yipay?'.http_build_query($payload);

        $this->get($url)
            ->assertOk()
            ->assertSeeText('success');

        $this->get($url)
            ->assertOk()
            ->assertSeeText('success');

        $payment->refresh();
        $this->assertSame(PaymentStatus::SUCCESS, (int) $payment->status);
        $this->assertSame($tradeNo, (string) $payment->trade_no);
        $this->assertNotNull($payment->invoice_id);

        $this->assertSame(1, AccountTransaction::query()
            ->where('user_id', (int) $user->id)
            ->where('event_type', 'recharge')
            ->where('source_id', (int) $payment->id)
            ->count());
        $this->assertSame('12.30', User::query()->findOrFail($user->id)->balance);
        $this->assertInstanceOf(Invoice::class, Invoice::query()->find((int) $payment->invoice_id));
    }

    /**
     * 回归：易支付回调不得确认属于其他网关的支付单，
     * 否则任一验签较弱的网关都能替支付宝订单入账。
     */
    public function test_yipay_notify_rejects_payment_belonging_to_another_gateway(): void
    {
        $this->activateYiPayPlugin();

        $suffix = bin2hex(random_bytes(4));
        $user = User::query()->create([
            'email' => 'yipay-cross-'.$suffix.'@example.com',
            'password' => 'Temp@123456',
            'phone' => '15'.str_pad((string) random_int(0, 999999999), 9, '0', STR_PAD_LEFT),
            'status' => 1,
            'nickname' => 'YiPay Cross',
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
        $user->forceFill(['balance' => '0.00'])->save();

        // 支付单属于支付宝，攻击者用易支付回调来确认它
        $payment = Payment::query()->create([
            'payment_no' => Payment::generatePaymentNo(),
            'user_id' => (int) $user->id,
            'invoice_id' => null,
            'gateway' => PaymentGatewayCode::ALIPAY,
            'amount' => '99.90',
            'status' => PaymentStatus::PENDING,
            'trace_id' => 'test-yipay-cross-gateway',
        ]);

        $payload = [
            'pid' => 'merchant-10001',
            'name' => '图拉云 跨网关测试',
            'money' => '99.90',
            'out_trade_no' => (string) $payment->payment_no,
            'trade_no' => 'YIPAY'.now()->format('YmdHis').strtoupper($suffix),
            'param' => '',
            'trade_status' => 'TRADE_SUCCESS',
            'type' => 'alipay',
            'sign_type' => 'MD5',
        ];
        // 签名本身是合法的，唯一的问题就是支付单不属于易支付
        $payload['sign'] = $this->sign($payload);

        $this->get('/api/v2/client/payment/notify/yipay?'.http_build_query($payload))
            ->assertOk()
            ->assertSeeText('fail');

        $payment->refresh();
        $this->assertSame(PaymentStatus::PENDING, (int) $payment->status);
        $this->assertNull($payment->invoice_id);
        $this->assertSame('0.00', User::query()->findOrFail($user->id)->balance);
        $this->assertSame(0, AccountTransaction::query()
            ->where('user_id', (int) $user->id)
            ->where('source_id', (int) $payment->id)
            ->count());
    }

    /**
     * @return array<string, mixed>
     */
    private function config(): array
    {
        return [
            'enabled' => true,
            'api_endpoint' => 'https://zpayz.cn',
            'merchant_id' => 'merchant-10001',
            'sign_type' => 'MD5',
            'merchant_key' => 'secret-key',
            'payment_types' => ['alipay'],
        ];
    }

    private function loadYiPayPlugin(): void
    {
        $manifest = app(PluginScanner::class)->requireManifest('payment', 'yi_pay');
        app(PluginFileLoader::class)->ensureLoaded($manifest);
    }

    private function activateYiPayPlugin(): IntegrationPlugin
    {
        $scanner = app(PluginScanner::class);
        $installer = app(PluginInstaller::class);
        $configRepository = app(PluginConfigRepository::class);

        $manifest = $scanner->requireManifest('payment', 'yi_pay');
        $plugin = $installer->install('payment', 'yi_pay');
        $configRepository->save($plugin, $manifest, $this->config());
        $plugin = $plugin->fresh('config') ?? $plugin;

        $enabled = $installer->enable($plugin);
        $this->forgetPaymentRuntime();

        return $enabled;
    }

    private function cleanPluginTables(): void
    {
        if (! Schema::hasTable('integration_plugins')) {
            return;
        }

        DB::statement('SET FOREIGN_KEY_CHECKS=0');
        if (Schema::hasTable('integration_plugin_configs')) {
            DB::table('integration_plugin_configs')->truncate();
        }
        DB::table('integration_plugins')->truncate();
        DB::statement('SET FOREIGN_KEY_CHECKS=1');
    }

    private function ensurePluginTables(): void
    {
        if (! Schema::hasTable('integration_plugins')) {
            Schema::create('integration_plugins', function (Blueprint $table): void {
                $table->id();
                $table->string('domain', 32);
                $table->string('slug', 120);
                $table->string('plugin_key', 120);
                $table->string('name', 120);
                $table->string('version', 32)->default('1.0.0');
                $table->string('provider_class', 255)->nullable();
                $table->string('entry_class', 255);
                $table->json('capabilities_json')->nullable();
                $table->json('config_schema_json')->nullable();
                $table->unsignedTinyInteger('status')->default(0);
                $table->timestamp('installed_at')->nullable();
                $table->timestamps();
                $table->unique(['domain', 'slug']);
                $table->unique(['domain', 'plugin_key']);
            });
        }

        if (! Schema::hasTable('integration_plugin_configs')) {
            Schema::create('integration_plugin_configs', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('plugin_id');
                $table->json('config_json')->nullable();
                $table->longText('secret_json')->nullable();
                $table->json('has_secret_json')->nullable();
                $table->unsignedBigInteger('updated_by')->nullable();
                $table->timestamps();
                $table->unique('plugin_id');
            });
        }
    }

    private function forgetPaymentRuntime(): void
    {
        $this->app->forgetInstance(PluginRuntimeRegistry::class);
        $this->app->forgetInstance(PaymentGatewayRegistry::class);
        $this->app->forgetInstance(PaymentGatewayManager::class);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function sign(array $payload): string
    {
        return md5($this->canonicalString($payload).'secret-key');
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function canonicalString(array $payload): string
    {
        unset($payload['sign'], $payload['sign_type']);
        ksort($payload, SORT_STRING);

        $pairs = [];
        foreach ($payload as $key => $value) {
            if ($value === null || $value === '' || is_array($value) || is_object($value)) {
                continue;
            }

            $pairs[] = $key.'='.(string) $value;
        }

        return implode('&', $pairs);
    }

    /**
     * @return array{private_key:string, public_key:string}
     */
    private function rsaKeyPair(): array
    {
        $configPath = tempnam(sys_get_temp_dir(), 'turaidc-openssl-');
        if ($configPath === false) {
            throw new \RuntimeException('测试 OpenSSL 临时配置创建失败');
        }

        $config = "[req]\ndistinguished_name=req_distinguished_name\n[req_distinguished_name]\n";
        if (file_put_contents($configPath, $config) === false) {
            unlink($configPath);

            throw new \RuntimeException('测试 OpenSSL 临时配置写入失败');
        }

        try {
            $key = openssl_pkey_new([
                'config' => $configPath,
                'private_key_bits' => 2048,
                'private_key_type' => OPENSSL_KEYTYPE_RSA,
            ]);
            if ($key === false) {
                throw new \RuntimeException('测试 RSA 密钥生成失败');
            }

            $privateKey = '';
            if (! openssl_pkey_export($key, $privateKey, null, ['config' => $configPath])) {
                throw new \RuntimeException('测试 RSA 私钥导出失败');
            }

            $details = openssl_pkey_get_details($key);
            if (! is_array($details) || ! is_string($details['key'] ?? null) || $details['key'] === '') {
                throw new \RuntimeException('测试 RSA 公钥导出失败');
            }

            return [
                'private_key' => $privateKey,
                'public_key' => $details['key'],
            ];
        } finally {
            if (is_file($configPath)) {
                unlink($configPath);
            }
        }
    }
}
