<?php

namespace EwyFinance\Plugins\Servers\ZjmfFinance\Commands;

use EwyFinance\Plugins\Servers\ZjmfFinance\Lib\ZjmfBillingRestoreProfile;
use EwyFinance\Plugins\Servers\ZjmfFinance\Lib\ZjmfBillingRestoreService;
use Illuminate\Console\Command;
use Throwable;

class RestoreZjmfBillingCommand extends Command
{
    protected $signature = 'finance:restore-zjmf-billing
        {dump : 原始 ZJMF 账单 SQL 文件绝对路径}
        {--confirm= : 危险确认短语，默认必须为 RESTORE_ZJMF_BILLING}
        {--dry-run : 仅解析并输出统计，不写入数据库}';

    protected $description = '从历史 ZJMF SQL 备份恢复账单、支付流水与余额记录';

    public function handle(
        ZjmfBillingRestoreService $restoreService,
        ZjmfBillingRestoreProfile $profile
    ): int {
        $dumpPath = (string) $this->argument('dump');
        $confirm = (string) $this->option('confirm');
        $dryRun = (bool) $this->option('dry-run');

        if (! in_array($confirm, $profile->confirmationPhrases(), true)) {
            $this->error('危险确认未通过，请追加 --confirm='.$profile->defaultConfirmationPhrase());

            return self::INVALID;
        }

        try {
            $summary = $restoreService->restoreFromSqlDump($dumpPath, $dryRun);
        } catch (Throwable $e) {
            $this->error('恢复失败: '.$e->getMessage());

            return self::FAILURE;
        }

        $this->info($dryRun ? '账单恢复预检完成' : '账单恢复完成');
        $this->line('SQL 文件: '.$dumpPath);
        $this->line('账单数: '.$summary['invoices']);
        $this->line('余额日志数: '.$summary['balance_logs']);
        $this->line('同步余额用户数: '.$summary['user_balances']);
        $this->line('跳过缺失用户记录数: '.$summary['skipped_missing_users']);
        $this->line('跳过已删除旧账单数: '.$summary['skipped_deleted_invoices']);

        return self::SUCCESS;
    }
}
