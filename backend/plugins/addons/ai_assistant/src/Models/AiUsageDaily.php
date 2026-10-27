<?php

declare(strict_types=1);

namespace TuraIDC\Plugins\Addons\AiAssistant\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * AI 每日用量：用于用户级与全站级额度判断（全站额度按天汇总本表求和）。
 */
class AiUsageDaily extends Model
{
    protected $table = 'ai_usage_daily';

    protected $fillable = [
        'day',
        'user_id',
        'messages',
        'prompt_tokens',
        'completion_tokens',
        'cost_cents',
    ];

    protected $casts = [
        'day' => 'date',
        'user_id' => 'integer',
        'messages' => 'integer',
        'prompt_tokens' => 'integer',
        'completion_tokens' => 'integer',
        'cost_cents' => 'integer',
    ];
}
