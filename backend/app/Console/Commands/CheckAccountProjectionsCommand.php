<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\User\UserAccountProjectionService;
use Illuminate\Console\Command;

class CheckAccountProjectionsCommand extends Command
{
    protected $signature = 'account:check-projections
        {--json : 以 JSON 输出结果}
        {--strict : 存在缺失或差异时返回非零退出码}';

    protected $description = '检查 users 与 user_accounts 账户投影完整性和余额一致性';

    public function handle(UserAccountProjectionService $service): int
    {
        $report = $service->inspect();

        if ((bool) $this->option('json')) {
            $this->line(json_encode($report, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT));
        } else {
            $this->info('账户投影检查');
            $this->line('- users_count: '.$report['users_count']);
            $this->line('- user_accounts_count: '.$report['user_accounts_count']);
            $this->line('- users_without_account: '.$report['users_without_account']);
            $this->line('- balance_mismatch_users_vs_accounts: '.$report['balance_mismatch_users_vs_accounts']);
            $this->line('- missing_user_ids: '.json_encode($report['missing_user_ids'], JSON_UNESCAPED_UNICODE));
        }

        if ((bool) $this->option('strict') && $this->hasAnomaly($report)) {
            return self::FAILURE;
        }

        return self::SUCCESS;
    }

    /**
     * @param  array<string, mixed>  $report
     */
    private function hasAnomaly(array $report): bool
    {
        return (int) ($report['users_without_account'] ?? 0) > 0
            || (int) ($report['balance_mismatch_users_vs_accounts'] ?? 0) > 0;
    }
}
