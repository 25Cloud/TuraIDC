<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Exceptions\BusinessException;
use App\Models\User;
use App\Services\User\UserService;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class AdminUserPhoneUniquenessTest extends TestCase
{
    public function test_user_service_create_allows_empty_nickname_without_database_error(): void
    {
        $suffix = bin2hex(random_bytes(4));

        $user = app(UserService::class)->create([
            'email' => "user-empty-nickname-{$suffix}@example.com",
            'password' => 'secret123',
            'phone' => '138'.str_pad((string) random_int(0, 99999999), 8, '0', STR_PAD_LEFT),
            'status' => 1,
        ]);

        $this->assertSame('', (string) DB::table('users')->where('id', (int) $user->id)->value('nickname'));
    }

    public function test_user_service_create_and_update_reject_duplicate_phone(): void
    {
        $suffix = bin2hex(random_bytes(4));
        $phone = '137'.str_pad((string) random_int(0, 99999999), 8, '0', STR_PAD_LEFT);

        User::query()->create([
            'email' => "user-phone-a-{$suffix}@example.com",
            'password' => 'secret123',
            'phone' => $phone,
            'status' => 1,
        ]);

        $service = app(UserService::class);

        try {
            $service->create([
                'email' => "user-phone-b-{$suffix}@example.com",
                'password' => 'secret123',
                'phone' => '86 '.$phone,
                'status' => 1,
            ]);
            $this->fail('Expected duplicate phone create to fail.');
        } catch (BusinessException $exception) {
            $this->assertSame('手机号已被注册', $exception->getMessage());
        }

        $second = User::query()->create([
            'email' => "user-phone-c-{$suffix}@example.com",
            'password' => 'secret123',
            'phone' => '139'.str_pad((string) random_int(0, 99999999), 8, '0', STR_PAD_LEFT),
            'status' => 1,
        ]);

        try {
            $service->update($second, [
                'phone' => $phone,
            ]);
            $this->fail('Expected duplicate phone update to fail.');
        } catch (BusinessException $exception) {
            $this->assertSame('手机号已被注册', $exception->getMessage());
        }

        $this->assertSame($phone, (string) User::query()->where('email', "user-phone-a-{$suffix}@example.com")->value('phone'));
    }

    public function test_users_phone_unique_index_exists(): void
    {
        $indexes = DB::select("
            SELECT INDEX_NAME
            FROM information_schema.statistics
            WHERE table_schema = DATABASE()
              AND table_name = 'users'
              AND index_name = 'users_phone_unique'
            LIMIT 1
        ");

        $this->assertNotEmpty($indexes);
    }
}
