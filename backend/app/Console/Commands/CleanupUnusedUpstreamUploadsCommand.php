<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ticket\TicketUpstreamCallbackService;
use Illuminate\Console\Command;

final class CleanupUnusedUpstreamUploadsCommand extends Command
{
    protected $signature = 'tickets:cleanup-unused-upstream-uploads';

    protected $description = '删除超过保留期（默认 5 分钟）且未被工单回复引用的上游上传文件';

    public function handle(TicketUpstreamCallbackService $service): int
    {
        $result = $service->cleanupOrphanUploads();

        $this->line(sprintf(
            '上游上传文件清理完成：检查 %d，删除 %d，保留（已引用）%d，跳过（处理中）%d，失败 %d',
            $result['checked'],
            $result['deleted'],
            $result['referenced'],
            $result['skipped'],
            $result['errors']
        ));

        return self::SUCCESS;
    }
}
