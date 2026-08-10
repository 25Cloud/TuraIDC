<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AutomationLog extends Model
{
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
        $log = static::query()->firstOrCreate(
            [
                'task_key' => trim($taskKey),
                'action' => trim($action),
                'object_type' => trim($objectType),
                'object_id' => $objectId,
                'rule_key' => trim($ruleKey),
            ],
            [
                'meta' => $meta,
                'executed_at' => null,
            ]
        );

        return $log->wasRecentlyCreated;
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
