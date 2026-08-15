<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

class AutomationLog extends Model
{
    /**
     * 崩溃残留认领窗口（秒）：认领后在该窗口内不允许再次认领，
     * 防止并发调用方重复执行；超时后允许下一次调度重新认领，实现崩溃自愈。
     */
    private const RETRY_CLAIM_TTL_SECONDS = 3600;

    protected $fillable = [
        'task_key',
        'action',
        'object_type',
        'object_id',
        'rule_key',
        'meta',
        'executed_at',
    ];

    protected function casts(): array
    {
        return [
            'meta' => 'array',
            'executed_at' => 'datetime',
        ];
    }

    public static function recordOnce(
        string $taskKey,
        string $action,
        string $objectType,
        int $objectId,
        string $ruleKey = '',
        array $meta = [],
    ): bool {
        $where = [
            'task_key' => trim($taskKey),
            'action' => trim($action),
            'object_type' => trim($objectType),
            'object_id' => $objectId,
            'rule_key' => trim($ruleKey),
        ];

        // 创建即写入认领标记：新建窗口（创建→markExecuted 之间）内的并发调用方
        // 会被 claimCrashResidual 的 TTL 窗口拦截，保证"仅一个调用方获得执行权"。
        $log = static::query()->firstOrCreate(
            $where,
            [
                'meta' => array_merge($meta, ['_retry_claimed_at' => now()->toDateTimeString()]),
                'executed_at' => null,
            ]
        );

        if ($log->wasRecentlyCreated) {
            return true;
        }

        // 崩溃残留自愈：executed_at 为空说明上次执行未标记完成（进程中断或异常退出）。
        // 通过 CAS 认领允许重试：并发下仅一个调用方获得执行权，认领超时后仍可再次重试。
        if ($log->executed_at === null) {
            return self::claimCrashResidual($where, $meta);
        }

        return false;
    }

    /**
     * 行锁内重读并 CAS 认领 executed_at IS NULL 的崩溃残留记录。
     *
     * @param  array<string, mixed>  $where
     * @param  array<string, mixed>  $meta
     */
    private static function claimCrashResidual(array $where, array $meta): bool
    {
        return DB::transaction(function () use ($where, $meta): bool {
            $locked = static::query()
                ->where($where)
                ->lockForUpdate()
                ->first();

            if (! $locked instanceof static || $locked->executed_at !== null) {
                return false;
            }

            $lockedMeta = is_array($locked->meta ?? null) ? $locked->meta : [];
            $claimedAt = trim((string) ($lockedMeta['_retry_claimed_at'] ?? ''));
            if ($claimedAt !== '') {
                $claimedTimestamp = strtotime($claimedAt);
                if ($claimedTimestamp !== false && $claimedTimestamp > time() - self::RETRY_CLAIM_TTL_SECONDS) {
                    return false;
                }
            }

            $lockedMeta = array_merge($lockedMeta, $meta, ['_retry_claimed_at' => now()->toDateTimeString()]);
            $locked->forceFill(['meta' => $lockedMeta])->save();

            return true;
        });
    }

    public static function hasRecord(
        string $taskKey,
        string $action,
        string $objectType,
        int $objectId,
        string $ruleKey = '',
    ): bool {
        return static::query()
            ->where('task_key', trim($taskKey))
            ->where('action', trim($action))
            ->where('object_type', trim($objectType))
            ->where('object_id', $objectId)
            ->where('rule_key', trim($ruleKey))
            ->exists();
    }

    public static function markExecuted(
        string $taskKey,
        string $action,
        string $objectType,
        int $objectId,
        string $ruleKey = '',
        array $meta = [],
    ): void {
        static::query()->updateOrCreate(
            [
                'task_key' => trim($taskKey),
                'action' => trim($action),
                'object_type' => trim($objectType),
                'object_id' => $objectId,
                'rule_key' => trim($ruleKey),
            ],
            [
                'meta' => $meta,
                'executed_at' => now(),
            ]
        );
    }

    public static function forgetRecord(
        string $taskKey,
        string $action,
        string $objectType,
        int $objectId,
        string $ruleKey = '',
    ): void {
        static::query()
            ->where('task_key', trim($taskKey))
            ->where('action', trim($action))
            ->where('object_type', trim($objectType))
            ->where('object_id', $objectId)
            ->where('rule_key', trim($ruleKey))
            ->delete();
    }
}
