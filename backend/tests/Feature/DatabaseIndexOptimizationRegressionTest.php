<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class DatabaseIndexOptimizationRegressionTest extends TestCase
{
    public function test_users_email_unique_index_targets_email_column(): void
    {
        $rows = DB::select("
            SELECT
                index_name AS index_name,
                non_unique AS non_unique,
                column_name AS column_name,
                seq_in_index AS seq_in_index
            FROM information_schema.statistics
            WHERE table_schema = DATABASE()
              AND table_name = 'users'
              AND index_name = 'users_email_unique'
            ORDER BY seq_in_index
        ");

        $this->assertCount(1, $rows);
        $this->assertSame('users_email_unique', (string) $rows[0]->index_name);
        $this->assertSame(0, (int) $rows[0]->non_unique);
        $this->assertSame('email', (string) $rows[0]->column_name);
    }

    public function test_operation_log_indexes_exist_and_redundant_indexes_are_removed(): void
    {
        $operationLogIndexes = $this->indexNamesFor('operation_logs');

        $this->assertContains('operation_logs_created_at_idx', $operationLogIndexes);
        $this->assertContains('operation_logs_user_created_at_idx', $operationLogIndexes);
        $this->assertContains('operation_logs_module_created_at_index', $operationLogIndexes);

        $usersIndexes = $this->indexNamesFor('users');
        $servicesIndexes = $this->indexNamesFor('services');
        $ticketsIndexes = $this->indexNamesFor('tickets');
        $balanceLogIndexes = $this->indexNamesFor('balance_logs');

        $this->assertNotContains('users_phone_idx', $usersIndexes);
        $this->assertNotContains('users_referral_code_index', $usersIndexes);
        $this->assertNotContains('users_status_index', $usersIndexes);
        $this->assertNotContains('services_status_index', $servicesIndexes);
        $this->assertNotContains('tickets_status_index', $ticketsIndexes);
        $this->assertNotContains('balance_logs_user_id_index', $balanceLogIndexes);
    }

    /**
     * @return list<string>
     */
    private function indexNamesFor(string $tableName): array
    {
        return collect(DB::select('
            SELECT DISTINCT index_name AS index_name
            FROM information_schema.statistics
            WHERE table_schema = DATABASE()
              AND table_name = ?
        ', [$tableName]))
            ->map(fn (object $row) => (string) $row->index_name)
            ->values()
            ->all();
    }
}
