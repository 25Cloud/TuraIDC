<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
use App\Services\Auth\VerificationService;
use App\Services\Integrations\Plugins\PluginConfigRepository;
use App\Services\Integrations\Plugins\PluginInstaller;
use App\Services\Integrations\Plugins\PluginScanner;
use App\Services\Verification\VerificationDriverManager;
use Tests\TestCase;

class ClientVerificationInitValidationTest extends TestCase
{
    private function createUnverifiedUser(string $suffix): User
    {
        return User::query()->create([
            'email' => 'verification-init-'.$suffix.'@example.com',
            'password' => 'Temp@123456',
            'phone' => '13'.str_pad((string) random_int(0, 999999999), 9, '0', STR_PAD_LEFT),
            'status' => 1,
            'is_verified' => 0,
            'verification_status' => 0,
        ]);
    }

    public function test_init_rejects_missing_realname(): void
    {
        $suffix = bin2hex(random_bytes(4));
        $user = $this->createUnverifiedUser($suffix);
        $token = $user->createToken('client-verification-init-test')->plainTextToken;

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/v2/client/verification/init', [
                'idcard' => '320505199001010012',
            ])
            ->assertStatus(422)
            ->assertJsonPath('code', 42200)
            ->assertJsonPath('data.errors.realname.0', '请填写真实姓名。');
    }

    public function test_init_rejects_missing_idcard(): void
    {
        $suffix = bin2hex(random_bytes(4));
        $user = $this->createUnverifiedUser($suffix);
        $token = $user->createToken('client-verification-init-test')->plainTextToken;

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/v2/client/verification/init', [
                'realname' => '张三',
            ])
            ->assertStatus(422)
            ->assertJsonPath('code', 42200)
            ->assertJsonPath('data.errors.idcard.0', '请填写证件号码。');
    }

    public function test_init_rejects_invalid_cert_type(): void
    {
        $suffix = bin2hex(random_bytes(4));
        $user = $this->createUnverifiedUser($suffix);
        $token = $user->createToken('client-verification-init-test')->plainTextToken;

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/v2/client/verification/init', [
                'realname' => '张三',
                'idcard' => '320505199001010012',
                'cert_type' => 'INVALID_TYPE',
            ])
            ->assertStatus(422)
            ->assertJsonPath('code', 42200)
            ->assertJsonStructure(['data' => ['errors' => ['cert_type']]]);
    }

    public function test_init_accepts_valid_cert_type(): void
    {
        $suffix = bin2hex(random_bytes(4));
        $user = $this->createUnverifiedUser($suffix);
        $token = $user->createToken('client-verification-init-test')->plainTextToken;

        $fakeService = new class extends VerificationService
        {
            public function __construct() {}

            public function startVerificationSession($user, string $realname, string $idcard, string $certType = 'IDENTITY_CARD'): array
            {
                return ['certify_id' => 'FAKE-CERTIFY-ID', 'status' => 'pending'];
            }
        };

        $this->app->instance(VerificationService::class, $fakeService);

        $response = $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/v2/client/verification/init', [
                'realname' => '张三',
                'idcard' => '320505199001010012',
                'cert_type' => 'HOME_VISIT_PERMIT_HK_MC',
            ]);

        $response->assertSuccessful();
        $this->assertArrayNotHasKey('errors', $response->json());
    }

    public function test_init_defaults_cert_type_to_identity_card_when_omitted(): void
    {
        $suffix = bin2hex(random_bytes(4));
        $user = $this->createUnverifiedUser($suffix);
        $token = $user->createToken('client-verification-init-test')->plainTextToken;

        $receivedCertType = null;
        $fakeService = new class($receivedCertType) extends VerificationService
        {
            public function __construct(private ?string &$receivedCertType) {}

            public function startVerificationSession($user, string $realname, string $idcard, string $certType = 'IDENTITY_CARD'): array
            {
                $this->receivedCertType = $certType;

                return ['certify_id' => 'FAKE-CERTIFY-ID', 'status' => 'pending'];
            }
        };

        $this->app->instance(VerificationService::class, $fakeService);

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/v2/client/verification/init', [
                'realname' => '张三',
                'idcard' => '320505199001010012',
            ])
            ->assertSuccessful();

        $this->assertSame('IDENTITY_CARD', $receivedCertType);
    }

    public function test_restart_rejects_non_failed_status(): void
    {
        $suffix = bin2hex(random_bytes(4));
        $user = $this->createUnverifiedUser($suffix);
        $user->forceFill(['verification_status' => 4, 'verification_message' => '等待认证'])->save();
        $token = $user->createToken('client-verification-restart-test')->plainTextToken;

        $called = false;
        $fakeService = new class($called) extends VerificationService
        {
            public function __construct(private bool &$called) {}

            public function restartVerificationSession($user): array
            {
                $this->called = true;

                return ['certify_id' => 'FAKE-CERTIFY-ID', 'status' => 'pending'];
            }
        };
        $this->app->instance(VerificationService::class, $fakeService);

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/v2/client/verification/restart')
            ->assertStatus(422)
            ->assertJsonPath('code', 42200)
            ->assertJsonPath('message', '当前状态不支持重新认证，请先查询认证结果');

        $this->assertFalse($called, '待认证状态不应触发重新认证会话');
    }

    public function test_restart_allows_failed_status(): void
    {
        $suffix = bin2hex(random_bytes(4));
        $user = $this->createUnverifiedUser($suffix);
        $user->forceFill(['verification_status' => 3, 'verification_message' => '认证失败'])->save();
        $token = $user->createToken('client-verification-restart-test')->plainTextToken;

        $fakeService = new class extends VerificationService
        {
            public function __construct() {}

            public function restartVerificationSession($user): array
            {
                return ['certify_id' => 'FAKE-CERTIFY-ID', 'status' => 'pending'];
            }
        };
        $this->app->instance(VerificationService::class, $fakeService);

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/v2/client/verification/restart')
            ->assertOk()
            ->assertJsonPath('data.certify_id', 'FAKE-CERTIFY-ID');
    }

    public function test_fee_config_reads_enabled_verification_plugin_config(): void
    {
        $suffix = bin2hex(random_bytes(4));
        $user = $this->createUnverifiedUser($suffix);
        $token = $user->createToken('client-verification-fee-config-test')->plainTextToken;

        $scanner = app(PluginScanner::class);
        $installer = app(PluginInstaller::class);
        $configRepository = app(PluginConfigRepository::class);
        $manifest = $scanner->requireManifest('verification', 'stay33');
        $plugin = $installer->install('verification', 'stay33');
        $configRepository->save($plugin, $manifest, [
            'api' => 'verification-api',
            'key' => 'verification-secret',
            'biz_code' => 'FACE',
            'charge_enabled' => true,
            'amount' => 8.5,
            'free_times' => 5,
        ]);
        $installer->enable($plugin);
        $this->app->forgetInstance(VerificationDriverManager::class);
        $this->app->forgetInstance(VerificationService::class);

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('/api/v2/client/verification/fee-config')
            ->assertOk()
            ->assertJsonPath('data.free_attempts', 5)
            ->assertJsonPath('data.retry_fee', 8.5)
            ->assertJsonPath('data.charge_enabled', true)
            ->assertJsonPath('data.amount', 8.5);
    }
}
