<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Console\Commands\MigrateIdentityUserAccountsCommand;
use App\Services\System\IdentityMigrationService;
use Illuminate\Console\OutputStyle;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Tests\TestCase;

class IdentityUserAccountsMigrationCommandTest extends TestCase
{
    public function test_it_backfills_reconciliation_fields_and_balance_diff_for_user_accounts(): void
    {
        $sourceDatabasePath = storage_path('framework/testing/identity-user-accounts-source.sqlite');
        $targetDatabasePath = storage_path('framework/testing/identity-user-accounts-target.sqlite');

        $sourceConnection = 'legacy_source';
        $targetConnection = 'legacy_target';

        $this->prepareSqliteDatabase($sourceConnection, $sourceDatabasePath);
        $this->prepareSqliteDatabase($targetConnection, $targetDatabasePath);
        config()->set('identity_migration.source_connection', $sourceConnection);
        config()->set('identity_migration.target_connection', $targetConnection);

        $sourceSchema = Schema::connection($sourceConnection);
        $targetSchema = Schema::connection($targetConnection);

        $sourceSchema->create('users', function (Blueprint $table): void {
            $table->id();
            $table->decimal('balance', 12, 2)->default(0);
            $table->timestamps();
        });

        $sourceSchema->create('user_accounts', function (Blueprint $table): void {
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

        $targetSchema->create('users', function (Blueprint $table): void {
            $table->id();
            $table->timestamps();
        });

        $targetSchema->create('identity_migration_checkpoints', function (Blueprint $table): void {
            $table->string('migration_name', 100)->primary();
            $table->timestamp('completed_at');
            $table->unsignedInteger('row_count')->default(0);
            $table->timestamp('created_at');
        });

        $targetSchema->create('user_accounts', function (Blueprint $table): void {
            $table->unsignedBigInteger('user_id')->primary();
            $table->decimal('cash_balance', 12, 2)->default(0);
            $table->decimal('credit_limit', 12, 2)->default(0);
            $table->decimal('referral_frozen_balance', 12, 2)->default(0);
            $table->decimal('referral_available_balance', 12, 2)->default(0);
            $table->decimal('referral_withdrawing_balance', 12, 2)->default(0);
            $table->decimal('referral_withdrawn_balance', 12, 2)->default(0);
            $table->decimal('frozen_cash_balance', 12, 2)->default(0);
            $table->timestamp('last_reconciled_at')->nullable();
            $table->decimal('migrated_balance_diff', 12, 2)->default(0);
            $table->unsignedInteger('version')->default(0);
            $table->timestamps();
        });

        DB::connection($sourceConnection)->table('users')->insert([
            [
                'id' => 50,
                'balance' => '188.80',
                'created_at' => '2026-05-18 01:00:00',
                'updated_at' => '2026-05-18 01:00:00',
            ],
            [
                'id' => 51,
                'balance' => '20.00',
                'created_at' => '2026-05-18 01:10:00',
                'updated_at' => '2026-05-18 01:10:00',
            ],
        ]);

        DB::connection($sourceConnection)->table('user_accounts')->insert([
            [
                'user_id' => 50,
                'cash_balance' => '100.00',
                'credit_limit' => '0.00',
                'referral_frozen_balance' => '1.00',
                'referral_available_balance' => '2.00',
                'referral_pending_withdrawal_balance' => '3.00',
                'referral_withdrawn_balance' => '4.00',
                'version' => 7,
                'created_at' => '2026-05-18 01:00:00',
                'updated_at' => '2026-05-18 01:20:00',
            ],
        ]);

        DB::connection($targetConnection)->table('users')->insert([
            ['id' => 50, 'created_at' => '2026-05-18 01:00:00', 'updated_at' => '2026-05-18 01:00:00'],
            ['id' => 51, 'created_at' => '2026-05-18 01:10:00', 'updated_at' => '2026-05-18 01:10:00'],
        ]);

        DB::connection($targetConnection)->table('identity_migration_checkpoints')->insert([
            'migration_name' => 'identity_users',
            'completed_at' => '2026-05-18 02:00:00',
            'row_count' => 2,
            'created_at' => '2026-05-18 02:00:00',
        ]);

        $service = new class extends IdentityMigrationService
        {
            public function isMigrationCompleted(string $migrationName): bool
            {
                return $migrationName === 'identity_users';
            }

            public function getColumnNames(string $connection, string $table): array
            {
                return Schema::connection($connection)->getColumnListing($table);
            }

            public function batchInsertIgnore(string $table, array $columns, array $rows): int
            {
                if ($rows === []) {
                    return 0;
                }

                return DB::connection($this->targetConnection())
                    ->table($table)
                    ->insertOrIgnore($rows);
            }
        };

        $this->app->instance(IdentityMigrationService::class, $service);

        $command = app(MigrateIdentityUserAccountsCommand::class);
        $command->setLaravel($this->app);
        $command->setOutput(new OutputStyle(new ArrayInput([]), new BufferedOutput));

        $method = new \ReflectionMethod($command, 'migrateRows');
        $method->setAccessible(true);
        $migrated = $method->invoke($command, [], 500);

        $this->assertSame(2, $migrated);

        $rows = DB::connection($targetConnection)
            ->table('user_accounts')
            ->orderBy('user_id')
            ->get()
            ->map(fn ($row) => (array) $row)
            ->all();

        $this->assertCount(2, $rows);
        $this->assertSame('3.00', number_format((float) $rows[0]['referral_withdrawing_balance'], 2, '.', ''));
        $this->assertSame('0.00', number_format((float) $rows[0]['frozen_cash_balance'], 2, '.', ''));
        $this->assertNull($rows[0]['last_reconciled_at']);
        $this->assertSame('88.80', number_format((float) $rows[0]['migrated_balance_diff'], 2, '.', ''));

        $this->assertSame(51, (int) $rows[1]['user_id']);
        $this->assertSame('0.00', number_format((float) $rows[1]['cash_balance'], 2, '.', ''));
        $this->assertSame('0.00', number_format((float) $rows[1]['migrated_balance_diff'], 2, '.', ''));
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
