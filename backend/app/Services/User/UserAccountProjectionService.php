<?php

declare(strict_types=1);

namespace App\Services\User;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;

class UserAccountProjectionService
{
    private const FIELD_MAP = [
        'balance' => 'cash_balance',
        'credit_limit' => 'credit_limit',
        'referral_frozen_amount' => 'referral_frozen_balance',
        'referral_available_amount' => 'referral_available_balance',
        'referral_withdrawing_amount' => 'referral_pending_withdrawal_balance',
        'referral_withdrawn_amount' => 'referral_withdrawn_balance',
    ];

    /**
     * @return array<string, mixed>
     */
    public function inspect(int $sampleLimit = 20): array
    {
        $this->ensureTablesExist();

        return [
            'users_count' => DB::table('users')->count(),
            'user_accounts_count' => DB::table('user_accounts')->count(),
            'users_without_account' => $this->missingUsersQuery()->count(),
            'missing_user_ids' => $this->missingUsersQuery()
                ->orderBy('u.id')
                ->limit($sampleLimit)
                ->pluck('u.id')
                ->map(fn ($id): int => (int) $id)
                ->values()
                ->all(),
            'balance_mismatch_users_vs_accounts' => $this->mismatchQuery()->count(),
            'mismatch_samples' => $this->mismatchSelectQuery()
                ->orderBy('u.id')
                ->limit($sampleLimit)
                ->get()
                ->map(fn (object $row): array => (array) $row)
                ->all(),
        ];
    }

    /**
     * @return array{
     *     user_id:int,
     *     nickname:string,
     *     email:string,
     *     cash_balance:string,
     *     credit_limit:string,
     *     referral_available_balance:string,
     *     verification_status:int
     * }
     */
    public function summaryForUser(int $userId): array
    {
        $this->ensureTablesExist();

        $row = DB::table('users as u')
            ->leftJoin('user_accounts as ua', 'ua.user_id', '=', 'u.id')
            ->where('u.id', $userId)
            ->select([
                'u.id',
                'u.nickname',
                'u.email',
                'u.verification_status',
            ])
            ->selectRaw('COALESCE(ua.cash_balance, u.balance, 0) as resolved_cash_balance')
            ->selectRaw('COALESCE(ua.credit_limit, u.credit_limit, 0) as resolved_credit_limit')
            ->selectRaw(
                'CASE
                    WHEN ua.user_id IS NULL THEN COALESCE(u.referral_available_amount, 0)
                    WHEN ROUND(COALESCE(ua.referral_available_balance, 0), 2) = 0
                        AND ROUND(COALESCE(u.referral_available_amount, 0), 2) <> 0
                    THEN u.referral_available_amount
                    ELSE COALESCE(ua.referral_available_balance, 0)
                END as resolved_referral_available_balance'
            )
            ->first();

        if ($row === null) {
            throw new \RuntimeException('用户不存在');
        }

        return [
            'user_id' => (int) $row->id,
            'nickname' => trim((string) ($row->nickname ?? '')),
            'email' => trim((string) ($row->email ?? '')),
            'cash_balance' => $this->money($row->resolved_cash_balance ?? 0),
            'credit_limit' => $this->money($row->resolved_credit_limit ?? 0),
            'referral_available_balance' => $this->money($row->resolved_referral_available_balance ?? 0),
            'verification_status' => (int) ($row->verification_status ?? 0),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function backfill(bool $execute, int $chunkSize = 500, bool $syncLegacyUsers = false): array
    {
        $this->ensureTablesExist();

        $chunkSize = max(1, $chunkSize);
        $before = $this->inspect();
        $missingRows = $this->missingUsersQuery()
            ->orderBy('u.id')
            ->get();

        if (! $execute) {
            return [
                'dry_run' => true,
                'inserted' => 0,
                'backup_path' => null,
                'legacy_sync_backup_path' => null,
                'legacy_users_synced' => 0,
                'before' => $before,
                'after' => $before,
            ];
        }

        $backupPath = $this->writeBackup($missingRows->all());
        $inserted = 0;
        $lastId = 0;

        DB::transaction(function () use (&$inserted, &$lastId, $chunkSize): void {
            do {
                $rows = $this->missingUsersQuery()
                    ->where('u.id', '>', $lastId)
                    ->orderBy('u.id')
                    ->limit($chunkSize)
                    ->get();

                if ($rows->isEmpty()) {
                    break;
                }

                $now = now()->toDateTimeString();
                $payload = [];

                foreach ($rows as $row) {
                    $lastId = max($lastId, (int) $row->id);
                    $payload[] = $this->buildAccountPayload((object) $row, $now);
                }

                if ($payload !== []) {
                    $inserted += DB::table('user_accounts')->insertOrIgnore($payload);
                }
            } while (true);
        });

        $legacySyncBackupPath = null;
        $legacyUsersSynced = 0;

        if ($syncLegacyUsers) {
            $mismatches = $this->mismatchSelectQuery()->orderBy('u.id')->get()->all();
            $legacySyncBackupPath = $this->writeLegacySyncBackup($mismatches);
            $legacyUsersSynced = $this->syncLegacyUsersFromAccounts($mismatches);
        }

        return [
            'dry_run' => false,
            'inserted' => $inserted,
            'backup_path' => $backupPath,
            'legacy_sync_backup_path' => $legacySyncBackupPath,
            'legacy_users_synced' => $legacyUsersSynced,
            'before' => $before,
            'after' => $this->inspect(),
        ];
    }

    private function ensureTablesExist(): void
    {
        foreach (['users', 'user_accounts'] as $table) {
            if (! Schema::hasTable($table)) {
                throw new \RuntimeException("缺少数据表：{$table}");
            }
        }
    }

    private function missingUsersQuery(): Builder
    {
        return DB::table('users as u')
            ->leftJoin('user_accounts as ua', 'ua.user_id', '=', 'u.id')
            ->whereNull('ua.user_id')
            ->select([
                'u.id',
                'u.balance',
                'u.credit_limit',
                'u.referral_frozen_amount',
                'u.referral_available_amount',
                'u.referral_withdrawing_amount',
                'u.referral_withdrawn_amount',
            ]);
    }

    private function mismatchQuery(): Builder
    {
        return DB::table('users as u')
            ->join('user_accounts as ua', 'ua.user_id', '=', 'u.id')
            ->where(function (Builder $query): void {
                foreach (self::FIELD_MAP as $userColumn => $accountColumn) {
                    $query->orWhereRaw(
                        "ROUND(COALESCE(u.{$userColumn}, 0), 2) <> ROUND(COALESCE(ua.{$accountColumn}, 0), 2)"
                    );
                }
            });
    }

    private function mismatchSelectQuery(): Builder
    {
        $query = $this->mismatchQuery()->select('u.id as user_id');

        foreach (self::FIELD_MAP as $userColumn => $accountColumn) {
            $query
                ->selectRaw("u.{$userColumn} as users_{$userColumn}")
                ->selectRaw("ua.{$accountColumn} as account_{$accountColumn}");
        }

        return $query;
    }

    /**
     * @param  array<int, object>  $rows
     */
    private function writeBackup(array $rows): string
    {
        $directory = storage_path('app/account-user-account-backfills');
        File::ensureDirectoryExists($directory);

        $path = $directory.'/user_accounts_missing_'.now()->format('Ymd_His').'.json';
        $payload = [
            'generated_at' => now()->toISOString(),
            'count' => count($rows),
            'rows' => array_map(function (object $row): array {
                return [
                    'source_user' => [
                        'id' => (int) $row->id,
                        'balance' => $this->money($row->balance ?? 0),
                        'credit_limit' => $this->money($row->credit_limit ?? 0),
                        'referral_frozen_amount' => $this->money($row->referral_frozen_amount ?? 0),
                        'referral_available_amount' => $this->money($row->referral_available_amount ?? 0),
                        'referral_withdrawing_amount' => $this->money($row->referral_withdrawing_amount ?? 0),
                        'referral_withdrawn_amount' => $this->money($row->referral_withdrawn_amount ?? 0),
                    ],
                    'user_account_payload' => $this->buildAccountPayload($row, now()->toDateTimeString()),
                ];
            }, $rows),
        ];

        File::put($path, json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT));

        return $path;
    }

    /**
     * @param  array<int, object>  $rows
     */
    private function writeLegacySyncBackup(array $rows): ?string
    {
        if ($rows === []) {
            return null;
        }

        $directory = storage_path('app/account-user-account-backfills');
        File::ensureDirectoryExists($directory);

        $path = $directory.'/users_legacy_projection_sync_'.now()->format('Ymd_His').'.json';
        $payload = [
            'generated_at' => now()->toISOString(),
            'count' => count($rows),
            'rows' => array_map(fn (object $row): array => (array) $row, $rows),
        ];

        File::put($path, json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT));

        return $path;
    }

    /**
     * @param  array<int, object>  $rows
     */
    private function syncLegacyUsersFromAccounts(array $rows): int
    {
        $updated = 0;

        DB::transaction(function () use (&$updated, $rows): void {
            foreach ($rows as $row) {
                $updated += DB::table('users')
                    ->where('id', (int) $row->user_id)
                    ->update([
                        'balance' => $this->money($row->account_cash_balance ?? 0),
                        'credit_limit' => $this->money($row->account_credit_limit ?? 0),
                        'referral_frozen_amount' => $this->money($row->account_referral_frozen_balance ?? 0),
                        'referral_available_amount' => $this->money($row->account_referral_available_balance ?? 0),
                        'referral_withdrawing_amount' => $this->money($row->account_referral_pending_withdrawal_balance ?? 0),
                        'referral_withdrawn_amount' => $this->money($row->account_referral_withdrawn_balance ?? 0),
                        'updated_at' => now(),
                    ]);
            }
        });

        return $updated;
    }

    /**
     * @return array<string, mixed>
     */
    private function buildAccountPayload(object $row, string $timestamp): array
    {
        return [
            'user_id' => (int) $row->id,
            'cash_balance' => $this->money($row->balance ?? 0),
            'credit_limit' => $this->money($row->credit_limit ?? 0),
            'referral_frozen_balance' => $this->money($row->referral_frozen_amount ?? 0),
            'referral_available_balance' => $this->money($row->referral_available_amount ?? 0),
            'referral_pending_withdrawal_balance' => $this->money($row->referral_withdrawing_amount ?? 0),
            'referral_withdrawn_balance' => $this->money($row->referral_withdrawn_amount ?? 0),
            'version' => 0,
            'created_at' => $timestamp,
            'updated_at' => $timestamp,
        ];
    }

    private function money(mixed $value): string
    {
        return number_format((float) ($value ?? 0), 2, '.', '');
    }
}
