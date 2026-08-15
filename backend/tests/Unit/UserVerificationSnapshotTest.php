<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Models\User;
use Tests\TestCase;

class UserVerificationSnapshotTest extends TestCase
{
    public function test_it_stores_id_card_as_plaintext(): void
    {
        $plainIdCard = '320505199001010012';

        $user = new User;
        $user->setRawAttributes([
            'id_card' => $plainIdCard,
        ], true);

        $this->assertSame($plainIdCard, $user->id_card);
    }

    public function test_it_keeps_plain_user_id_card_attribute_readable(): void
    {
        $plainIdCard = '320505199001010012';

        $user = new User;
        $user->setRawAttributes([
            'id_card' => $plainIdCard,
        ], true);

        $this->assertSame($plainIdCard, $user->id_card);
    }

    public function test_it_uses_users_table_nickname_for_display_name(): void
    {
        $user = new User;
        $user->exists = true;
        $user->setRawAttributes([
            'nickname' => 'user-nickname',
            'email' => 'demo@example.com',
            'phone' => '',
            'real_name' => '',
            'verification_status' => 0,
            'is_verified' => 0,
        ], true);

        $this->assertSame('user-nickname', $user->nickname);
        $this->assertSame('user-nickname', $user->display_name);
    }

    public function test_it_prefers_real_name_for_display_name_after_verification(): void
    {
        $user = new User;
        $user->setRawAttributes([
            'nickname' => 'user-nickname',
            'email' => 'demo@example.com',
            'phone' => '13800138000',
            'real_name' => 'verified-name',
            'verification_status' => 2,
            'is_verified' => 1,
        ], true);

        $this->assertSame('verified-name', $user->display_name);
    }

    public function test_it_treats_successful_verification_status_as_completed(): void
    {
        $user = new User;
        $user->setRawAttributes([
            'is_verified' => 0,
            'verification_status' => 2,
        ], true);

        $this->assertTrue($user->hasCompletedVerification());
    }

    public function test_it_treats_legacy_verified_flag_as_completed(): void
    {
        $user = new User;
        $user->setRawAttributes([
            'is_verified' => 1,
            'verification_status' => 0,
        ], true);

        $this->assertTrue($user->hasCompletedVerification());
    }

    public function test_it_falls_back_to_email_or_phone_for_display_name_when_nickname_missing(): void
    {
        $emailUser = new User;
        $emailUser->setRawAttributes([
            'nickname' => '',
            'email' => 'demo@example.com',
            'phone' => '',
            'real_name' => '',
            'verification_status' => 0,
            'is_verified' => 0,
        ], true);

        $phoneUser = new User;
        $phoneUser->setRawAttributes([
            'nickname' => '',
            'email' => '',
            'phone' => '13800138000',
            'real_name' => '',
            'verification_status' => 0,
            'is_verified' => 0,
        ], true);

        $this->assertSame('demo@example.com', $emailUser->display_name);
        $this->assertSame('13800138000', $phoneUser->display_name);
    }
}
