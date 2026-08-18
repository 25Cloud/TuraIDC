<?php

declare(strict_types=1);

namespace TuraIDC\Plugins\Servers\ZjmfFinance\Lib;

use App\Constants\InvoiceStatus;
use App\Services\Upstream\Contracts\UpstreamBillingRestoreProfile;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;

class ZjmfBillingRestoreService
{
    public function __construct(
        private readonly UpstreamBillingRestoreProfile $profile = new ZjmfBillingRestoreProfile,
    ) {}

    /**
     * @return array<string, int|bool|string>
     */
    public function restoreFromSqlDump(string $dumpPath, bool $dryRun = false, bool $forceOverwrite = false): array
    {
        if (! is_file($dumpPath)) {
            throw new RuntimeException('原始 SQL 文件不存在: '.$dumpPath);
        }

        $tables = $this->extractTargetTables($dumpPath);
        $existingUserIds = array_fill_keys(
            DB::table('users')->pluck('id')->map(fn ($id) => (int) $id)->all(),
            true
        );

        $clientBalances = $this->buildClientBalances($tables['shd_clients'], $existingUserIds);
        $accountMap = $this->buildAccountMap($tables['shd_accounts'], $existingUserIds);

        $invoicePayload = [];
        $balanceLogPayload = [];
        $usedInvoiceNos = [];
        $summary = [
            'dry_run' => $dryRun,
            'invoices' => 0,
            'balance_logs' => 0,
            'user_balances' => count($clientBalances),
            'skipped_missing_users' => 0,
            'skipped_deleted_invoices' => 0,
            'existing_invoices' => 0,
            'existing_balance_logs' => 0,
            'overwrite_forced' => false,
        ];

        foreach ($tables['shd_invoices'] as $row) {
            $invoiceId = (int) ($row[0] ?? 0);
            $userId = (int) ($row[1] ?? 0);
            $deleteTime = (int) ($row[18] ?? 0);
            $isDelete = (int) ($row[33] ?? 0);

            if ($invoiceId <= 0 || $userId <= 0 || ! isset($existingUserIds[$userId])) {
                $summary['skipped_missing_users']++;

                continue;
            }

            if ($deleteTime > 0 || $isDelete === 1) {
                $summary['skipped_deleted_invoices']++;

                continue;
            }

            $statusText = trim((string) ($row[15] ?? ''));
            $invoiceStatus = $this->mapInvoiceStatus($statusText);
            $createdAt = $this->formatDateTime((int) ($row[3] ?? 0));
            $updatedAt = $this->formatDateTime((int) ($row[4] ?? 0)) ?? $createdAt ?? now()->format('Y-m-d H:i:s');
            $dueDate = $this->formatDate((int) ($row[5] ?? 0))
                ?? $this->formatDate((int) ($row[3] ?? 0))
                ?? now()->toDateString();
            $paidAt = $this->formatDateTime((int) ($row[6] ?? 0));

            $amount = $this->toDecimalString($row[12] ?? $row[8] ?? '0');
            $creditAmount = $this->toDecimalString($row[9] ?? '0');
            $paidAmount = $this->resolveInvoicePaidAmount($statusText, $amount, $creditAmount);
            $account = $accountMap[$invoiceId] ?? null;

            if ($paidAt === null && $account !== null) {
                $paidAt = $account['paid_at'];
            }

            $invoiceNo = $this->resolveInvoiceNo((string) ($row[2] ?? ''), $invoiceId, $usedInvoiceNos);

            $invoicePayload[] = [
                'id' => $invoiceId,
                'invoice_no' => $invoiceNo,
                'user_id' => $userId,
                'order_id' => null,
                'product_id' => null,
                'type' => $this->mapInvoiceType((string) ($row[20] ?? '')),
                'amount' => $amount,
                'paid_amount' => $paidAmount,
                'status' => $invoiceStatus,
                'due_date' => $dueDate,
                'paid_at' => $paidAt,
                'trace_id' => 'restore-invoice-'.$invoiceId,
                'created_at' => $createdAt ?? now()->format('Y-m-d H:i:s'),
                'updated_at' => $updatedAt,
            ];

            $summary['invoices']++;
        }

        foreach ($tables['shd_credit'] as $row) {
            $creditId = (int) ($row[0] ?? 0);
            $userId = (int) ($row[1] ?? 0);

            if ($creditId <= 0 || $userId <= 0 || ! isset($existingUserIds[$userId])) {
                $summary['skipped_missing_users']++;

                continue;
            }

            $description = trim((string) ($row[3] ?? ''));
            $amount = $this->normalizeBalanceAmount($description, $this->toDecimalString($row[4] ?? '0'));
            $remark = trim((string) ($row[7] ?? '')) !== '' ? trim((string) ($row[7] ?? '')) : $description;

            $balanceLogPayload[] = [
                'id' => $creditId,
                'user_id' => $userId,
                'event_type' => $this->mapBalanceLogType($description, $amount),
                'change_amount' => $amount,
                'balance_after' => $this->toDecimalString($row[8] ?? '0'),
                'remark' => $remark,
                'reference_id' => ((int) ($row[5] ?? 0)) > 0 ? (int) ($row[5] ?? 0) : null,
                'created_at' => $this->formatDateTime((int) ($row[2] ?? 0)) ?? now()->format('Y-m-d H:i:s'),
            ];

            $summary['balance_logs']++;
        }

        usort($invoicePayload, fn (array $a, array $b) => $a['id'] <=> $b['id']);
        usort($balanceLogPayload, fn (array $a, array $b) => $a['id'] <=> $b['id']);

        // 仅允许恢复到空财务表：invoices/balance_logs 属于审计链路，禁止用 dump 覆盖
        // 当前库中已产生的账单/余额日志。--force 保留为兼容旧脚本的无效参数，不再触发删除。
        $existingInvoices = Schema::hasTable('invoices') ? (int) DB::table('invoices')->count() : 0;
        $existingBalanceLogs = Schema::hasTable('balance_logs') ? (int) DB::table('balance_logs')->count() : 0;
        $summary['existing_invoices'] = $existingInvoices;
        $summary['existing_balance_logs'] = $existingBalanceLogs;

        if ($dryRun) {
            return $summary;
        }

        if ($existingInvoices > 0 || $existingBalanceLogs > 0) {
            throw new RuntimeException(
                '目标库 invoices/balance_logs 已有数据（invoices='.$existingInvoices.', balance_logs='.$existingBalanceLogs.'），'
                .'为保护财务审计链路，禁止覆盖或物理删除既有账单/余额日志；请仅在空库或隔离库执行恢复'
            );
        }

        $summary['overwrite_forced'] = false;

        DB::transaction(function () use ($invoicePayload, $balanceLogPayload, $clientBalances): void {
            $now = now()->format('Y-m-d H:i:s');

            DB::table('users')->update([
                'updated_at' => $now,
            ]);

            DB::table('user_accounts')->update([
                'cash_balance' => '0.00',
                'updated_at' => $now,
            ]);

            foreach (array_chunk($invoicePayload, 500) as $chunk) {
                DB::table('invoices')->insert($chunk);
            }

            foreach (array_chunk($balanceLogPayload, 500) as $chunk) {
                DB::table('balance_logs')->insert($chunk);
            }

            foreach ($clientBalances as $userId => $balance) {
                DB::table('user_accounts')
                    ->where('user_id', $userId)
                    ->update([
                        'cash_balance' => $balance,
                        'updated_at' => $now,
                    ]);
            }
        }, 3);

        return $summary;
    }

    /**
     * @return array<string, array<int, array<int, mixed>>>
     */
    private function extractTargetTables(string $dumpPath): array
    {
        $targets = [
            'shd_clients' => [],
            'shd_invoices' => [],
            'shd_credit' => [],
            'shd_accounts' => [],
        ];

        $handle = fopen($dumpPath, 'rb');

        if ($handle === false) {
            throw new RuntimeException('无法读取原始 SQL 文件: '.$dumpPath);
        }

        $currentTable = null;
        $statement = '';

        while (($line = fgets($handle)) !== false) {
            if ($currentTable === null) {
                foreach (array_keys($targets) as $table) {
                    $prefix = "INSERT INTO `{$table}` VALUES ";

                    if (str_starts_with($line, $prefix)) {
                        $currentTable = $table;
                        $statement = $line;
                        break;
                    }
                }

                if ($currentTable === null) {
                    continue;
                }
            } else {
                $statement .= $line;
            }

            if (preg_match('/;\s*$/', trim($line)) !== 1) {
                continue;
            }

            $targets[$currentTable] = array_merge(
                $targets[$currentTable],
                $this->parseInsertStatement($statement, $currentTable)
            );

            $currentTable = null;
            $statement = '';
        }

        fclose($handle);

        return $targets;
    }

    /**
     * @return array<int, array<int, mixed>>
     */
    private function parseInsertStatement(string $statement, string $table): array
    {
        $prefix = "INSERT INTO `{$table}` VALUES ";

        if (! str_starts_with($statement, $prefix)) {
            return [];
        }

        $payload = substr($statement, strlen($prefix));
        $payload = rtrim(trim($payload), ';');

        $rows = [];
        $row = [];
        $field = '';
        $depth = 0;
        $inString = false;
        $escaped = false;
        $length = strlen($payload);

        for ($index = 0; $index < $length; $index++) {
            $char = $payload[$index];

            if ($inString) {
                $field .= $char;

                if ($escaped) {
                    $escaped = false;

                    continue;
                }

                if ($char === '\\') {
                    $escaped = true;

                    continue;
                }

                if ($char === "'") {
                    $inString = false;
                }

                continue;
            }

            if ($char === "'") {
                $inString = true;
                $field .= $char;

                continue;
            }

            if ($char === '(') {
                $depth++;

                if ($depth === 1) {
                    $row = [];
                    $field = '';

                    continue;
                }
            }

            if ($char === ')' && $depth === 1) {
                $row[] = $this->normalizeSqlValue(trim($field));
                $rows[] = $row;
                $row = [];
                $field = '';
                $depth = 0;

                continue;
            }

            if ($char === ',' && $depth === 1) {
                $row[] = $this->normalizeSqlValue(trim($field));
                $field = '';

                continue;
            }

            if ($depth >= 1) {
                $field .= $char;
            }
        }

        return $rows;
    }

    private function normalizeSqlValue(string $value): mixed
    {
        if (strcasecmp($value, 'NULL') === 0) {
            return null;
        }

        if ($value === '') {
            return '';
        }

        if ($value[0] === "'" && substr($value, -1) === "'") {
            return stripcslashes(substr($value, 1, -1));
        }

        if (preg_match('/^-?\d+$/', $value) === 1) {
            return (int) $value;
        }

        if (is_numeric($value)) {
            return $value;
        }

        return $value;
    }

    /**
     * @param  array<int, array<int, mixed>>  $rows
     * @param  array<int, bool>  $existingUserIds
     * @return array<int, string>
     */
    private function buildClientBalances(array $rows, array $existingUserIds): array
    {
        $balances = [];

        foreach ($rows as $row) {
            $userId = (int) ($row[0] ?? 0);

            if ($userId <= 0 || ! isset($existingUserIds[$userId])) {
                continue;
            }

            $balances[$userId] = $this->toDecimalString($row[26] ?? '0');
        }

        return $balances;
    }

    /**
     * @param  array<int, array<int, mixed>>  $rows
     * @param  array<int, bool>  $existingUserIds
     * @return array<int, array{id:int,trade_no:string,paid_at:?string,created_at:string}>
     */
    private function buildAccountMap(array $rows, array $existingUserIds): array
    {
        $map = [];

        foreach ($rows as $row) {
            $invoiceId = (int) ($row[13] ?? 0);
            $userId = (int) ($row[1] ?? 0);
            $deleteTime = (int) ($row[15] ?? 0);

            if (
                $invoiceId <= 0 ||
                $userId <= 0 ||
                ! isset($existingUserIds[$userId]) ||
                $deleteTime > 0
            ) {
                continue;
            }

            $candidate = [
                'id' => (int) ($row[0] ?? 0),
                'trade_no' => trim((string) ($row[12] ?? '')),
                'paid_at' => $this->formatDateTime((int) ($row[6] ?? 0)),
                'created_at' => $this->formatDateTime((int) ($row[4] ?? 0))
                    ?? $this->formatDateTime((int) ($row[6] ?? 0))
                    ?? now()->format('Y-m-d H:i:s'),
            ];

            if (
                ! isset($map[$invoiceId]) ||
                $this->isBetterAccountCandidate($candidate, $map[$invoiceId])
            ) {
                $map[$invoiceId] = $candidate;
            }
        }

        return $map;
    }

    /**
     * @param  array{id:int,trade_no:string,paid_at:?string,created_at:string}  $candidate
     * @param  array{id:int,trade_no:string,paid_at:?string,created_at:string}  $current
     */
    private function isBetterAccountCandidate(array $candidate, array $current): bool
    {
        $candidatePaidAt = $candidate['paid_at'] ?? '';
        $currentPaidAt = $current['paid_at'] ?? '';

        if ($candidatePaidAt !== $currentPaidAt) {
            return $candidatePaidAt > $currentPaidAt;
        }

        if (($candidate['trade_no'] !== '') !== ($current['trade_no'] !== '')) {
            return $candidate['trade_no'] !== '';
        }

        return $candidate['id'] > $current['id'];
    }

    private function resolveInvoiceNo(string $rawInvoiceNo, int $invoiceId, array &$usedInvoiceNos): string
    {
        $invoiceNo = trim($rawInvoiceNo);
        $invoiceNo = preg_replace('/\s+/', '', $invoiceNo) ?? '';

        if ($invoiceNo === '' || strlen($invoiceNo) > 32 || isset($usedInvoiceNos[$invoiceNo])) {
            $invoiceNo = $this->profile->invoiceNoPrefix().str_pad((string) $invoiceId, 12, '0', STR_PAD_LEFT);
        }

        $usedInvoiceNos[$invoiceNo] = true;

        return $invoiceNo;
    }

    private function mapInvoiceType(string $type): string
    {
        return match (trim($type)) {
            'renew' => 'renew',
            'product' => 'normal',
            default => 'manual',
        };
    }

    private function mapInvoiceStatus(string $status): int
    {
        return match (trim($status)) {
            'Paid' => InvoiceStatus::PAID,
            'Cancelled', 'Refunded' => InvoiceStatus::CANCELLED,
            'Overdue', 'Collections' => InvoiceStatus::OVERDUE,
            default => InvoiceStatus::UNPAID,
        };
    }

    private function resolveInvoicePaidAmount(string $status, string $amount, string $creditAmount): string
    {
        return match (trim($status)) {
            'Paid', 'Refunded' => $amount,
            'Unpaid', 'Overdue', 'Draft', 'Collections' => $this->decimalCompare($creditAmount, '0.00') === 1 ? $creditAmount : '0.00',
            default => '0.00',
        };
    }

    private function mapBalanceLogType(string $description, string $amount): string
    {
        $description = mb_strtolower($description);

        if (
            str_contains($description, 'add funds') ||
            str_contains($description, '充值') ||
            str_contains($description, '添加余额')
        ) {
            return 'recharge';
        }

        if (
            str_contains($description, 'refund') ||
            str_contains($description, '退款')
        ) {
            return 'refund';
        }

        if (
            str_contains($description, 'credit applied') ||
            str_contains($description, '减少余额') ||
            $this->decimalCompare($amount, '0.00') === -1
        ) {
            return 'consume';
        }

        return 'adjust';
    }

    private function normalizeBalanceAmount(string $description, string $amount): string
    {
        $normalized = $this->toDecimalString($amount);
        $description = mb_strtolower($description);

        if (
            str_contains($description, 'credit applied') ||
            str_contains($description, '减少余额')
        ) {
            return $this->decimalCompare($normalized, '0.00') === 1
                ? $this->decimalNegate($normalized)
                : $normalized;
        }

        return $normalized;
    }

    private function toDecimalString(mixed $value): string
    {
        return number_format((float) $value, 2, '.', '');
    }

    private function decimalCompare(string $left, string $right): int
    {
        return (float) $left <=> (float) $right;
    }

    private function decimalNegate(string $value): string
    {
        return number_format(0 - (float) $value, 2, '.', '');
    }

    private function formatDateTime(int $timestamp): ?string
    {
        if ($timestamp <= 0) {
            return null;
        }

        return Carbon::createFromTimestamp($timestamp)->format('Y-m-d H:i:s');
    }

    private function formatDate(int $timestamp): ?string
    {
        if ($timestamp <= 0) {
            return null;
        }

        return Carbon::createFromTimestamp($timestamp)->toDateString();
    }
}
