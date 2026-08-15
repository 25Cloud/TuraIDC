<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
use App\Services\Auth\VerificationService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class VerificationCertifyIdLookupRegressionTest extends TestCase
{
    public function test_service_finds_user_by_users_table_when_legacy_verification_table_is_missing(): void
    {
        $suffix = strtoupper(bin2hex(random_bytes(4)));

        $user = User::query()->create([
            'email' => 'verification-lookup-'.strtolower($suffix).'@example.com',
            'password' => 'Temp@123456',
            'phone' => '13'.str_pad((string) random_int(0, 999999999), 9, '0', STR_PAD_LEFT),
            'status' => 1,
            'nickname' => 'Verification Lookup User',
            'real_name' => '鏉庡洓',
            'id_card' => '320505199001010012',
            'verification_status' => 4,
            'verification_message' => '绛夊緟璁よ瘉',
            'verification_certify_id' => 'CERT-'.$suffix,
            'is_verified' => 0,
            'member_level_id' => null,
            'total_sales_amount' => '0.00',
            'referrer_user_id' => null,
            'verified_at' => null,
        ]);

        $actualSchema = DB::connection()->getSchemaBuilder();

        Schema::shouldReceive('hasTable')
            ->andReturnUsing(static function (string $table) use ($actualSchema): bool {
                return match ($table) {
                    'user_verifications' => false,
                    default => $actualSchema->hasTable($table),
                };
            });

        $service = app(VerificationService::class);
        $found = $service->findUserByCertifyId('CERT-'.$suffix);

        $this->assertNotNull($found);
        $this->assertSame((int) $user->id, (int) $found->id);
        $this->assertSame('CERT-'.$suffix, (string) $found->verification_certify_id);
    }
}
