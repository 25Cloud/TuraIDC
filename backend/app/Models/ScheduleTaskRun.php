<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ScheduleTaskRun extends Model
{
    public const STATUS_QUEUED = 'queued';

    public const STATUS_RUNNING = 'running';

    /**
     * 本次尝试失败但队列仍会继续重试，不能被新的触发请求视为可复用的终态。
     */
    public const STATUS_RETRYING = 'retrying';

    /**
     * 心跳任务尚未成功派发到队列，下一次同槽心跳应复用并重派这条记录。
     */
    public const STATUS_DISPATCH_FAILED = 'dispatch_failed';

    public const STATUS_SUCCESS = 'success';

    public const STATUS_FAILED = 'failed';

    public const STATUS_LABELS = [
        self::STATUS_QUEUED => '排队中',
        self::STATUS_RUNNING => '执行中',
        self::STATUS_RETRYING => '重试中',
        self::STATUS_DISPATCH_FAILED => '派发失败',
        self::STATUS_SUCCESS => '成功',
        self::STATUS_FAILED => '失败',
    ];

    public static function statusLabel(string $status): string
    {
        return self::STATUS_LABELS[$status] ?? $status;
    }

    protected $fillable = [
        'schedule_tick_id',
        'parent_run_id',
        'task_key',
        'task_name',
        'rule_description',
        'source',
        'queue',
        'status',
        'attempt',
        'duration_ms',
        'summary',
        'error_msg',
        'queued_at',
        'started_at',
        'finished_at',
        'manual_retry_at',
        'manual_retry_by',
    ];

    protected function casts(): array
    {
        return [
            'schedule_tick_id' => 'integer',
            'parent_run_id' => 'integer',
            'attempt' => 'integer',
            'duration_ms' => 'integer',
            'summary' => 'array',
            'queued_at' => 'immutable_datetime',
            'started_at' => 'immutable_datetime',
            'finished_at' => 'immutable_datetime',
            'manual_retry_at' => 'immutable_datetime',
            'manual_retry_by' => 'integer',
        ];
    }

    public function parentRun(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_run_id');
    }

    public function childRuns(): HasMany
    {
        return $this->hasMany(self::class, 'parent_run_id');
    }

    public function parent(): BelongsTo
    {
        return $this->parentRun();
    }

    public function children(): HasMany
    {
        return $this->childRuns();
    }
}
