<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\System\AccountMigrationService;
use Illuminate\Console\Command;

class ReconcileAccountDomainCommand extends Command
{
    protected $signature = 'migrate:account:reconcile {--json : 以 JSON 输出结果}';

    protected $description = '执行账户账本与返佣域迁移后的总对账';

    public function handle(AccountMigrationService $service): int
    {
        $service->ensureConnections();

        $referralRelations = $service->deriveReferralRelationPayloadPartition();
        $referralRewards = $service->deriveReferralRewardPayloads();
        $withdrawals = $service->deriveWithdrawalPayloads();
        $ledgers = $service->deriveAccountLedgerPayloads();
        $snapshots = $service->deriveBalanceSnapshotPayloads(date('Y-m-d'));

        $summary = [
            'referral_relations' => [
                'old_derived' => count($referralRelations['kept']),
                'new' => $service->targetCount('referral_relations'),
            ],
            'referral_rewards' => [
                'old_derived' => count($referralRewards),
                'new' => $service->targetCount('referral_rewards'),
                'old_total_reward_amount' => $this->sumPayloads($referralRewards, 'reward_amount'),
                'new_total_reward_amount' => $service->targetSum('referral_rewards', 'reward_amount'),
            ],
            'withdrawals' => [
                'old_derived' => count($withdrawals),
                'new' => $service->targetCount('withdrawals'),
                'old_total_amount' => $this->sumPayloads($withdrawals, 'amount'),
                'new_total_amount' => $service->targetSum('withdrawals', 'amount'),
            ],
            'account_ledgers' => [
                'old_derived' => count($ledgers),
                'new' => $service->targetCount('account_ledgers'),
                'old_total_amount' => $this->sumPayloads($ledgers, 'amount'),
                'new_total_amount' => $service->targetSum('account_ledgers', 'amount'),
            ],
            'account_balance_snapshots' => [
                'old_derived' => count($snapshots),
                'new' => $service->targetCount('account_balance_snapshots'),
            ],
        ];

        $orphans = [
            'referral_relations.referrer_user_id' => (int) (($service->targetQuery(
                'SELECT COUNT(*) AS cnt FROM referral_relations WHERE referrer_user_id NOT IN (SELECT id FROM users)'
            )[0]->cnt) ?? 0),
            'referral_relations.referred_user_id' => (int) (($service->targetQuery(
                'SELECT COUNT(*) AS cnt FROM referral_relations WHERE referred_user_id NOT IN (SELECT id FROM users)'
            )[0]->cnt) ?? 0),
            'referral_rewards.referrer_user_id' => (int) (($service->targetQuery(
                'SELECT COUNT(*) AS cnt FROM referral_rewards WHERE referrer_user_id NOT IN (SELECT id FROM users)'
            )[0]->cnt) ?? 0),
            'referral_rewards.referred_user_id' => (int) (($service->targetQuery(
                'SELECT COUNT(*) AS cnt FROM referral_rewards WHERE referred_user_id NOT IN (SELECT id FROM users)'
            )[0]->cnt) ?? 0),
            'referral_rewards.source_invoice_id' => (int) (($service->targetQuery(
                'SELECT COUNT(*) AS cnt FROM referral_rewards WHERE source_invoice_id NOT IN (SELECT id FROM invoices)'
            )[0]->cnt) ?? 0),
            'withdrawals.user_id' => (int) (($service->targetQuery(
                'SELECT COUNT(*) AS cnt FROM withdrawals WHERE user_id NOT IN (SELECT id FROM users)'
            )[0]->cnt) ?? 0),
            'account_ledgers.user_id' => (int) (($service->targetQuery(
                'SELECT COUNT(*) AS cnt FROM account_ledgers WHERE user_id NOT IN (SELECT id FROM users)'
            )[0]->cnt) ?? 0),
            'account_balance_snapshots.user_id' => (int) (($service->targetQuery(
                'SELECT COUNT(*) AS cnt FROM account_balance_snapshots WHERE user_id NOT IN (SELECT id FROM users)'
            )[0]->cnt) ?? 0),
        ];
        $withdrawalNoDup = $service->targetQuery(
            'SELECT COUNT(*) - COUNT(DISTINCT withdrawal_no) AS cnt
             FROM withdrawals
             WHERE withdrawal_no IS NOT NULL'
        );
        $referralRewardStatusOld = $this->statusDistribution($service->sourceQuery(
            'SELECT status, COUNT(*) AS cnt FROM referral_rewards GROUP BY status ORDER BY status ASC'
        ));
        $referralRewardStatusNew = $this->statusDistribution($service->targetQuery(
            'SELECT status, COUNT(*) AS cnt FROM referral_rewards GROUP BY status ORDER BY status ASC'
        ));
        $withdrawalStatusOld = $this->statusDistribution($service->sourceQuery(
            'SELECT status, COUNT(*) AS cnt FROM referral_withdrawals GROUP BY status ORDER BY status ASC'
        ));
        $withdrawalStatusNew = $this->statusDistribution($service->targetQuery(
            'SELECT status, COUNT(*) AS cnt FROM withdrawals GROUP BY status ORDER BY status ASC'
        ));
        $ledgerBalanceDiffRows = $service->targetQuery(
            "SELECT
                al.user_id,
                al.account_type,
                ROUND(SUM(CASE WHEN al.direction = 'credit' THEN al.amount ELSE -al.amount END), 2) AS ledger_net,
                CASE al.account_type
                    WHEN 'cash' THEN (SELECT cash_balance FROM user_accounts WHERE user_id = al.user_id LIMIT 1)
                    WHEN 'referral_frozen' THEN (SELECT referral_frozen_balance FROM user_accounts WHERE user_id = al.user_id LIMIT 1)
                    WHEN 'referral_available' THEN (SELECT referral_available_balance FROM user_accounts WHERE user_id = al.user_id LIMIT 1)
                    WHEN 'referral_withdrawing' THEN (SELECT referral_pending_withdrawal_balance FROM user_accounts WHERE user_id = al.user_id LIMIT 1)
                    WHEN 'referral_withdrawn' THEN (SELECT referral_withdrawn_balance FROM user_accounts WHERE user_id = al.user_id LIMIT 1)
                    ELSE NULL
                END AS account_balance,
                ROUND(ABS(
                    SUM(CASE WHEN al.direction = 'credit' THEN al.amount ELSE -al.amount END) -
                    CASE al.account_type
                        WHEN 'cash' THEN COALESCE((SELECT cash_balance FROM user_accounts WHERE user_id = al.user_id LIMIT 1), 0)
                        WHEN 'referral_frozen' THEN COALESCE((SELECT referral_frozen_balance FROM user_accounts WHERE user_id = al.user_id LIMIT 1), 0)
                        WHEN 'referral_available' THEN COALESCE((SELECT referral_available_balance FROM user_accounts WHERE user_id = al.user_id LIMIT 1), 0)
                        WHEN 'referral_withdrawing' THEN COALESCE((SELECT referral_pending_withdrawal_balance FROM user_accounts WHERE user_id = al.user_id LIMIT 1), 0)
                        WHEN 'referral_withdrawn' THEN COALESCE((SELECT referral_withdrawn_balance FROM user_accounts WHERE user_id = al.user_id LIMIT 1), 0)
                        ELSE 0
                    END
                ), 2) AS diff
             FROM account_ledgers al
             GROUP BY al.user_id, al.account_type
             HAVING diff > 0.01
             ORDER BY al.user_id ASC, al.account_type ASC"
        );

        $payload = [
            'summary' => $summary,
            'orphans' => $orphans,
            'uniques' => [
                'withdrawals.withdrawal_no' => (int) ($withdrawalNoDup[0]->cnt ?? 0),
            ],
            'statuses' => [
                'referral_rewards' => [
                    'old' => $referralRewardStatusOld,
                    'new' => $referralRewardStatusNew,
                ],
                'withdrawals' => [
                    'old' => $withdrawalStatusOld,
                    'new' => $withdrawalStatusNew,
                ],
            ],
            'ledger_balance_diff' => [
                'count' => count($ledgerBalanceDiffRows),
                'rows' => array_map(function (object $row): array {
                    return [
                        'user_id' => (int) ($row->user_id ?? 0),
                        'account_type' => (string) ($row->account_type ?? ''),
                        'ledger_net' => number_format((float) ($row->ledger_net ?? 0), 2, '.', ''),
                        'account_balance' => number_format((float) ($row->account_balance ?? 0), 2, '.', ''),
                        'diff' => number_format((float) ($row->diff ?? 0), 2, '.', ''),
                    ];
                }, $ledgerBalanceDiffRows),
            ],
        ];

        if ((bool) $this->option('json')) {
            $this->line(json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));

            return self::SUCCESS;
        }

        foreach ($summary as $table => $counts) {
            $this->line($table.': '.json_encode($counts, JSON_UNESCAPED_UNICODE));
        }

        foreach ($orphans as $key => $count) {
            $this->line($key.' orphan='.$count);
        }

        $this->line('uniques: '.json_encode($payload['uniques'], JSON_UNESCAPED_UNICODE));
        $this->line('statuses: '.json_encode($payload['statuses'], JSON_UNESCAPED_UNICODE));
        $this->line('ledger_balance_diff: '.json_encode($payload['ledger_balance_diff'], JSON_UNESCAPED_UNICODE));

        return self::SUCCESS;
    }

    /**
     * @param  array<int, array<string, mixed>>  $payloads
     */
    private function sumPayloads(array $payloads, string $column): string
    {
        $sum = 0.0;

        foreach ($payloads as $payload) {
            $sum += (float) ($payload[$column] ?? 0);
        }

        return number_format($sum, 2, '.', '');
    }

    /**
     * @param  array<int, object>  $rows
     * @return array<string, int>
     */
    private function statusDistribution(array $rows): array
    {
        $distribution = [];

        foreach ($rows as $row) {
            $distribution[(string) ($row->status ?? '')] = (int) ($row->cnt ?? 0);
        }

        return $distribution;
    }
}
