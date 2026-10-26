<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * AI 消息：role 为 user / assistant / tool / system。
 *
 * tool_calls 以 JSON 文本保存本轮工具调用与结果摘要，供前端折叠展示与审计回放。
 */
class AiMessage extends Model
{
    public const ROLE_USER = 'user';

    public const ROLE_ASSISTANT = 'assistant';

    public const ROLE_TOOL = 'tool';

    public const ROLE_SYSTEM = 'system';

    protected $table = 'ai_messages';

    protected $fillable = [
        'conversation_id',
        'role',
        'content',
        'tool_calls',
        'tool_call_id',
        'prompt_tokens',
        'completion_tokens',
    ];

    protected $casts = [
        'conversation_id' => 'integer',
        'tool_calls' => 'array',
        'prompt_tokens' => 'integer',
        'completion_tokens' => 'integer',
    ];

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(AiConversation::class, 'conversation_id');
    }
}
