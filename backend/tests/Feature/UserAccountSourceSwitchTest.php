<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
use App\Services\User\AccountService;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class UserAccountSourceSwitchTest extends BaseTestCase
{
    public function createApplication()
    {
        $app = require __DIR__.'/../../bootstrap/app.php';
        $app->make(Kernel::class)->bootstrap();

        return $app;
    }

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('database.default', 'sqlite');
        config()->set('database.connections.sqlite', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
            'foreign_key_constraints' => false,
        ]);
        DB::purge('sqlite');
        DB::reconnect('sqlite');
        app('db')->setDefaultConnection('sqlite');

        $this->createSchema();
    }

    public function test_user_account_accessors_read_user_accounts_without_legacy_fallback(): void
    {
        $userId = $this->insertUserWithAccount();

        $user = User::query()->findOrFail($userId);

        $this->assertFalse($user->relationLoaded('account'));
        $this->assertSame('12.34', $user->balance);
        $this->assertSame('56.78', $user->credit_limit);
        $this->assertSame('1.23', $user->referral_frozen_amount);
        $this->assertSame('2.34', $user->referral_available_amount);

        DB::table('users')->where('id', $userId)->update([
            'balance' => '777.77',
            'credit_limit' => '888.88',
            'referral_frozen_amount' => '9.99',
            'referral_available_amount' => '8.88',
        ]);

        $freshUser = User::query()->findOrFail($userId);

        $this->assertSame('12.34', $freshUser->balance);
        $this->assertSame('56.78', $freshUser->credit_limit);
        $this->assertSame('1.23', $freshUser->referral_frozen_amount);
        $this->assertSame('2.34', $freshUser->referral_available_amount);
    }

    public function test_account_service_writes_user_accounts_without_touching_legacy_balance(): void
    {
        $userId = $this->insertUserWithAccount();
        $user = User::query()->findOrFail($userId);

        $balanceAfter = app(AccountService::class)->setCashBalance($user, 45.67);

        $this->assertSame('45.67', $balanceAfter);
        $this->assertDatabaseHas('user_accounts', [
            'user_id' => $userId,
            'cash_balance' => '45.67',
            'version' => 1,
        ]);
        $this->assertDatabaseHas('users', [
            'id' => $userId,
            'balance' => '999.99',
        ]);
        $this->assertSame('45.67', (User::query()->findOrFail($userId))->balance);
    }

    private function createSchema(): void
    {
        Schema::dropIfExists('user_accounts');
        Schema::dropIfExists('users');

        Schema::create('users', function (Blueprint $table): void {
            $table->id();
            $table->string('email')->nullable()->unique();
            $table->string('password');
            $table->string('phone')->nullable()->unique();
            $table->tinyInteger('status')->default(1);
            $table->decimal('balance', 12, 2)->default(0);
            $table->decimal('credit_limit', 12, 2)->default(0);
            $table->decimal('referral_frozen_amount', 12, 2)->default(0);
            $table->decimal('referral_available_amount', 12, 2)->default(0);
            $table->decimal('referral_withdrawing_amount', 12, 2)->default(0);
            $table->decimal('referral_withdrawn_amount', 12, 2)->default(0);
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('user_accounts', function (Blueprint $table): void {
            $table->unsignedBigInteger('user_id')->primary();
            $table->decimal('cash_balance', 12, 2)->default(0);
            $table->decimal('credit_limit', 12, 2)->default(0);
            $table->decimal('referral_frozen_balance', 12, 2)->default(0);
            $table->decimal('referral_available_balance', 12, 2)->default(0);
            $table->decimal('referral_pending_withdrawal_balance', 12, 2)->default(0);
            $table->decimal('referral_withdrawn_balance', 12, 2)->default(0);
            $table->unsignedInteger('version')->default(0);
            $table->timestamps();
        });
    }

    private function insertUserWithAccount(): int
    {
        $userId = (int) DB::table('users')->insertGetId([
            'email' => 'account-source-'.uniqid('', true).'@example.test',
            'password' => bcrypt('password'),
            'phone' => null,
            'status' => 1,
            'balance' => '999.99',
            'credit_limit' => '999.99',
            'referral_frozen_amount' => '9.99',
            'referral_available_amount' => '8.88',
            'referral_withdrawing_amount' => '7.77',
            'referral_withdrawn_amount' => '6.66',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('user_accounts')->insert([
            'user_id' => $userId,
            'cash_balance' => '12.34',
            'credit_limit' => '56.78',
            'referral_frozen_balance' => '1.23',
            'referral_available_balance' => '2.34',
            'referral_pending_withdrawal_balance' => '3.45',
            'referral_withdrawn_balance' => '4.56',
            'version' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $userId;
    }
}
