<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * AI 工具调用记录：既是审计凭据，也是写操作确认协议的载体。
 *
 * 状态流转：pending（等待用户确认）→ done / failed / rejected；
 * 只读工具不落 pending，直接以 done 记录，便于统计与排障。
 * idempotency_key 唯一，保证同一次确认重复提交只执行一次。
 */
class AiToolInvocation extends Model
{
    public const STATUS_PENDING = 'pending';

    public const STATUS_CONFIRMED = 'confirmed';

    public const STATUS_REJECTED = 'rejected';

    public const STATUS_FAILED = 'failed';

    public const STATUS_DONE = 'done';

    protected $table = 'ai_tool_invocations';

    protected $fillable = [
        'conversation_id',
        'message_id',
        'user_id',
        'tool_name',
        'risk',
        'arguments',
        'result',
        'status',
        'confirm_token_hash',
        'idempotency_key',
        'error_code',
        'duration_ms',
        'confirmed_at',
    ];

    protected $casts = [
        'conversation_id' => 'integer',
        'message_id' => 'integer',
        'user_id' => 'integer',
        'arguments' => 'array',
        'result' => 'array',
        'duration_ms' => 'integer',
        'confirmed_at' => 'datetime',
    ];

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(AiConversation::class, 'conversation_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function isPending(): bool
    {
        return $this->status === self::STATUS_PENDING;
    }
}
