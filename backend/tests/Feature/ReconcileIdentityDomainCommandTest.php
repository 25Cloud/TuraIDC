<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class ReconcileIdentityDomainCommandTest extends TestCase
{
    public function test_it_includes_user_account_balance_diff_summary_in_reconcile_payload(): void
    {
        $sourceDatabasePath = storage_path('framework/testing/reconcile-identity-domain-source.sqlite');
        $targetDatabasePath = storage_path('framework/testing/reconcile-identity-domain-target.sqlite');

        $sourceConnection = 'legacy_source';
        $targetConnection = 'legacy_target';

        $this->prepareSqliteDatabase($sourceConnection, $sourceDatabasePath);
        $this->prepareSqliteDatabase($targetConnection, $targetDatabasePath);
        config()->set('identity_migration.source_connection', $sourceConnection);
        config()->set('identity_migration.target_connection', $targetConnection);

        $sourceSchema = Schema::connection($sourceConnection);
        $targetSchema = Schema::connection($targetConnection);

        $sourceSchema->create('member_levels', function (Blueprint $table): void {
            $table->id();
        });
        $sourceSchema->create('users', function (Blueprint $table): void {
            $table->id();
            $table->decimal('balance', 12, 2)->default(0);
            $table->string('email')->nullable();
            $table->string('phone')->nullable();
            $table->string('referral_code')->nullable();
            $table->timestamps();
        });
        $sourceSchema->create('user_accounts', function (Blueprint $table): void {
            $table->unsignedBigInteger('user_id')->primary();
            $table->decimal('cash_balance', 12, 2)->default(0);
            $table->timestamps();
        });
        $sourceSchema->create('verification_histories', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('user_id');
        });
        $sourceSchema->create('admin_users', function (Blueprint $table): void {
            $table->id();
            $table->string('username')->nullable();
        });
        $sourceSchema->create('roles', function (Blueprint $table): void {
            $table->id();
            $table->string('name')->nullable();
        });
        $sourceSchema->create('admin_user_roles', function (Blueprint $table): void {
            $table->unsignedBigInteger('admin_user_id');
            $table->unsignedBigInteger('role_id');
        });

        $targetSchema->create('member_levels', function (Blueprint $table): void {
            $table->id();
        });
        $targetSchema->create('users', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('member_level_id')->nullable();
            $table->unsignedBigInteger('referrer_user_id')->nullable();
            $table->string('email')->nullable();
            $table->string('phone')->nullable();
            $table->string('referral_code')->nullable();
            $table->timestamps();
        });
        $targetSchema->create('user_profiles', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('user_id');
        });
        $targetSchema->create('user_accounts', function (Blueprint $table): void {
            $table->unsignedBigInteger('user_id')->primary();
            $table->decimal('cash_balance', 12, 2)->default(0);
            $table->decimal('frozen_cash_balance', 12, 2)->default(0);
            $table->timestamp('last_reconciled_at')->nullable();
            $table->decimal('migrated_balance_diff', 12, 2)->default(0);
            $table->timestamps();
        });
        $targetSchema->create('verification_histories', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('user_id');
        });
        $targetSchema->create('admin_users', function (Blueprint $table): void {
            $table->id();
            $table->string('username')->nullable();
        });
        $targetSchema->create('roles', function (Blueprint $table): void {
            $table->id();
            $table->string('name')->nullable();
        });
        $targetSchema->create('admin_user_roles', function (Blueprint $table): void {
            $table->unsignedBigInteger('admin_user_id');
            $table->unsignedBigInteger('role_id');
        });

        DB::connection($sourceConnection)->table('users')->insert([
            ['id' => 50, 'balance' => '188.80', 'email' => 'dup@example.com', 'phone' => '13800000000', 'referral_code' => 'REF-CODE', 'created_at' => '2026-05-18 01:00:00', 'updated_at' => '2026-05-18 01:00:00'],
            ['id' => 51, 'balance' => '20.00', 'email' => 'dup@example.com', 'phone' => '13800000000', 'referral_code' => 'REF-CODE', 'created_at' => '2026-05-18 01:10:00', 'updated_at' => '2026-05-18 01:10:00'],
        ]);
        DB::connection($sourceConnection)->table('admin_users')->insert([
            ['id' => 1, 'username' => 'dup-admin'],
            ['id' => 2, 'username' => 'dup-admin'],
        ]);
        DB::connection($sourceConnection)->table('roles')->insert([
            ['id' => 1, 'name' => 'dup-role'],
            ['id' => 2, 'name' => 'dup-role'],
        ]);
        DB::connection($sourceConnection)->table('user_accounts')->insert([
            ['user_id' => 50, 'cash_balance' => '100.00', 'created_at' => '2026-05-18 01:00:00', 'updated_at' => '2026-05-18 01:20:00'],
            ['user_id' => 51, 'cash_balance' => '20.00', 'created_at' => '2026-05-18 01:10:00', 'updated_at' => '2026-05-18 01:20:00'],
        ]);

        DB::connection($targetConnection)->table('users')->insert([
            [
                'id' => 50,
                'member_level_id' => 999,
                'referrer_user_id' => 51,
                'email' => 'dup@example.com',
                'phone' => '13800000000',
                'referral_code' => 'REF-CODE',
                'created_at' => '2026-05-18 01:00:00',
                'updated_at' => '2026-05-18 01:00:00',
            ],
            [
                'id' => 51,
                'member_level_id' => null,
                'referrer_user_id' => 999,
                'email' => 'dup@example.com',
                'phone' => '13800000000',
                'referral_code' => 'REF-CODE',
                'created_at' => '2026-05-18 01:10:00',
                'updated_at' => '2026-05-18 01:10:00',
            ],
        ]);
        DB::connection($targetConnection)->table('admin_users')->insert([
            ['id' => 1, 'username' => 'dup-admin'],
            ['id' => 2, 'username' => 'dup-admin'],
        ]);
        DB::connection($targetConnection)->table('roles')->insert([
            ['id' => 1, 'name' => 'dup-role'],
            ['id' => 2, 'name' => 'dup-role'],
        ]);
        DB::connection($targetConnection)->table('user_profiles')->insert([
            ['id' => 1, 'user_id' => 50],
            ['id' => 2, 'user_id' => 51],
        ]);
        DB::connection($targetConnection)->table('user_accounts')->insert([
            [
                'user_id' => 50,
                'cash_balance' => '100.00',
                'frozen_cash_balance' => '0.00',
                'last_reconciled_at' => null,
                'migrated_balance_diff' => '88.80',
                'created_at' => '2026-05-18 01:00:00',
                'updated_at' => '2026-05-18 01:20:00',
            ],
            [
                'user_id' => 51,
                'cash_balance' => '20.00',
                'frozen_cash_balance' => '0.00',
                'last_reconciled_at' => null,
                'migrated_balance_diff' => '0.00',
                'created_at' => '2026-05-18 01:10:00',
                'updated_at' => '2026-05-18 01:20:00',
            ],
        ]);

        $exitCode = Artisan::call('migrate:identity:reconcile', ['--json' => true]);

        $this->assertSame(0, $exitCode);

        $payload = json_decode(trim(Artisan::output()), true, 512, JSON_THROW_ON_ERROR);

        $this->assertSame('120', $payload['cash_balance_total']['new']);
        $this->assertSame(1, $payload['user_account_balance_diff']['count']);
        $this->assertSame('88.80', $payload['user_account_balance_diff']['sum']);
        $this->assertSame([50], $payload['user_account_balance_diff']['user_ids']);
        $this->assertSame(1, $payload['orphans']['users.member_level_id']);
        $this->assertSame(1, $payload['orphans']['users.referrer_user_id']);
        $this->assertSame(1, $payload['uniques']['users.email']);
        $this->assertSame(1, $payload['uniques']['users.phone']);
        $this->assertSame(1, $payload['uniques']['users.referral_code']);
        $this->assertSame(1, $payload['uniques']['admin_users.username']);
        $this->assertSame(1, $payload['uniques']['roles.name']);
    }

    private function prepareSqliteDatabase(string $connectionName, string $databasePath): void
    {
        $directory = dirname($databasePath);

        if (! is_dir($directory)) {
            mkdir($directory, 0777, true);
        }

        if (is_file($databasePath)) {
            unlink($databasePath);
        }

        touch($databasePath);

        config()->set('database.connections.'.$connectionName, [
            'driver' => 'sqlite',
            'database' => $databasePath,
            'prefix' => '',
            'foreign_key_constraints' => false,
        ]);

        DB::purge($connectionName);
        DB::reconnect($connectionName);
    }
}
