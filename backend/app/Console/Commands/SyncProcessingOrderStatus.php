<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * 该命令已废弃：原用于把开通中订单收敛为已完成，Invoice 没有 PROCESSING 状态，服务状态即真源，无需收敛。
 * 保留命令签名以避免调度调用失败。
 */
class SyncProcessingOrderStatus extends Command
{
    protected $signature = 'orders:sync-processing-status';

    protected $description = '[已废弃] 历史订单状态同步命令，保留兼容';

    public function handle(): int
    {
        Log::info('[定时任务] 处理中订单状态同步执行完成', [
            'matched' => 0,
            'completed' => 0,
            'deprecated' => true,
        ]);

        return self::SUCCESS;
    }
}
