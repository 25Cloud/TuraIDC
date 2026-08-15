<?php

declare(strict_types=1);

namespace TuraIDC\Plugins\Addons\ZjmfBridge\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AgentApplication extends Model
{
    protected $fillable = [
        'user_id', 'contact_name', 'contact_phone', 'contact_qq', 'company_name',
        'reason', 'status', 'api_key', 'admin_note',
    ];

    protected function casts(): array
    {
        return [];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
