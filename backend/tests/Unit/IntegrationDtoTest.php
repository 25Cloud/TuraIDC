<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\Sms\Data\SmsSendResult;
use App\Services\Verification\Data\VerificationInitializeResult;
use App\Services\Verification\Data\VerificationScanUrlResult;
use App\Services\Verification\Data\VerificationStatusResult;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use TuraIDC\Plugins\Certification\Stay33\Logic\Stay33Client;
use TuraIDC\Plugins\Sms\Aliyun\Lib\AliyunSmsClient;

class IntegrationDtoTest extends TestCase
{
    public function test_identity_dtos_keep_legacy_array_shape_without_raw_payload(): void
    {
        $initialize = new VerificationInitializeResult(200, '请求成功', 'CERT123', ['raw' => 'hidden']);
        $scanUrl = new VerificationScanUrlResult(200, '请继续认证', 'https://example.test/verify', ['raw' => 'hidden']);
        $status = new VerificationStatusResult(1, '审核通过', ['raw' => 'hidden']);

        $this->assertSame(['status' => 200, 'msg' => '请求成功', 'certify_id' => 'CERT123'], $initialize->toArray());
        $this->assertSame(['status' => 200, 'msg' => '请继续认证', 'url' => 'https://example.test/verify'], $scanUrl->toArray());
        $this->assertSame(['status' => 1, 'msg' => '审核通过'], $status->toArray());
    }

    public function test_sms_result_keeps_legacy_array_shape_without_raw_payload(): void
    {
        $result = new SmsSendResult('success', 'REQ123', null, 'TPL001', ['code' => '123456'], ['raw' => 'hidden']);

        $this->assertSame([
            'status' => 'success',
            'request_id' => 'REQ123',
            'template_code' => 'TPL001',
            'template_params' => ['code' => '123456'],
        ], $result->toArray());
    }

    public function test_provider_english_errors_are_mapped_to_chinese_messages(): void
    {
        $this->loadPluginFile('sms/aliyun/lib/AliyunSmsClient.php');
        $this->loadPluginFile('certification/stay33/logic/Stay33Client.php');

        $this->assertSame(
            '短信发送失败，请稍后重试',
            $this->invokePrivate(new AliyunSmsClient([]), 'resolveFailureMessage', ['InvalidAccessKeyId.NotFound'])
        );

        $this->assertSame(
            '短信余额不足',
            $this->invokePrivate(new AliyunSmsClient([]), 'resolveFailureMessage', ['短信余额不足'])
        );

        $this->assertSame(
            '实名认证接口配置错误，请联系管理员',
            $this->invokePrivate(new Stay33Client([]), 'safeProviderMessage', ['SYSTEM_ERROR', '实名认证接口配置错误，请联系管理员'])
        );
    }

    public function test_aliyun_verify_code_uses_builtin_template_codes_by_purpose(): void
    {
        $this->loadPluginFile('sms/aliyun/lib/AliyunSmsClient.php');

        $client = new AliyunSmsClient([]);

        $this->assertSame('100001', $this->invokePrivate($client, 'resolveVerifyTemplateCode', [['purpose' => 'login']]));
        $this->assertSame('100001', $this->invokePrivate($client, 'resolveVerifyTemplateCode', [['purpose' => 'register']]));
        $this->assertSame('100002', $this->invokePrivate($client, 'resolveVerifyTemplateCode', [['purpose' => 'change_phone']]));
        $this->assertSame('100003', $this->invokePrivate($client, 'resolveVerifyTemplateCode', [['purpose' => 'reset_password']]));
        $this->assertSame('100004', $this->invokePrivate($client, 'resolveVerifyTemplateCode', [['purpose' => 'bind_phone']]));
        $this->assertSame('100005', $this->invokePrivate($client, 'resolveVerifyTemplateCode', [['purpose' => 'verify_bound_phone']]));
        $this->assertSame('100001', $this->invokePrivate($client, 'resolveVerifyTemplateCode', [['purpose' => 'unknown']]));
    }

    public function test_aliyun_sign_name_accepts_dynamic_options(): void
    {
        $this->loadPluginFile('sms/aliyun/lib/AliyunSmsClient.php');

        $this->assertSame(
            '运营商新签名',
            $this->invokePrivate(new AliyunSmsClient(['sign_name' => '运营商新签名']), 'resolveSignName', [[]])
        );
        $this->assertSame(
            '恒创联众',
            $this->invokePrivate(new AliyunSmsClient(['sign_name' => '']), 'resolveSignName', [[]])
        );
    }

    public function test_aliyun_sign_name_parser_reads_nested_provider_response(): void
    {
        $this->loadPluginFile('sms/aliyun/lib/AliyunSmsClient.php');

        $payload = [
            'Code' => 'OK',
            'SmsSignList' => [
                'SmsSign' => [
                    ['SignName' => '恒创联众', 'AuditStatus' => 'AUDIT_STATE_PASS'],
                    ['SignName' => '北京恒锐创岳'],
                    ['SignName' => '待审核签名', 'AuditStatus' => 'AUDIT_STATE_INIT'],
                    ['sign_name' => '恒锐创岳科技'],
                ],
            ],
        ];

        $this->assertSame(
            ['恒创联众', '北京恒锐创岳', '恒锐创岳科技'],
            $this->invokePrivate(new AliyunSmsClient([]), 'extractSignNames', [$payload])
        );
    }

    /**
     * 证书校验不再可配置，且失败文案不再指向已移除的 env 项。
     *
     * 本测试取代原 test_aliyun_sms_client_resolves_ssl_options_and_curl_errors——那条断言
     * resolveSslVerify() / resolveCaBundle() 会读插件配置，把违规行为钉成了预期，
     * 与 AGENTS.md「所有插件不需要 SSL 和 CA」冲突。
     */
    public function test_aliyun_sms_client_always_verifies_certificates(): void
    {
        $this->loadPluginFile('sms/aliyun/lib/AliyunSmsClient.php');

        // 即便塞入插件级 ssl 配置，也不应再有任何读取入口
        $client = new AliyunSmsClient([
            'ssl_verify' => '0',
            'ca_bundle' => ' C:/certs/cacert.pem ',
        ]);

        foreach (['resolveSslVerify', 'resolveCaBundle'] as $removed) {
            $this->assertFalse(
                method_exists($client, $removed),
                "AliyunSmsClient 不应再有 {$removed}()：证书校验已固定开启，不读任何配置"
            );
        }

        $source = (string) file_get_contents(base_path('plugins/sms/aliyun/lib/AliyunSmsClient.php'));
        $this->assertStringContainsString('CURLOPT_SSL_VERIFYPEER, true', $source);
        $this->assertStringNotContainsString('CURLOPT_CAINFO', $source);

        $this->setPrivateProperty($client, 'lastCurlError', [
            'errno' => 60,
            'error' => 'SSL certificate problem: unable to get local issuer certificate',
        ]);

        // 文案不得再指向已移除的 SMS_CA_BUNDLE
        $message = (string) $this->invokePrivate($client, 'resolveCurlFailureMessage', []);
        $this->assertStringNotContainsString('SMS_CA_BUNDLE', $message);
        $this->assertStringContainsString('系统 CA', $message);
    }

    private function invokePrivate(object $instance, string $method, array $args): mixed
    {
        $reflection = new ReflectionClass($instance);
        $methodRef = $reflection->getMethod($method);
        $methodRef->setAccessible(true);

        return $methodRef->invokeArgs($instance, $args);
    }

    private function setPrivateProperty(object $instance, string $property, mixed $value): void
    {
        $reflection = new ReflectionClass($instance);
        $propertyRef = $reflection->getProperty($property);
        $propertyRef->setAccessible(true);
        $propertyRef->setValue($instance, $value);
    }

    private function loadPluginFile(string $relativePath): void
    {
        require_once dirname(__DIR__, 2).'/plugins/'.$relativePath;
    }
}
