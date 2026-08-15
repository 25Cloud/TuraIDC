<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
use App\Services\Auth\VerificationService;
use Tests\TestCase;

class ClientVerificationQrcodeOwnershipTest extends TestCase
{
    public function test_qrcode_refresh_rejects_certify_id_that_belongs_to_another_user(): void
    {
        $suffix = strtoupper(bin2hex(random_bytes(4)));

        $owner = $this->createClientUserForVerification('owner-'.$suffix, 'CERT-OWNER-'.$suffix);
        $attacker = $this->createClientUserForVerification('attacker-'.$suffix, 'CERT-ATTACKER-'.$suffix);

        $fakeService = new class extends VerificationService
        {
            public bool $called = false;

            public function __construct() {}

            public function generateQrCode(string $certifyId): array
            {
                $this->called = true;

                return [
                    'url' => 'https://realname.example.com/certify',
                ];
            }
        };

        $this->app->instance(VerificationService::class, $fakeService);

        $token = $attacker->createToken('client-verification-qrcode-ownership')->plainTextToken;

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/v2/client/verification/qrcode', [
                'certify_id' => (string) $owner->verification_certify_id,
            ])
            ->assertForbidden()
            ->assertJsonPath('code', 40300)
            ->assertJsonPath('message', '认证会话与当前账户不匹配');

        $this->assertFalse($fakeService->called);
    }

    public function test_qrcode_close_rejects_certify_id_that_belongs_to_another_user(): void
    {
        $suffix = strtoupper(bin2hex(random_bytes(4)));

        $owner = $this->createClientUserForVerification('owner-close-'.$suffix, 'CERT-OWNER-CLOSE-'.$suffix);
        $attacker = $this->createClientUserForVerification('attacker-close-'.$suffix, 'CERT-ATTACKER-CLOSE-'.$suffix);

        $fakeService = new class extends VerificationService
        {
            public bool $called = false;

            public function __construct() {}

            public function closeQrCodeSession(string $certifyId): void
            {
                $this->called = true;
            }
        };

        $this->app->instance(VerificationService::class, $fakeService);

        $token = $attacker->createToken('client-verification-close-ownership')->plainTextToken;

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/v2/client/verification/close', [
                'certify_id' => (string) $owner->verification_certify_id,
            ])
            ->assertForbidden()
            ->assertJsonPath('code', 40300)
            ->assertJsonPath('message', '认证会话与当前账户不匹配');

        $this->assertFalse($fakeService->called);
    }

    public function test_qrcode_close_accepts_current_users_session(): void
    {
        $suffix = strtoupper(bin2hex(random_bytes(4)));
        $user = $this->createClientUserForVerification('owner-close-ok-'.$suffix, 'CERT-CLOSE-'.$suffix);

        $fakeService = new class extends VerificationService
        {
            public string $closedCertifyId = '';

            public function __construct() {}

            public function closeQrCodeSession(string $certifyId): void
            {
                $this->closedCertifyId = $certifyId;
            }
        };

        $this->app->instance(VerificationService::class, $fakeService);

        $token = $user->createToken('client-verification-close-current')->plainTextToken;

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/v2/client/verification/close', [
                'certify_id' => (string) $user->verification_certify_id,
            ])
            ->assertOk()
            ->assertJsonPath('code', 0)
            ->assertJsonPath('data.closed', true)
            ->assertJsonPath('data.certify_id', (string) $user->verification_certify_id);

        $this->assertSame((string) $user->verification_certify_id, $fakeService->closedCertifyId);
    }

    private function createClientUserForVerification(string $label, string $certifyId): User
    {
        $numeric = (string) random_int(0, 999999999);

        return User::query()->create([
            'email' => 'verification-qrcode-'.$label.'@example.com',
            'password' => 'Temp@123456',
            'phone' => '13'.str_pad($numeric, 9, '0', STR_PAD_LEFT),
            'status' => 1,
            'nickname' => 'Verification Qrcode '.$label,
            'real_name' => '张三',
            'id_card' => '320505199001010012',
            'verification_status' => 4,
            'verification_message' => '等待认证',
            'verification_certify_id' => $certifyId,
            'is_verified' => 0,
            'member_level_id' => null,
            'total_sales_amount' => '0.00',
            'referrer_user_id' => null,
            'verified_at' => null,
        ]);
    }
}
