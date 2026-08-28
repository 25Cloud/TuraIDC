<?php

declare(strict_types=1);

namespace Tests\Feature;

require_once __DIR__.'/../Support/InstallsZjmfBridgeAddon.php';

use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Cache;
use Tests\Support\InstallsZjmfBridgeAddon;
use Tests\TestCase;
use TuraIDC\Plugins\Addons\ZjmfBridge\Services\ZjmfTokenService;

/**
 * ZJMF Bridge 客户端令牌的伪造防护。
 *
 * 背景：`zjmf_bridge.secret` 的 env 默认值是空串，而 ZjmfTokenService 早期直接把它
 * 交给 hash_hmac 当密钥。空密钥下任何人都能算出「正确」签名，伪造出任意 uid 的令牌；
 * 而 /zjmf/v1 下的 client 路由只挂 `zjmf.client:<scope>`，没有叠加 HMAC 签名校验，
 * 令牌就是唯一防线 —— 一旦桥接被启用，等于任意用户接管（读账单、建工单、充值单）。
 * scope 也写在 payload 里由攻击者自选，scopeAllowed 挡不住。
 *
 * 本用例把这条路径钉死：密钥为空时既不签发也不校验，桥接直接对外报配置错误。
 */
class ZjmfBridgeTokenForgeryTest extends TestCase
{
    use DatabaseTransactions;
    use InstallsZjmfBridgeAddon;

    private const REAL_SECRET = 'zjmf-forgery-test-secret';

    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();
        config([
            'zjmf_bridge.enabled' => true,
            'zjmf_bridge.app_id' => 'zjmf-test',
            'zjmf_bridge.secret' => self::REAL_SECRET,
            'zjmf_bridge.allowed_ips' => [],
            'zjmf_bridge.signature_tolerance' => 300,
            'zjmf_bridge.token_ttl' => 7200,
            'zjmf_bridge.system_scopes' => ['system.health'],
        ]);
        $this->installZjmfBridgeAddon();
    }

    public function test_token_forged_with_an_empty_secret_cannot_impersonate_a_user(): void
    {
        $victim = $this->createClientUser();

        // 现场即为「桥接已开启、但运维没配 ZJMF_BRIDGE_SECRET」——env 默认值就是空串。
        config(['zjmf_bridge.secret' => '']);

        // 攻击者只需知道受害者 uid，用空密钥自签一枚全 scope 令牌。
        $forged = $this->forgeToken('', [
            'sub' => 'client:'.(int) $victim->id,
            'uid' => (int) $victim->id,
            'scope' => ['client.read', 'finance.read', 'finance.write', 'payment.write', 'ticket.write'],
        ]);

        $response = $this->withHeaders(['Authorization' => 'Bearer '.$forged])
            ->getJson('/zjmf/v1/user');

        $this->assertNotSame(
            200,
            (int) $response->json('status'),
            '空密钥下自签的令牌必须被拒绝，否则任何人都能冒充任意用户'
        );
        $response->assertJsonPath('status', 401);
    }

    public function test_empty_secret_disables_verification_even_for_a_consistently_signed_token(): void
    {
        config(['zjmf_bridge.secret' => '']);

        $payload = app(ZjmfTokenService::class)->verify($this->forgeToken('', [
            'sub' => 'client:1',
            'uid' => 1,
            'scope' => ['client.read'],
        ]));

        $this->assertNull($payload, '密钥未配置时 verify() 必须一律返回 null');
    }

    public function test_login_refuses_to_mint_a_token_when_the_secret_is_missing(): void
    {
        config(['zjmf_bridge.secret' => '']);

        // 拒签而不是签一枚人人可伪造的令牌：宁可登录整条断掉。
        $this->expectException(\RuntimeException::class);

        app(ZjmfTokenService::class)->issue(['sub' => 'client:1', 'uid' => 1], 60);
    }

    public function test_algorithm_header_tampering_is_rejected(): void
    {
        $victim = $this->createClientUser();

        // 密钥配置正常，但攻击者把 header 改成 alg=none 并去掉签名段。
        $header = $this->base64UrlEncode((string) json_encode(['typ' => 'JWT', 'alg' => 'none']));
        $now = time();
        $body = $this->base64UrlEncode((string) json_encode([
            'sub' => 'client:'.(int) $victim->id,
            'uid' => (int) $victim->id,
            'scope' => ['client.read'],
            'iat' => $now,
            'nbf' => $now,
            'exp' => $now + 600,
        ]));

        $response = $this->withHeaders(['Authorization' => 'Bearer '.$header.'.'.$body.'.'])
            ->getJson('/zjmf/v1/user');

        $response->assertJsonPath('status', 401);
    }

    public function test_a_properly_signed_token_still_authenticates(): void
    {
        $user = $this->createClientUser();

        // 阳性对照：修复不能把正常令牌一起挡掉。
        $token = app(ZjmfTokenService::class)->issue([
            'sub' => 'client:'.(int) $user->id,
            'uid' => (int) $user->id,
            'scope' => ['client.read'],
        ], 600);

        $response = $this->withHeaders(['Authorization' => 'Bearer '.$token])
            ->getJson('/zjmf/v1/user');

        $response->assertJsonPath('status', 200);
    }

    /**
     * 用指定密钥手工拼一枚 HS256 令牌，不经 ZjmfTokenService——
     * 攻击者手里没有本项目代码，走的就是这条纯算法路径。
     *
     * @param  array<string, mixed>  $claims
     */
    private function forgeToken(string $secret, array $claims): string
    {
        $now = time();
        $header = $this->base64UrlEncode((string) json_encode(['typ' => 'JWT', 'alg' => 'HS256']));
        $payload = $this->base64UrlEncode((string) json_encode(array_merge($claims, [
            'iat' => $now,
            'nbf' => $now,
            'exp' => $now + 600,
        ])));

        $signature = $this->base64UrlEncode(hash_hmac('sha256', $header.'.'.$payload, $secret, true));

        return $header.'.'.$payload.'.'.$signature;
    }

    private function base64UrlEncode(string $input): string
    {
        return rtrim(strtr(base64_encode($input), '+/', '-_'), '=');
    }

    private function createClientUser(): User
    {
        $suffix = bin2hex(random_bytes(4));

        return User::query()->create([
            'email' => 'zjmf-forgery-'.$suffix.'@example.com',
            'phone' => '139'.random_int(10000000, 99999999),
            'password' => 'Secret123!',
            'nickname' => 'ZJMF Client',
            'status' => 1,
            'login_email_alert' => 0,
            'login_notify' => 0,
            'login_location_alert' => 0,
            'password_change_alert' => 0,
            'phone_change_alert' => 0,
            'email_change_alert' => 0,
            'marketing_alert' => 0,
        ]);
    }
}
