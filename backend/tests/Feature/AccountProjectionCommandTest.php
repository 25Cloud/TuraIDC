<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Services\User\UserAccountProjectionService;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;

class AccountProjectionCommandTest extends BaseTestCase
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

    public function test_account_check_projections_reports_missing_accounts(): void
    {
        $before = app(UserAccountProjectionService::class)->inspect();
        $this->insertUserWithoutAccount('account-check-'.uniqid('', true).'@example.test');

        Artisan::call('account:check-projections', ['--json' => true]);

        $payload = json_decode(Artisan::output(), true, 512, JSON_THROW_ON_ERROR);

        $this->assertSame(
            (int) $before['users_without_account'] + 1,
            (int) $payload['users_without_account']
        );
    }

    public function test_account_backfill_user_accounts_dry_run_does_not_write(): void
    {
        $userId = $this->insertUserWithoutAccount('account-dry-run-'.uniqid('', true).'@example.test');

        Artisan::call('account:backfill-user-accounts', ['--json' => true]);

        $payload = json_decode(Artisan::output(), true, 512, JSON_THROW_ON_ERROR);

        $this->assertTrue((bool) $payload['dry_run']);
        $this->assertSame(0, (int) $payload['inserted']);
        $this->assertDatabaseMissing('user_accounts', ['user_id' => $userId]);
    }

    public function test_account_backfill_user_accounts_execute_inserts_missing_accounts(): void
    {
        $backupPath = null;

        try {
            $userId = $this->insertUserWithoutAccount('account-execute-'.uniqid('', true).'@example.test');

            Artisan::call('account:backfill-user-accounts', [
                '--execute' => true,
                '--json' => true,
            ]);

            $payload = json_decode(Artisan::output(), true, 512, JSON_THROW_ON_ERROR);
            $backupPath = $payload['backup_path'] ?? null;

            $this->assertFalse((bool) $payload['dry_run']);
            $this->assertGreaterThanOrEqual(1, (int) $payload['inserted']);
            // users 旧余额列已删除，缺失账户按默认 0 回填。
            $this->assertDatabaseHas('user_accounts', [
                'user_id' => $userId,
                'cash_balance' => '0.00',
                'credit_limit' => '0.00',
                'referral_frozen_balance' => '0.00',
                'referral_available_balance' => '0.00',
                'referral_pending_withdrawal_balance' => '0.00',
                'referral_withdrawn_balance' => '0.00',
            ]);
            $this->assertIsString($backupPath);
            $this->assertFileExists($backupPath);
        } finally {
            if (is_string($backupPath) && File::exists($backupPath)) {
                File::delete($backupPath);
            }
        }
    }

    private function createSchema(): void
    {
        Schema::dropIfExists('user_accounts');
        Schema::dropIfExists('users');

        Schema::create('users', function (Blueprint $table): void {
            $table->id();
            $table->string('email')->nullable()->unique();
            $table->string('password');
            $table->string('nickname')->default('');
            $table->string('phone')->nullable()->unique();
            $table->tinyInteger('status')->default(1);
            $table->timestamps();
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

    private function insertUserWithoutAccount(string $email): int
    {
        return (int) DB::table('users')->insertGetId([
            'email' => $email,
            'password' => bcrypt('password'),
            'nickname' => '',
            'phone' => null,
            'status' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
