<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * 在线账户余额对账。
 *
 * 对账 user_accounts 各余额字段与 account_transactions 流水中
 * 每个 (user_id, account_type) 最新一条 balance_after 快照是否一致。
 * 差异超过 0.01 元即视为不一致，输出差异行并写 warning 日志，
 * 作为生产环境余额并发/直接改库等异常的兜底发现手段。
 *
 * 与 ReconcileAccountDomainCommand 的区别：后者是迁移域一次性对账
 * （对比旧库 balance_logs 与新库 account_ledgers），依赖迁移上下文；
 * 本命令只对账当前在线库的 account_transactions 与 user_accounts，
 * 适合注册到 schedule:run 定时执行。
 */
class ReconcileAccountBalanceCommand extends Command
{
    protected $signature = 'reconcile:account-balance {--json : 以 JSON 输出差异详情}';

    protected $description = '对账 account_transactions 流水快照与 user_accounts 余额字段，发现不一致即告警';

    private const ACCOUNT_TYPE_TO_COLUMN = [
        'cash' => 'cash_balance',
        'referral_frozen' => 'referral_frozen_balance',
        'referral_available' => 'referral_available_balance',
        'referral_pending_withdrawal' => 'referral_pending_withdrawal_balance',
        'referral_withdrawn' => 'referral_withdrawn_balance',
    ];

    public function handle(): int
    {
        $diffRows = $this->queryBalanceDiffs();

        $summary = [
            'checked_at' => now()->toDateTimeString(),
            'diff_count' => count($diffRows),
            'rows' => array_map(static function (object $row): array {
                return [
                    'user_id' => (int) $row->user_id,
                    'account_type' => (string) $row->account_type,
                    'latest_balance_after' => number_format((float) $row->latest_balance_after, 2, '.', ''),
                    'account_balance' => number_format((float) $row->account_balance, 2, '.', ''),
                    'diff' => number_format((float) $row->diff, 2, '.', ''),
                ];
            }, $diffRows),
        ];

        if ((bool) $this->option('json')) {
            $this->line(json_encode($summary, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
        } else {
            $this->info(sprintf('对账完成：差异用户账户数 %d', $summary['diff_count']));
            if ($summary['diff_count'] > 0) {
                $this->table(
                    ['user_id', 'account_type', 'latest_balance_after', 'account_balance', 'diff'],
                    $summary['rows']
                );
            }
        }

        if ($summary['diff_count'] > 0) {
            Log::warning('[账户对账] 发现 account_transactions 流水快照与 user_accounts 余额不一致', $summary);
        }

        return $summary['diff_count'] > 0 ? self::FAILURE : self::SUCCESS;
    }

    /**
     * 查询每个 (user_id, account_type) 最新一条流水的 balance_after
     * 与 user_accounts 对应字段差异超过 0.01 的记录。
     */
    private function queryBalanceDiffs(): array
    {
        $caseColumn = implode(' ', array_map(
            static fn (string $type, string $column): string => "WHEN '{$type}' THEN ua.{$column}",
            array_keys(self::ACCOUNT_TYPE_TO_COLUMN),
            array_values(self::ACCOUNT_TYPE_TO_COLUMN)
        ));

        $latestBalanceAfter = $this->latestBalanceAfterSubquery();

        // 外层包装以便在 WHERE 复用 ABS(...) > 0.01（MySQL 不允许直接在 HAVING 引用嵌套别名）。
        $rows = DB::select(
            "SELECT user_id, account_type, latest_balance_after, account_balance,
                    ROUND(ABS(latest_balance_after - account_balance), 2) AS diff
             FROM (
                 SELECT t.user_id,
                        t.account_type,
                        CAST(t.balance_after AS DECIMAL(20,2)) AS latest_balance_after,
                        CASE t.account_type {$caseColumn} ELSE NULL END AS account_balance
                 FROM account_transactions t
                 JOIN user_accounts ua ON ua.user_id = t.user_id
                 JOIN (
                     {$latestBalanceAfter}
                 ) latest ON latest.user_id = t.user_id AND latest.account_type = t.account_type AND latest.max_id = t.id
                 WHERE t.account_type IN ('cash','referral_frozen','referral_available','referral_pending_withdrawal','referral_withdrawn')
             ) AS computed
             WHERE account_balance IS NOT NULL
               AND ROUND(ABS(latest_balance_after - account_balance), 2) > 0.01
             ORDER BY user_id ASC, account_type ASC"
        );

        return array_map(static function (object $row): object {
            $row->user_id = (int) $row->user_id;
            $row->latest_balance_after = (float) $row->latest_balance_after;
            $row->account_balance = (float) $row->account_balance;
            $row->diff = (float) $row->diff;

            return $row;
        }, $rows);
    }

    private function latestBalanceAfterSubquery(): string
    {
        return "SELECT user_id, account_type, MAX(id) AS max_id
                FROM account_transactions
                WHERE account_type IN ('cash','referral_frozen','referral_available','referral_pending_withdrawal','referral_withdrawn')
                GROUP BY user_id, account_type";
    }
}
