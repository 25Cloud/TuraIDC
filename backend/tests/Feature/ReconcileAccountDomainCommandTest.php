<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class ReconcileAccountDomainCommandTest extends TestCase
{
    public function test_it_includes_referral_relations_in_reconcile_summary_and_orphans(): void
    {
        $sourceDatabasePath = storage_path('framework/testing/reconcile-account-domain-source.sqlite');
        $targetDatabasePath = storage_path('framework/testing/reconcile-account-domain-target.sqlite');

        $sourceConnection = 'legacy_source';
        $targetConnection = 'legacy_target';

        $this->prepareSqliteDatabase($sourceConnection, $sourceDatabasePath);
        $this->prepareSqliteDatabase($targetConnection, $targetDatabasePath);
        config()->set('account_migration.source_connection', $sourceConnection);
        config()->set('account_migration.target_connection', $targetConnection);

        $sourceSchema = Schema::connection($sourceConnection);
        $targetSchema = Schema::connection($targetConnection);

        $sourceSchema->create('users', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('referrer_user_id')->nullable();
            $table->string('referral_code', 24)->nullable();
            $table->timestamp('referred_at')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });

        $sourceSchema->create('referral_rewards', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('referrer_user_id')->nullable();
            $table->unsignedBigInteger('referred_user_id')->nullable();
            $table->unsignedBigInteger('order_id')->nullable();
            $table->unsignedBigInteger('product_id')->nullable();
            $table->decimal('order_amount', 12, 2)->default(0);
            $table->decimal('reward_rate', 5, 2)->default(0);
            $table->decimal('reward_amount', 12, 2)->default(0);
            $table->timestamp('available_at')->nullable();
            $table->timestamp('released_at')->nullable();
            $table->tinyInteger('status')->default(0);
            $table->string('trace_id', 64)->nullable();
            $table->string('remark')->nullable();
            $table->timestamps();
        });

        $sourceSchema->create('referral_withdrawals', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('user_id')->nullable();
            $table->decimal('amount', 12, 2)->default(0);
            $table->string('method', 20)->nullable();
            $table->string('account_name', 80)->nullable();
            $table->string('account_no', 120)->nullable();
            $table->tinyInteger('status')->default(0);
            $table->timestamp('processed_at')->nullable();
            $table->string('remark')->nullable();
            $table->string('trace_id', 64)->nullable();
            $table->timestamps();
        });

        $sourceSchema->create('balance_logs', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('user_id')->nullable();
            $table->string('event_type', 30)->nullable();
            $table->decimal('change_amount', 12, 2)->default(0);
            $table->decimal('balance_after', 12, 2)->default(0);
            $table->unsignedBigInteger('reference_id')->nullable();
            $table->string('remark')->nullable();
            $table->timestamp('created_at')->nullable();
        });

        $sourceSchema->create('referral_account_logs', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('user_id')->nullable();
            $table->string('event_type', 30)->nullable();
            $table->decimal('change_amount', 12, 2)->default(0);
            $table->decimal('frozen_balance', 12, 2)->default(0);
            $table->decimal('available_balance', 12, 2)->default(0);
            $table->decimal('pending_withdrawal_balance', 12, 2)->default(0);
            $table->decimal('withdrawn_balance', 12, 2)->default(0);
            $table->unsignedBigInteger('reference_id')->nullable();
            $table->string('remark')->nullable();
            $table->string('trace_id', 64)->nullable();
            $table->timestamp('created_at')->nullable();
        });

        $sourceSchema->create('invoices', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('order_id')->nullable();
        });

        $targetSchema->create('users', function (Blueprint $table): void {
            $table->id();
            $table->timestamps();
        });

        $targetSchema->create('products', function (Blueprint $table): void {
            $table->id();
        });

        $targetSchema->create('invoices', function (Blueprint $table): void {
            $table->id();
            $table->json('product_snapshot_json')->nullable();
        });

        $targetSchema->create('user_accounts', function (Blueprint $table): void {
            $table->unsignedBigInteger('user_id')->primary();
            $table->decimal('cash_balance', 12, 2)->default(0);
            $table->decimal('referral_frozen_balance', 12, 2)->default(0);
            $table->decimal('referral_available_balance', 12, 2)->default(0);
            $table->decimal('referral_pending_withdrawal_balance', 12, 2)->default(0);
            $table->decimal('referral_withdrawn_balance', 12, 2)->default(0);
        });

        $targetSchema->create('referral_rewards', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('referrer_user_id')->nullable();
            $table->unsignedBigInteger('referred_user_id')->nullable();
            $table->unsignedBigInteger('source_invoice_id')->nullable();
            $table->decimal('reward_amount', 12, 2)->default(0);
            $table->tinyInteger('status')->default(0);
        });

        $targetSchema->create('withdrawals', function (Blueprint $table): void {
            $table->id();
            $table->string('withdrawal_no', 32)->nullable();
            $table->unsignedBigInteger('user_id')->nullable();
            $table->decimal('amount', 12, 2)->default(0);
            $table->tinyInteger('status')->default(0);
        });

        $targetSchema->create('account_ledgers', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('user_id')->nullable();
            $table->string('account_type', 30)->nullable();
            $table->string('direction', 10)->nullable();
            $table->decimal('amount', 12, 2)->default(0);
        });

        $targetSchema->create('account_balance_snapshots', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('user_id')->nullable();
            $table->string('account_type', 30);
            $table->date('snapshot_date');
        });

        $targetSchema->create('referral_relations', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('referrer_user_id');
            $table->unsignedBigInteger('referred_user_id');
            $table->string('referral_code_snapshot', 24)->nullable();
            $table->timestamp('bound_at')->nullable();
            $table->timestamps();
        });

        DB::connection($sourceConnection)->table('users')->insert([
            [
                'id' => 62,
                'referrer_user_id' => null,
                'referral_code' => 'ROOT-62',
                'referred_at' => null,
                'created_at' => '2026-05-18 00:00:00',
                'updated_at' => '2026-05-18 00:00:00',
                'deleted_at' => null,
            ],
            [
                'id' => 63,
                'referrer_user_id' => 62,
                'referral_code' => 'INVITE-63',
                'referred_at' => '2026-05-18 01:39:01',
                'created_at' => '2026-05-18 01:00:00',
                'updated_at' => '2026-05-18 01:40:01',
                'deleted_at' => null,
            ],
            [
                'id' => 64,
                'referrer_user_id' => 999,
                'referral_code' => 'INVITE-64',
                'referred_at' => '2026-05-18 01:50:01',
                'created_at' => '2026-05-18 01:10:00',
                'updated_at' => '2026-05-18 01:50:01',
                'deleted_at' => null,
            ],
            [
                'id' => 65,
                'referrer_user_id' => 65,
                'referral_code' => 'SELF-65',
                'referred_at' => '2026-05-18 01:55:01',
                'created_at' => '2026-05-18 01:20:00',
                'updated_at' => '2026-05-18 01:55:01',
                'deleted_at' => null,
            ],
        ]);
        DB::connection($sourceConnection)->table('referral_rewards')->insert([
            [
                'id' => 1,
                'referrer_user_id' => 62,
                'referred_user_id' => 63,
                'order_id' => null,
                'product_id' => null,
                'order_amount' => '100.00',
                'reward_rate' => '5.00',
                'reward_amount' => '5.00',
                'available_at' => null,
                'released_at' => null,
                'status' => 0,
                'trace_id' => null,
                'remark' => null,
                'created_at' => '2026-05-18 01:00:00',
                'updated_at' => '2026-05-18 01:00:00',
            ],
            [
                'id' => 2,
                'referrer_user_id' => 62,
                'referred_user_id' => 64,
                'order_id' => null,
                'product_id' => null,
                'order_amount' => '200.00',
                'reward_rate' => '10.00',
                'reward_amount' => '20.00',
                'available_at' => null,
                'released_at' => null,
                'status' => 1,
                'trace_id' => null,
                'remark' => null,
                'created_at' => '2026-05-18 01:00:00',
                'updated_at' => '2026-05-18 01:00:00',
            ],
        ]);
        DB::connection($sourceConnection)->table('referral_withdrawals')->insert([
            [
                'id' => 1,
                'user_id' => 62,
                'amount' => '30.00',
                'method' => 'alipay',
                'account_name' => 'tester-1',
                'account_no' => 'acc-1',
                'status' => 0,
                'processed_at' => null,
                'remark' => null,
                'trace_id' => null,
                'created_at' => '2026-05-18 01:00:00',
                'updated_at' => '2026-05-18 01:00:00',
            ],
            [
                'id' => 2,
                'user_id' => 63,
                'amount' => '40.00',
                'method' => 'alipay',
                'account_name' => 'tester-2',
                'account_no' => 'acc-2',
                'status' => 1,
                'processed_at' => '2026-05-18 01:10:00',
                'remark' => null,
                'trace_id' => null,
                'created_at' => '2026-05-18 01:00:00',
                'updated_at' => '2026-05-18 01:10:00',
            ],
        ]);

        DB::connection($targetConnection)->table('users')->insert([
            ['id' => 62, 'created_at' => '2026-05-18 00:00:00', 'updated_at' => '2026-05-18 00:00:00'],
            ['id' => 63, 'created_at' => '2026-05-18 00:00:00', 'updated_at' => '2026-05-18 00:00:00'],
            ['id' => 64, 'created_at' => '2026-05-18 00:00:00', 'updated_at' => '2026-05-18 00:00:00'],
        ]);

        DB::connection($targetConnection)->table('referral_relations')->insert([
            [
                'id' => 1,
                'referrer_user_id' => 62,
                'referred_user_id' => 63,
                'referral_code_snapshot' => 'INVITE-63',
                'bound_at' => '2026-05-18 01:39:01',
                'created_at' => '2026-05-18 01:00:00',
                'updated_at' => '2026-05-18 01:40:01',
            ],
            [
                'id' => 2,
                'referrer_user_id' => 999,
                'referred_user_id' => 64,
                'referral_code_snapshot' => 'INVITE-64',
                'bound_at' => '2026-05-18 01:50:01',
                'created_at' => '2026-05-18 01:10:00',
                'updated_at' => '2026-05-18 01:50:01',
            ],
        ]);
        DB::connection($targetConnection)->table('referral_rewards')->insert([
            [
                'id' => 1,
                'referrer_user_id' => 62,
                'referred_user_id' => 63,
                'source_invoice_id' => null,
                'reward_amount' => '5.00',
                'status' => 0,
            ],
            [
                'id' => 2,
                'referrer_user_id' => 62,
                'referred_user_id' => 64,
                'source_invoice_id' => null,
                'reward_amount' => '20.00',
                'status' => 1,
            ],
        ]);
        DB::connection($targetConnection)->table('withdrawals')->insert([
            [
                'id' => 1,
                'withdrawal_no' => 'WD00000001',
                'user_id' => 62,
                'amount' => '30.00',
                'status' => 0,
            ],
            [
                'id' => 2,
                'withdrawal_no' => 'WD00000001',
                'user_id' => 63,
                'amount' => '40.00',
                'status' => 1,
            ],
        ]);
        DB::connection($targetConnection)->table('account_ledgers')->insert([
            [
                'id' => 1,
                'user_id' => 62,
                'account_type' => 'cash',
                'direction' => 'credit',
                'amount' => '20.00',
            ],
            [
                'id' => 2,
                'user_id' => 63,
                'account_type' => 'cash',
                'direction' => 'credit',
                'amount' => '15.00',
            ],
        ]);
        DB::connection($targetConnection)->table('user_accounts')->insert([
            [
                'user_id' => 62,
                'cash_balance' => '20.00',
                'referral_frozen_balance' => '0.00',
                'referral_available_balance' => '0.00',
                'referral_pending_withdrawal_balance' => '0.00',
                'referral_withdrawn_balance' => '0.00',
            ],
            [
                'user_id' => 63,
                'cash_balance' => '10.00',
                'referral_frozen_balance' => '0.00',
                'referral_available_balance' => '0.00',
                'referral_pending_withdrawal_balance' => '0.00',
                'referral_withdrawn_balance' => '0.00',
            ],
        ]);

        $exitCode = Artisan::call('migrate:account:reconcile', ['--json' => true]);
        $this->assertSame(0, $exitCode);

        $output = trim(Artisan::output());
        $payload = json_decode($output, true, 512, JSON_THROW_ON_ERROR);

        $this->assertSame(1, $payload['summary']['referral_relations']['old_derived']);
        $this->assertSame(2, $payload['summary']['referral_relations']['new']);
        $this->assertSame(1, $payload['orphans']['referral_relations.referrer_user_id']);
        $this->assertSame(0, $payload['orphans']['referral_relations.referred_user_id']);
        $this->assertSame(1, $payload['uniques']['withdrawals.withdrawal_no']);
        $this->assertSame(['0' => 1, '1' => 1], $payload['statuses']['referral_rewards']['old']);
        $this->assertSame(['0' => 1, '1' => 1], $payload['statuses']['referral_rewards']['new']);
        $this->assertSame(['0' => 1, '1' => 1], $payload['statuses']['withdrawals']['old']);
        $this->assertSame(['0' => 1, '1' => 1], $payload['statuses']['withdrawals']['new']);
        $this->assertSame(1, $payload['ledger_balance_diff']['count']);
        $this->assertSame([
            [
                'user_id' => 63,
                'account_type' => 'cash',
                'ledger_net' => '15.00',
                'account_balance' => '10.00',
                'diff' => '5.00',
            ],
        ], $payload['ledger_balance_diff']['rows']);
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
