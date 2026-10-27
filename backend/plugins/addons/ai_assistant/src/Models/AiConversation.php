<?php

declare(strict_types=1);

namespace TuraIDC\Plugins\Addons\AiAssistant\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * AI 会话：一个用户在一个入口下的一段连续对话。
 *
 * permission_mode 是每会话的权限档位：read_only 只允许只读工具，
 * confirm 写操作要用户点确认，auto_approve（beta）写操作直接执行。
 */
class AiConversation extends Model
{
    public const STATUS_ACTIVE = 1;

    public const STATUS_DELETED = 0;

    public const MODE_READ_ONLY = 'read_only';

    public const MODE_CONFIRM = 'confirm';

    public const MODE_AUTO_APPROVE = 'auto_approve';

    public const PERMISSION_MODES = [self::MODE_READ_ONLY, self::MODE_CONFIRM, self::MODE_AUTO_APPROVE];

    protected $table = 'ai_conversations';

    protected $fillable = [
        'user_id',
        'channel',
        'subject_type',
        'subject_id',
        'title',
        'permission_mode',
        'message_count',
        'prompt_tokens',
        'completion_tokens',
        'cost_cents',
        'last_message_at',
        'status',
    ];

    protected $casts = [
        'user_id' => 'integer',
        'subject_id' => 'integer',
        'message_count' => 'integer',
        'prompt_tokens' => 'integer',
        'completion_tokens' => 'integer',
        'cost_cents' => 'integer',
        'status' => 'integer',
        'last_message_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function messages(): HasMany
    {
        return $this->hasMany(AiMessage::class, 'conversation_id')->orderBy('id');
    }

    public function invocations(): HasMany
    {
        return $this->hasMany(AiToolInvocation::class, 'conversation_id')->orderBy('id');
    }
}
