<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\AdminUser;
use App\Models\Role;
use App\Models\User;
use App\Support\AdminPermissions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class AdminVerificationUnbindRegressionTest extends TestCase
{
    public function test_admin_can_unbind_verification_without_legacy_user_verifications_table(): void
    {
        $suffix = bin2hex(random_bytes(4));
        $rejectReason = '资料需要重新提交';

        $role = Role::query()->create([
            'name' => 'verification-unbind-regression-'.$suffix,
            'label' => 'Verification Unbind Regression',
            'permissions' => [AdminPermissions::VERIFICATION_UNBIND],
        ]);

        $admin = AdminUser::query()->create([
            'username' => 'verification-unbind-admin-'.$suffix,
            'password' => 'secret123',
            'role_id' => (int) $role->id,
            'nickname' => 'Verification Admin',
            'email' => 'verification-unbind-admin-'.$suffix.'@example.com',
            'status' => 1,
        ]);

        $user = User::query()->create([
            'email' => 'verification-unbind-user-'.$suffix.'@example.com',
            'password' => 'Temp@123456',
            'phone' => '13'.str_pad((string) random_int(0, 999999999), 9, '0', STR_PAD_LEFT),
            'status' => 1,
            'nickname' => 'Verification User',
            'real_name' => '张三',
            'id_card' => '320505199001010012',
            'verification_status' => 2,
            'verification_message' => '等待认证',
            'verification_certify_id' => 'CERT-'.strtoupper($suffix),
            'is_verified' => 1,
            'member_level_id' => null,
            'total_sales_amount' => '0.00',
            'referrer_user_id' => null,
            'verified_at' => now(),
        ]);

        $actualSchema = DB::connection()->getSchemaBuilder();

        Schema::shouldReceive('hasTable')
            ->andReturnUsing(static function (string $table) use ($actualSchema): bool {
                return match ($table) {
                    'user_verifications' => false,
                    default => $actualSchema->hasTable($table),
                };
            });

        $token = $admin->createToken('verification-unbind-regression')->plainTextToken;

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/v2/admin/verifications/'.$user->id.'/unbindings', [
                'reject_reason' => $rejectReason,
            ])
            ->assertOk()
            ->assertJsonPath('data.user_id', (int) $user->id)
            ->assertJsonPath('data.reject_reason', $rejectReason);

        $user->refresh();

        $this->assertSame(5, (int) $user->verification_status);
        $this->assertSame(0, (int) $user->is_verified);
        $this->assertSame($rejectReason, (string) $user->verification_message);
        $this->assertNull($user->verification_certify_id);
        $this->assertNull($user->verified_at);
    }
}
