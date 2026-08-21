<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Services\Integrations\Plugins\PluginFileLoader;
use App\Services\Integrations\Plugins\PluginScanner;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;
use TuraIDC\Plugins\Certification\AlipayCertify\Logic\AlipayCertify;

class AlipayCertifyPluginTest extends TestCase
{
    private string $privatePem = '';

    private string $publicPem = '';

    protected function setUp(): void
    {
        parent::setUp();

        // 插件类走 PluginFileLoader 的 require_once 加载，不在 PSR-4 autoload 内，
        // 直接 new 之前必须先确保文件已载入。
        $manifest = app(PluginScanner::class)->requireManifest('verification', 'alipay_certify');
        app(PluginFileLoader::class)->ensureLoaded($manifest);

        // 现场生成密钥对：验签必须能对上自己签出来的内容，才说明待签串符合支付宝规范
        $keyPair = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        openssl_pkey_export($keyPair, $privatePem);
        $this->privatePem = (string) $privatePem;
        $this->publicPem = (string) (openssl_pkey_get_details($keyPair)['key'] ?? '');
    }

    public function test_manifest_declares_expected_contract_and_no_ssl_configuration(): void
    {
        $manifest = app(PluginScanner::class)->requireManifest('verification', 'alipay_certify');

        $this->assertSame('支付宝身份认证', $manifest->name);
        $this->assertSame('alipay_certify', $manifest->pluginKey ?? 'alipay_certify');

        foreach (['personal', 'scan_url', 'query_status', 'verify_callback', 'fee_config'] as $capability) {
            $this->assertContains($capability, (array) $manifest->capabilities);
        }

        $keys = collect($manifest->configSchema)->pluck('key')->all();
        foreach (['app_id', 'private_key', 'alipay_public_key', 'biz_code'] as $field) {
            $this->assertContains($field, $keys);
        }

        // 项目硬规则：插件不需要 SSL 与 CA，不得把这两项做成可配置
        $this->assertNotContains('ssl_verify', $keys);
        $this->assertNotContains('ca_bundle', $keys);
    }

    public function test_scan_url_signature_matches_alipay_canonical_form(): void
    {
        $result = (new AlipayCertify)->execute([
            'action' => 'certification.scan_url',
            'config' => $this->config(),
            'payload' => ['certify_id' => 'CERTIFY_0001'],
        ]);

        $data = $result['data'];
        $this->assertSame(200, $data['status']);

        parse_str((string) parse_url($data['url'], PHP_URL_QUERY), $params);
        $this->assertSame('alipay.user.certify.open.certify', $params['method']);
        $this->assertSame('RSA2', $params['sign_type']);
        $this->assertStringContainsString('CERTIFY_0001', $params['biz_content']);

        // 按支付宝规范重建待签串：key 升序、剔除 sign、跳过空值、用未编码原始值以 k=v 拼接
        $sign = (string) $params['sign'];
        unset($params['sign']);
        ksort($params);
        $pairs = [];
        foreach ($params as $key => $value) {
            if ((string) $value !== '') {
                $pairs[] = $key.'='.$value;
            }
        }

        $this->assertSame(1, openssl_verify(
            implode('&', $pairs),
            (string) base64_decode($sign, true),
            openssl_pkey_get_public($this->publicPem),
            OPENSSL_ALGO_SHA256
        ), '生成的签名必须能被支付宝公钥验证通过');
    }

    public function test_verify_callback_accepts_valid_signature_and_returns_replay_key(): void
    {
        $payload = $this->signedNotify(['certify_id' => 'CERTIFY_0001', 'passed' => 'T']);

        $data = (new AlipayCertify)->execute([
            'action' => 'certification.verify_callback',
            'config' => $this->config(),
            'payload' => ['payload' => $payload],
        ])['data'];

        $this->assertTrue($data['passed']);
        $this->assertSame(200, $data['http_status']);
        $this->assertNotSame('', trim((string) $data['replay_key']), '必须返回 replay_key 供平台做重放拦截');
    }

    public function test_verify_callback_rejects_tampered_and_missing_signature(): void
    {
        $payload = $this->signedNotify(['certify_id' => 'CERTIFY_0001', 'passed' => 'T']);

        $tampered = $payload;
        $tampered['passed'] = 'F';
        $this->assertFalse((new AlipayCertify)->execute([
            'action' => 'certification.verify_callback',
            'config' => $this->config(),
            'payload' => ['payload' => $tampered],
        ])['data']['passed']);

        unset($payload['sign']);
        $this->assertFalse((new AlipayCertify)->execute([
            'action' => 'certification.verify_callback',
            'config' => $this->config(),
            'payload' => ['payload' => $payload],
        ])['data']['passed']);
    }

    // 注意：这两个场景必须分成独立测试。Http::fake() 是「追加 stub、首个匹配生效」，
    // 在同一个测试里连续 fake 两次，第二次不会覆盖第一次。
    public function test_query_status_maps_passed_true_to_success(): void
    {
        $this->fakeGateway('alipay_user_certify_open_query_response', ['code' => '10000', 'msg' => 'Success', 'passed' => 'T']);

        $this->assertSame(1, $this->queryStatus(), 'passed=T 应判定为通过');
    }

    public function test_query_status_maps_passed_false_to_failure(): void
    {
        $this->fakeGateway('alipay_user_certify_open_query_response', ['code' => '10000', 'msg' => 'Success', 'passed' => 'F']);

        $this->assertSame(2, $this->queryStatus(), 'passed=F 应判定为未通过');
    }

    public function test_query_status_treats_missing_passed_flag_as_pending(): void
    {
        // code=10000 但没给 passed：不能当成失败，交由后续轮询收敛
        $this->fakeGateway('alipay_user_certify_open_query_response', ['code' => '10000', 'msg' => 'Success']);

        $this->assertSame(4, $this->queryStatus());
    }

    public function test_query_status_treats_unfinished_certification_as_pending(): void
    {
        // 用户尚未走完认证时，支付宝返回顶层 40004 + sub_code。这类响应语义是「处理中」，
        // 若判为失败，用户会看到「认证失败」并被要求重新发起，而 certify_id 在未刷脸时
        // 还有 23 小时有效期，本可继续使用。
        $this->fakeGateway('alipay_user_certify_open_query_response', [
            'code' => '40004',
            'msg' => 'Business Failed',
            'sub_code' => 'ACQ.CERTIFY_NOT_FINISH',
            'sub_msg' => '认证未完成',
        ]);

        $this->assertSame(4, $this->queryStatus());
    }

    public function test_query_status_maps_business_failure_to_failed(): void
    {
        $this->fakeGateway('alipay_user_certify_open_query_response', [
            'code' => '40004',
            'msg' => 'Business Failed',
            'sub_code' => 'ACQ.CERTIFY_INFO_MISMATCH',
            'sub_msg' => '姓名与证件号不一致',
        ]);

        $data = (new AlipayCertify)->execute([
            'action' => 'certification.query_status',
            'config' => $this->config(),
            'payload' => ['certify_id' => 'CERTIFY_0001'],
        ])['data'];

        $this->assertSame(2, $data['status']);
        $this->assertSame('姓名与证件号不一致', $data['message'], '中文 sub_msg 应透传给用户');
    }

    public function test_query_status_maps_connection_failure_to_network_error(): void
    {
        Http::fake(fn () => throw new \Illuminate\Http\Client\ConnectionException('timeout'));

        $this->assertSame(3, $this->queryStatus(), '网络异常必须与业务失败区分，否则会被误判为认证不通过');
    }

    public function test_initialize_returns_certify_id_and_sends_expected_biz_content(): void
    {
        $this->fakeGateway('alipay_user_certify_open_initialize_response', [
            'code' => '10000',
            'msg' => 'Success',
            'certify_id' => 'CERTIFY_0002',
        ]);

        $data = (new AlipayCertify)->execute([
            'action' => 'certification.initialize',
            'config' => $this->config(),
            'payload' => [
                'real_name' => '张三',
                'id_card' => '110101199001010011',
                'return_url' => 'https://console.example.com/client/verification',
            ],
        ])['data'];

        $this->assertSame(200, $data['status']);
        $this->assertSame('CERTIFY_0002', $data['certify_id']);

        Http::assertSent(function ($request) {
            $bizContent = json_decode((string) ($request->data()['biz_content'] ?? ''), true);

            $this->assertSame('FACE', $bizContent['biz_code']);
            $this->assertSame('CERT_INFO', $bizContent['identity_param']['identity_type']);
            $this->assertSame('IDENTITY_CARD', $bizContent['identity_param']['cert_type']);
            $this->assertSame('张三', $bizContent['identity_param']['cert_name']);
            $this->assertSame('110101199001010011', $bizContent['identity_param']['cert_no']);
            $this->assertSame(
                'https://console.example.com/client/verification',
                $bizContent['merchant_config']['return_url']
            );
            // 支付宝要求 32 位以内的字母数字组合
            $this->assertMatchesRegularExpression('/^[A-Za-z0-9]{1,32}$/', $bizContent['outer_order_no']);
            // charset 必须同时出现在查询串，否则网关按 GBK 解码 body，中文姓名会导致验签不一致
            $this->assertStringContainsString('charset=utf-8', (string) $request->url());

            return true;
        });
    }

    public function test_initialize_reports_missing_credentials_instead_of_signature_failure(): void
    {
        $data = (new AlipayCertify)->execute([
            'action' => 'certification.initialize',
            'config' => ['biz_code' => 'FACE'],
            'payload' => ['real_name' => '张三', 'id_card' => '110101199001010011'],
        ])['data'];

        $this->assertSame(400, $data['status']);
        $this->assertStringContainsString('应用 AppID', $data['message']);
        $this->assertStringContainsString('应用私钥', $data['message']);
        $this->assertStringContainsString('支付宝公钥', $data['message']);
    }

    public function test_fee_config_zeroes_retry_fee_when_charging_disabled(): void
    {
        $disabled = (new AlipayCertify)->execute([
            'action' => 'certification.fee_config',
            'config' => ['charge_enabled' => false, 'amount' => 5.5, 'free_times' => 2],
        ])['data'];

        $this->assertSame(0.0, $disabled['retry_fee'], '关闭收费时 retry_fee 会下发前端展示，必须为 0');
        $this->assertSame(2, $disabled['free_attempts']);

        $enabled = (new AlipayCertify)->execute([
            'action' => 'certification.fee_config',
            'config' => ['charge_enabled' => true, 'amount' => 5.5, 'free_times' => 2],
        ])['data'];

        $this->assertSame(5.5, $enabled['retry_fee']);
    }

    public function test_unsupported_action_is_rejected(): void
    {
        $result = (new AlipayCertify)->execute([
            'action' => 'certification.not_a_real_action',
            'config' => $this->config(),
        ]);

        $this->assertFalse($result['success']);
    }

    // ------------------------------------------------------------------ helpers

    /**
     * @return array<string, mixed>
     */
    private function config(): array
    {
        // 只给 base64 主体（不带 PEM 头尾）：顺带验证插件会自动补齐头尾
        $strip = static fn (string $pem): string => trim((string) preg_replace('/-----[^-]+-----|\s+/', '', $pem));

        return [
            'app_id' => '2021000000000000',
            'private_key' => $strip($this->privatePem),
            'alipay_public_key' => $strip($this->publicPem),
            'biz_code' => 'FACE',
        ];
    }

    /**
     * @param  array<string, mixed>  $body
     */
    private function fakeGateway(string $node, array $body): void
    {
        Http::fake([
            '*openapi.alipay.com*' => Http::response([$node => $body], 200),
        ]);
    }

    private function queryStatus(): int
    {
        return (int) (new AlipayCertify)->execute([
            'action' => 'certification.query_status',
            'config' => $this->config(),
            'payload' => ['certify_id' => 'CERTIFY_0001'],
        ])['data']['status'];
    }

    /**
     * 用测试私钥签出一份合法的异步通知。
     *
     * @param  array<string, mixed>  $fields
     * @return array<string, mixed>
     */
    private function signedNotify(array $fields): array
    {
        $payload = $fields + ['gmt_create' => '2026-08-22 10:00:00', 'sign_type' => 'RSA2'];

        $params = $payload;
        unset($params['sign'], $params['sign_type']);
        ksort($params);

        $pairs = [];
        foreach ($params as $key => $value) {
            if ((string) $value !== '') {
                $pairs[] = $key.'='.$value;
            }
        }

        $signature = '';
        openssl_sign(implode('&', $pairs), $signature, $this->privatePem, OPENSSL_ALGO_SHA256);
        $payload['sign'] = base64_encode($signature);

        return $payload;
    }
}
