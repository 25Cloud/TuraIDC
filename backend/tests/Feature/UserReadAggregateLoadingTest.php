<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Http\Resources\Admin\V2\AdminUserListItemResource;
use App\Http\Resources\User\UserResource;
use App\Models\User;
use App\Models\UserAccount;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class UserReadAggregateLoadingTest extends TestCase
{
    public function test_user_resources_do_not_trigger_extra_queries_when_read_aggregates_are_preloaded(): void
    {
        $suffix = bin2hex(random_bytes(4));
        $user = User::query()->create([
            'email' => "user-read-{$suffix}@example.com",
            'password' => 'secret123',
            'phone' => '131'.str_pad((string) random_int(0, 99999999), 8, '0', STR_PAD_LEFT),
            'status' => 1,
            'nickname' => '测试昵称',
            'real_name' => '张三',
            'verification_status' => 2,
        ]);

        UserAccount::query()->create([
            'user_id' => (int) $user->id,
            'cash_balance' => '88.80',
            'credit_limit' => '20.00',
            'referral_frozen_balance' => '0.00',
            'referral_available_balance' => '0.00',
            'referral_pending_withdrawal_balance' => '0.00',
            'referral_withdrawn_balance' => '0.00',
            'version' => 0,
        ]);

        $loadedUser = User::query()->withReadAggregates()->findOrFail((int) $user->id);
        $loadedCollection = User::query()->withReadAggregates()->whereKey((int) $user->id)->get();

        DB::flushQueryLog();
        DB::enableQueryLog();

        $detailPayload = (new UserResource($loadedUser))->resolve();
        $listPayload = AdminUserListItemResource::collection($loadedCollection)->resolve();

        $queries = DB::getQueryLog();
        DB::disableQueryLog();

        $this->assertCount(0, $queries);
        $this->assertSame('测试昵称', $detailPayload['nickname'] ?? null);
        $this->assertSame('88.80', $detailPayload['cash_balance'] ?? null);
        $this->assertArrayNotHasKey('balance', $detailPayload);
        $this->assertSame('测试昵称', $listPayload[0]['nickname'] ?? null);
        $this->assertSame(88.8, (float) ($listPayload[0]['cash_balance'] ?? 0));
        $this->assertArrayNotHasKey('balance', $listPayload[0]);
    }

    public function test_user_model_with_read_aggregates_source_no_longer_uses_schema_probe(): void
    {
        $content = file_get_contents(base_path('app/Models/User.php'));

        $this->assertIsString($content);
        $this->assertStringNotContainsString("loadMissing('profile')", $content);
        $this->assertStringNotContainsString('profileTableAvailable', $content);
        $this->assertStringNotContainsString('accountTableAvailable', $content);
    }
}
