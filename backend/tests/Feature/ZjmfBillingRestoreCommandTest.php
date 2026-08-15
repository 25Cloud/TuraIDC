<?php

declare(strict_types=1);

namespace Tests\Feature;

use TuraIDC\Plugins\Servers\ZjmfFinance\Lib\ZjmfBillingRestoreService;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ZjmfBillingRestoreCommandTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->activateIntegrationPluginForTest('upstream', 'zjmf_finance');
    }

    public function test_restore_zjmf_billing_requires_confirmation_phrase(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'restore-sql-');
        file_put_contents($path, '');

        try {
            $this->artisan('finance:restore-zjmf-billing', [
                'dump' => $path,
                '--dry-run' => true,
            ])->assertExitCode(2);
        } finally {
            @unlink($path);
        }
    }

    public function test_restore_zjmf_billing_accepts_zjmf_confirmation_phrase(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'restore-sql-');
        file_put_contents($path, '');

        $this->app->instance(ZjmfBillingRestoreService::class, $this->fakeRestoreService());

        try {
            $this->artisan('finance:restore-zjmf-billing', [
                'dump' => $path,
                '--confirm' => 'RESTORE_ZJMF_BILLING',
                '--dry-run' => true,
            ])->assertExitCode(0);
        } finally {
            @unlink($path);
        }
    }

    public function test_restore_zjmf_billing_rejects_legacy_mofang_confirmation_phrase(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'restore-sql-');
        file_put_contents($path, '');

        $this->app->instance(ZjmfBillingRestoreService::class, $this->fakeRestoreService());

        try {
            $this->artisan('finance:restore-zjmf-billing', [
                'dump' => $path,
                '--confirm' => 'RESTORE_MOFANG_BILLING',
                '--dry-run' => true,
            ])->assertExitCode(2);
        } finally {
            @unlink($path);
        }
    }

    public function test_restore_rejects_non_empty_target_without_force(): void
    {
        // 目标 invoices 表存在既有数据时，未显式 --force 必须拒绝物理删除覆盖，
        // 防止误把 dump 快照后的新账单抹掉。
        $userId = (int) DB::table('users')->value('id');
        $invoiceNo = 'RESTORE-GUARD-'.strtoupper(bin2hex(random_bytes(4)));
        DB::table('invoices')->insert([
            'invoice_no' => $invoiceNo,
            'user_id' => $userId,
            'amount' => '0.01',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $path = tempnam(sys_get_temp_dir(), 'restore-sql-');
        file_put_contents($path, '');

        try {
            $this->expectException(\RuntimeException::class);
            $this->expectExceptionMessage('--force');
            app(ZjmfBillingRestoreService::class)->restoreFromSqlDump($path, false, false);
        } finally {
            @unlink($path);
            DB::table('invoices')->where('invoice_no', $invoiceNo)->delete();
        }
    }

    private function fakeRestoreService(): ZjmfBillingRestoreService
    {
        return new class extends ZjmfBillingRestoreService
        {
            public function restoreFromSqlDump(string $dumpPath, bool $dryRun = false, bool $forceOverwrite = false): array
            {
                return [
                    'dry_run' => $dryRun,
                    'invoices' => 0,
                    'balance_logs' => 0,
                    'user_balances' => 0,
                    'skipped_missing_users' => 0,
                    'skipped_deleted_invoices' => 0,
                    'existing_invoices' => 0,
                    'existing_balance_logs' => 0,
                    'overwrite_forced' => $forceOverwrite,
                ];
            }
        };
    }
}
