<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\System\IdentityMigrationService;
use Illuminate\Console\Command;

class ReconcileIdentityDomainCommand extends Command
{
    protected $signature = 'migrate:identity:reconcile {--json : 以 JSON 输出结果}';

    protected $description = '执行身份与权限域迁移后的总对账';

    public function handle(IdentityMigrationService $service): int
    {
        $service->ensureConnections();

        $summary = [
            'member_levels' => [
                'old' => $service->sourceCount('member_levels'),
                'new' => $service->targetCount('member_levels'),
            ],
            'users' => [
                'old' => $service->sourceCount('users'),
                'new' => $service->targetCount('users'),
            ],
            'user_profiles' => [
                'old_derived' => $service->sourceCount('users'),
                'new' => $service->targetCount('user_profiles'),
            ],
            'user_accounts' => [
                'old' => $service->sourceCount('user_accounts'),
                'new' => $service->targetCount('user_accounts'),
            ],
            'verification_histories' => [
                'old' => $service->sourceCount('verification_histories'),
                'new' => $service->targetCount('verification_histories'),
            ],
            'admin_users' => [
                'old' => $service->sourceCount('admin_users'),
                'new' => $service->targetCount('admin_users'),
            ],
            'roles' => [
                'old' => $service->sourceCount('roles'),
                'new' => $service->targetCount('roles'),
            ],
            'admin_user_roles' => [
                'old' => $service->sourceCount('admin_user_roles'),
                'new' => $service->targetCount('admin_user_roles'),
            ],
        ];

        $orphans = [
            'users.member_level_id' => (int) (($service->targetQuery(
                'SELECT COUNT(*) AS cnt
                 FROM users
                 WHERE member_level_id IS NOT NULL
                   AND member_level_id NOT IN (SELECT id FROM member_levels)'
            )[0]->cnt) ?? 0),
            'users.referrer_user_id' => (int) (($service->targetQuery(
                'SELECT COUNT(*) AS cnt
                 FROM users
                 WHERE referrer_user_id IS NOT NULL
                   AND referrer_user_id NOT IN (SELECT id FROM users)'
            )[0]->cnt) ?? 0),
            'user_profiles.user_id' => (int) (($service->targetQuery(
                'SELECT COUNT(*) AS cnt FROM user_profiles WHERE user_id NOT IN (SELECT id FROM users)'
            )[0]->cnt) ?? 0),
            'user_accounts.user_id' => (int) (($service->targetQuery(
                'SELECT COUNT(*) AS cnt FROM user_accounts WHERE user_id NOT IN (SELECT id FROM users)'
            )[0]->cnt) ?? 0),
            'verification_histories.user_id' => (int) (($service->targetQuery(
                'SELECT COUNT(*) AS cnt FROM verification_histories WHERE user_id NOT IN (SELECT id FROM users)'
            )[0]->cnt) ?? 0),
            'admin_user_roles.admin_user_id' => (int) (($service->targetQuery(
                'SELECT COUNT(*) AS cnt FROM admin_user_roles WHERE admin_user_id NOT IN (SELECT id FROM admin_users)'
            )[0]->cnt) ?? 0),
            'admin_user_roles.role_id' => (int) (($service->targetQuery(
                'SELECT COUNT(*) AS cnt FROM admin_user_roles WHERE role_id NOT IN (SELECT id FROM roles)'
            )[0]->cnt) ?? 0),
        ];

        $cashOld = $service->sourceQuery('SELECT COALESCE(SUM(cash_balance), 0) AS total FROM user_accounts');
        $cashNew = $service->targetQuery('SELECT COALESCE(SUM(cash_balance), 0) AS total FROM user_accounts');
        $balanceDiffCount = $service->targetQuery(
            'SELECT COUNT(*) AS cnt
             FROM user_accounts
             WHERE ABS(COALESCE(migrated_balance_diff, 0)) > 0.01'
        );
        $balanceDiffSum = $service->targetQuery(
            'SELECT COALESCE(SUM(migrated_balance_diff), 0) AS total
             FROM user_accounts
             WHERE ABS(COALESCE(migrated_balance_diff, 0)) > 0.01'
        );
        $balanceDiffUsers = $service->targetQuery(
            'SELECT user_id
             FROM user_accounts
             WHERE ABS(COALESCE(migrated_balance_diff, 0)) > 0.01
             ORDER BY user_id ASC'
        );
        $emailDup = $service->targetQuery(
            'SELECT COUNT(*) - COUNT(DISTINCT email) AS cnt
             FROM users
             WHERE email IS NOT NULL'
        );
        $phoneDup = $service->targetQuery(
            'SELECT COUNT(*) - COUNT(DISTINCT phone) AS cnt
             FROM users
             WHERE phone IS NOT NULL'
        );
        $referralCodeDup = $service->targetQuery(
            'SELECT COUNT(*) - COUNT(DISTINCT referral_code) AS cnt
             FROM users
             WHERE referral_code IS NOT NULL'
        );
        $adminUsernameDup = $service->targetQuery(
            'SELECT COUNT(*) - COUNT(DISTINCT username) AS cnt
             FROM admin_users
             WHERE username IS NOT NULL'
        );
        $roleNameDup = $service->targetQuery(
            'SELECT COUNT(*) - COUNT(DISTINCT name) AS cnt
             FROM roles
             WHERE name IS NOT NULL'
        );

        $payload = [
            'summary' => $summary,
            'orphans' => $orphans,
            'uniques' => [
                'users.email' => (int) ($emailDup[0]->cnt ?? 0),
                'users.phone' => (int) ($phoneDup[0]->cnt ?? 0),
                'users.referral_code' => (int) ($referralCodeDup[0]->cnt ?? 0),
                'admin_users.username' => (int) ($adminUsernameDup[0]->cnt ?? 0),
                'roles.name' => (int) ($roleNameDup[0]->cnt ?? 0),
            ],
            'cash_balance_total' => [
                'old' => (string) ($cashOld[0]->total ?? '0.00'),
                'new' => (string) ($cashNew[0]->total ?? '0.00'),
            ],
            'user_account_balance_diff' => [
                'count' => (int) ($balanceDiffCount[0]->cnt ?? 0),
                'sum' => number_format((float) ($balanceDiffSum[0]->total ?? 0), 2, '.', ''),
                'user_ids' => array_map(
                    static fn (object $row): int => (int) ($row->user_id ?? 0),
                    $balanceDiffUsers
                ),
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
        $this->line('cash_balance_total: '.json_encode($payload['cash_balance_total'], JSON_UNESCAPED_UNICODE));
        $this->line('user_account_balance_diff: '.json_encode($payload['user_account_balance_diff'], JSON_UNESCAPED_UNICODE));

        return self::SUCCESS;
    }
}
