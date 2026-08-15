<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
use App\Services\Auth\VerificationService;
use App\Services\Integrations\Plugins\IntegrationDriverBindingResolver;
use App\Services\Verification\Contracts\VerificationDriver;
use App\Services\Verification\Data\VerificationInitializeRequest;
use App\Services\Verification\Data\VerificationInitializeResult;
use App\Services\Verification\Data\VerificationScanUrlResult;
use App\Services\Verification\Data\VerificationStatusResult;
use App\Services\Verification\VerificationDriverManager;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class VerificationServiceIdCardEncryptionTest extends TestCase
{
    public function test_initialization_encrypts_each_id_card_once(): void
    {
        $idCard = '320505199001010012';
        $user = User::query()->create([
            'email' => 'verification-encryption-'.bin2hex(random_bytes(4)).'@example.com',
            'password' => 'Temp@123456',
            'phone' => '13'.str_pad((string) random_int(0, 999999999), 9, '0', STR_PAD_LEFT),
            'status' => 1,
        ]);

        $resolver = new class extends IntegrationDriverBindingResolver
        {
            public function verificationDriverCandidates(): array
            {
                return ['verification-test'];
            }

            public function verificationContext(?string $driverKey = null): array
            {
                return ['plugin_id' => null, 'driver_key' => $driverKey ?: 'verification-test'];
            }
        };

        $driver = new class implements VerificationDriver
        {
            public function key(): string
            {
                return 'verification-test';
            }

            public function label(): string
            {
                return '实名认证测试驱动';
            }

            public function initialize(VerificationInitializeRequest $request): VerificationInitializeResult
            {
                return new VerificationInitializeResult(200, '初始化成功', 'verification-test-certify-id');
            }

            public function generateScanUrl(string $certifyId): VerificationScanUrlResult
            {
                return new VerificationScanUrlResult(200, '获取成功', 'https://example.test/verification');
            }

            public function queryStatus(string $certifyId): VerificationStatusResult
            {
                return new VerificationStatusResult(4, '等待认证');
            }
        };

        $service = new VerificationService(
            new VerificationDriverManager([$driver], $resolver),
            null,
            $resolver,
        );

        $service->initVerification($user, '张三', $idCard);

        $storedUserIdCard = (string) DB::table('users')->where('id', $user->id)->value('id_card');
        $storedHistoryIdCard = (string) DB::table('verification_histories')
            ->where('user_id', $user->id)
            ->value('id_card');

        $this->assertNotSame($idCard, $storedUserIdCard);
        $this->assertLessThanOrEqual(512, strlen($storedUserIdCard));
        $this->assertSame($idCard, Crypt::decryptString($storedUserIdCard));
        $this->assertSame($idCard, $user->fresh()->id_card);

        $this->assertNotSame($idCard, $storedHistoryIdCard);
        $this->assertLessThanOrEqual(512, strlen($storedHistoryIdCard));
        $this->assertSame($idCard, Crypt::decryptString($storedHistoryIdCard));
    }
}
