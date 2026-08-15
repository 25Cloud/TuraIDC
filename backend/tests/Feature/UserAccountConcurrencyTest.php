<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Exceptions\BusinessException;
use App\Models\UserAccount;
use App\Services\User\AccountService;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class UserAccountConcurrencyTest extends BaseTestCase
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

    public function test_update_account_uses_the_loaded_version_and_rejects_a_stale_write(): void
    {
        $userId = $this->insertAccount('10.00', 0);
        $firstStaleAccount = UserAccount::query()->findOrFail($userId);
        $secondStaleAccount = UserAccount::query()->findOrFail($userId);

        app(AccountService::class)->updateAccount($firstStaleAccount, [
            'cash_balance' => '30.00',
        ]);

        DB::flushQueryLog();
        DB::enableQueryLog();

        try {
            app(AccountService::class)->updateAccount($secondStaleAccount, [
                'cash_balance' => '20.00',
            ]);
            $this->fail('过期版本写入必须被拒绝。');
        } catch (BusinessException $exception) {
            $this->assertSame(40900, $exception->getErrorCode());
            $this->assertSame(409, $exception->getCode());
            $this->assertSame('账户数据已被并发修改，请重试', $exception->getMessage());
        } finally {
            $queries = DB::getQueryLog();
            DB::disableQueryLog();
        }

        $updateSql = collect($queries)
            ->pluck('query')
            ->first(static fn (string $query): bool => str_starts_with(strtolower($query), 'update "user_accounts"'));

        $this->assertIsString($updateSql);
        $this->assertMatchesRegularExpression('/where.+["`]version["`]\s*=\s*\?/is', $updateSql);
        $this->assertDatabaseHas('user_accounts', [
            'user_id' => $userId,
            'cash_balance' => '30.00',
            'version' => 1,
        ]);
    }

    public function test_update_account_increments_version_after_a_successful_conditional_write(): void
    {
        $userId = $this->insertAccount('10.00', 3);
        $account = UserAccount::query()->findOrFail($userId);

        $updated = app(AccountService::class)->updateAccount($account, [
            'cash_balance' => '20.00',
        ]);

        $this->assertSame('20.00', $updated->cash_balance);
        $this->assertSame(4, $updated->version);
        $this->assertDatabaseHas('user_accounts', [
            'user_id' => $userId,
            'cash_balance' => '20.00',
            'version' => 4,
        ]);
    }

    private function createSchema(): void
    {
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

    private function insertAccount(string $cashBalance, int $version): int
    {
        $userId = random_int(100000, 999999);

        DB::table('user_accounts')->insert([
            'user_id' => $userId,
            'cash_balance' => $cashBalance,
            'credit_limit' => '0.00',
            'referral_frozen_balance' => '0.00',
            'referral_available_balance' => '0.00',
            'referral_pending_withdrawal_balance' => '0.00',
            'referral_withdrawn_balance' => '0.00',
            'version' => $version,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $userId;
    }
}
