<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @deprecated 模型对应的 account_balance_snapshots 表已不存在。
 *             余额快照数据已整合至 user_accounts 与 account_transactions。
 *             保留此文件仅用于历史迁移命令 MigrateAccountBalanceSnapshotsCommand 的引用，
 *             该命令同样已废弃。待确认迁移不再需要后一并删除。
 */
class AccountBalanceSnapshot extends Model
{
    protected $table = 'account_balance_snapshots';

    protected $fillable = [
        'user_id',
        'account_type',
        'available_balance',
        'frozen_balance',
        'snapshot_date',
    ];

    protected function casts(): array
    {
        return [
            'user_id' => 'integer',
            'available_balance' => 'decimal:2',
            'frozen_balance' => 'decimal:2',
            'snapshot_date' => 'date',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
