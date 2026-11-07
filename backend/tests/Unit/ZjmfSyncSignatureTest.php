<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Exceptions\BusinessException;
use App\Services\ZjmfUpstream\ZjmfSyncSignature;
use Tests\TestCase;

/**
 * 魔方财务同步推送签名：3.7.6 只有 legacy（md5），3.8.0 起默认 v2（HMAC-SHA256）。
 *
 * 下面两个十六进制串是按 3.8.0 的 canonicalSyncPayload / createSign 口径离线算出来的
 * 固定样本，用来锁死算法细节（递归排序、null→空串、标量转字符串、JSON 不转义）。
 */
class ZjmfSyncSignatureTest extends TestCase
{
    private const TOKEN = 'secret-token-32chars-xxxxxxxxxxxxxx';

    /**
     * @return array<string, mixed>
     */
    private function payload(): array
    {
        return [
            'id' => 12,
            'host_id' => 345,
            'domain' => 'demo.example.test',
            'username' => 'root',
            'password' => 'p@ss',
            'dedicatedip' => '203.0.113.9',
            'assignedips' => '',
            'port' => 22,
            'os' => 'Debian 12',
            'os_url' => '',
            'domainstatus' => 'Active',
            'suspendreason' => '',
            'nextduedate' => '2026-12-07',
            'type' => 'create',
            'signature_version' => '2',
            'timestamp' => 1700000000,
            'nonce' => str_repeat('a', 32),
            'rand_str' => 'abc123',
        ];
    }

    public function test_canonical_payload_matches_zjmf_ordering_and_string_cast(): void
    {
        $this->assertSame(
            '{"assignedips":"","dedicatedip":"203.0.113.9","domain":"demo.example.test",'
            .'"domainstatus":"Active","host_id":"345","id":"12","nextduedate":"2026-12-07",'
            .'"nonce":"aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa","os":"Debian 12","os_url":"",'
            .'"password":"p@ss","port":"22","rand_str":"abc123","signature_version":"2",'
            .'"suspendreason":"","timestamp":"1700000000","type":"create","username":"root"}',
            ZjmfSyncSignature::canonicalPayload($this->payload())
        );
    }

    public function test_canonical_payload_drops_token_and_signature(): void
    {
        $withSecrets = $this->payload();
        $withSecrets['token'] = self::TOKEN;
        $withSecrets['signature'] = 'DEADBEEF';

        $this->assertSame(
            ZjmfSyncSignature::canonicalPayload($this->payload()),
            ZjmfSyncSignature::canonicalPayload($withSecrets)
        );
    }

    public function test_v2_signature_matches_reference_digest(): void
    {
        $this->assertSame(
            '7BC5CE1D067588C47AE5A7B93192B6F66730BFC61E995BCCB41E91E0C01851FE',
            strtoupper(hash_hmac('sha256', ZjmfSyncSignature::canonicalPayload($this->payload()), self::TOKEN))
        );
    }

    public function test_legacy_signature_matches_zjmf_create_sign(): void
    {
        $signed = ZjmfSyncSignature::signLegacy(['id' => 12], self::TOKEN);

        $expected = ['id' => 12, 'token' => self::TOKEN, 'rand_str' => $signed['rand_str']];
        ksort($expected, SORT_STRING);

        $this->assertSame(strtoupper(md5((string) json_encode($expected))), $signed['signature']);
        $this->assertSame(6, strlen($signed['rand_str']));
    }

    public function test_sign_v2_round_trips_through_verify(): void
    {
        $payload = ['id' => 42, 'domain' => 'x.example.test', 'domainstatus' => 'Active'];
        $signed = ZjmfSyncSignature::signV2($payload, self::TOKEN);

        $this->assertSame('2', $signed['signature_version']);
        $this->assertMatchesRegularExpression('/^[a-f0-9]{32}$/', $signed['nonce']);

        $received = array_merge($payload, $signed);
        $this->assertTrue(ZjmfSyncSignature::isV2($received));
        $this->assertTrue(ZjmfSyncSignature::verifyV2($received, self::TOKEN));
    }

    public function test_verify_rejects_tampered_field(): void
    {
        $payload = ['id' => 42, 'domain' => 'x.example.test'];
        $signed = ZjmfSyncSignature::signV2($payload, self::TOKEN);
        $received = array_merge($payload, $signed);
        $received['domain'] = 'evil.example.test';

        $this->assertFalse(ZjmfSyncSignature::verifyV2($received, self::TOKEN));
    }

    public function test_verify_rejects_wrong_token(): void
    {
        $payload = ['id' => 42];
        $received = array_merge($payload, ZjmfSyncSignature::signV2($payload, self::TOKEN));

        $this->assertFalse(ZjmfSyncSignature::verifyV2($received, str_repeat('x', 32)));
    }

    public function test_verify_rejects_stale_timestamp(): void
    {
        $payload = ['id' => 42];
        $signed = ZjmfSyncSignature::signV2($payload, self::TOKEN);
        $signed['timestamp'] = time() - (ZjmfSyncSignature::TIMESTAMP_TOLERANCE + 5);
        $signed['signature'] = strtoupper(hash_hmac(
            'sha256',
            ZjmfSyncSignature::canonicalPayload(array_merge($payload, $signed)),
            self::TOKEN
        ));

        $this->assertFalse(ZjmfSyncSignature::verifyV2(array_merge($payload, $signed), self::TOKEN));
    }

    public function test_verify_rejects_malformed_nonce(): void
    {
        $payload = ['id' => 42];
        $signed = ZjmfSyncSignature::signV2($payload, self::TOKEN);
        // 29 位 hex：签名本身仍然自洽，但 nonce 格式必须是 32 位
        $signed['nonce'] = str_repeat('b', 30);
        $signed['signature'] = strtoupper(hash_hmac(
            'sha256',
            ZjmfSyncSignature::canonicalPayload(array_merge($payload, $signed)),
            self::TOKEN
        ));

        $this->assertFalse(ZjmfSyncSignature::verifyV2(array_merge($payload, $signed), self::TOKEN));
    }

    public function test_empty_token_is_refused(): void
    {
        $this->expectException(BusinessException::class);

        ZjmfSyncSignature::signV2(['id' => 1], '   ');
    }

    public function test_rejection_detection_matches_upstream_wording(): void
    {
        $this->assertTrue(ZjmfSyncSignature::isSignatureRejected(['status' => 400, 'msg' => '签名验证失败']));
        $this->assertTrue(ZjmfSyncSignature::isSignatureRejected(['status' => 400, 'msg' => 'Signature verification failed']));
        $this->assertFalse(ZjmfSyncSignature::isSignatureRejected(['status' => 400, 'msg' => '该产品不能使用该接口']));
        $this->assertFalse(ZjmfSyncSignature::isSignatureRejected(['status' => 200, 'msg' => '签名验证失败']));
    }
}
