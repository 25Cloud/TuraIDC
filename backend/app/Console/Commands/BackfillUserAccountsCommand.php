<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\User\UserAccountProjectionService;
use Illuminate\Console\Command;

class BackfillUserAccountsCommand extends Command
{
    protected $signature = 'account:backfill-user-accounts
        {--dry-run : 只统计影响范围，不写入数据}
        {--execute : 执行回填写入；未指定时默认 dry-run}
        {--sync-legacy-users : 执行时将 users 旧余额/返佣投影同步为 user_accounts 当前值}
        {--json : 以 JSON 输出结果}
        {--chunk=500 : 每批处理数量}';

    protected $description = '从 users 当前余额字段回填缺失的 user_accounts 账户源表行';

    public function handle(UserAccountProjectionService $service): int
    {
        $execute = (bool) $this->option('execute') && ! (bool) $this->option('dry-run');
        $chunkSize = max(1, (int) $this->option('chunk'));
        $syncLegacyUsers = (bool) $this->option('sync-legacy-users');

        $result = $service->backfill($execute, $chunkSize, $syncLegacyUsers);

        if ((bool) $this->option('json')) {
            $this->line(json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT));
        } else {
            $this->info($execute ? '账户投影回填完成' : '账户投影回填预览');
            $this->line('- dry_run: '.($result['dry_run'] ? 'true' : 'false'));
            $this->line('- users_without_account_before: '.$result['before']['users_without_account']);
            $this->line('- inserted: '.$result['inserted']);
            $this->line('- legacy_users_synced: '.$result['legacy_users_synced']);
            $this->line('- users_without_account_after: '.$result['after']['users_without_account']);
            $this->line('- balance_mismatch_after: '.$result['after']['balance_mismatch_users_vs_accounts']);

            if ($result['backup_path']) {
                $this->line('- backup_path: '.$result['backup_path']);
            }

            if ($result['legacy_sync_backup_path']) {
                $this->line('- legacy_sync_backup_path: '.$result['legacy_sync_backup_path']);
            }

            if (! $execute) {
                $this->warn('默认 dry-run，实际未写入。确认影响面和备份策略后使用 --execute 执行。');
            }
        }

        return self::SUCCESS;
    }
}
